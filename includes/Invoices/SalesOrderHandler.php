<?php
namespace WcArca\Invoices;

use WcArca\Services\CompanyManager;
use WcArca\Services\SimplePdf;

defined('ABSPATH') || exit;

class SalesOrderHandler {
    public static function init() {
        add_action('woocommerce_admin_order_actions_end', [__CLASS__, 'add_admin_order_sales_button']);
        add_action('woocommerce_order_details_after_order_table', [__CLASS__, 'add_customer_sales_button']);
        add_action('init', [__CLASS__, 'handle_sales_order_download']);
    }

    public static function add_admin_order_sales_button($order) {
        $url = self::get_download_url($order->get_id());
        echo '<a class="button wc-action-button" href="' . esc_url($url) . '" target="_blank" title="' . esc_attr__('Ver Nota de Pedido / Venta (PDF)', 'open-facturarca') . '">📋 Nota de Pedido</a>';
    }

    public static function add_customer_sales_button($order) {
        // Si aún no está facturado, permitir al cliente descargar su Nota de Pedido
        if (!$order->get_meta('_arca_cae')) {
            $url = self::get_download_url($order->get_id());
            ?>
            <div style="margin: 20px 0; padding: 15px; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 6px;">
                <h3 style="margin-top: 0; font-size: 16px;">📋 Comprobante de Pedido / Venta</h3>
                <p style="margin-bottom: 12px; font-size: 14px;">Puede descargar el detalle comercial de su compra en PDF.</p>
                <a href="<?php echo esc_url($url); ?>" class="button" target="_blank" style="display: inline-block;">
                    Descargar Nota de Pedido (PDF)
                </a>
            </div>
            <?php
        }
    }

    public static function get_download_url($order_id) {
        $nonce = wp_create_nonce('wc_arca_view_sales_' . $order_id);
        return add_query_arg([
            'wc_arca_action' => 'download_sales_order',
            'order_id'       => $order_id,
            'nonce'          => $nonce,
        ], home_url('/'));
    }

    public static function handle_sales_order_download() {
        if (!isset($_GET['wc_arca_action']) || $_GET['wc_arca_action'] !== 'download_sales_order') {
            return;
        }

        $order_id = absint($_GET['order_id'] ?? 0);
        $nonce    = sanitize_text_field($_GET['nonce'] ?? '');

        if (!$order_id || !wp_verify_nonce($nonce, 'wc_arca_view_sales_' . $order_id)) {
            wp_die(__('Enlace de descarga inválido o expirado.', 'open-facturarca'), 403);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die(__('Pedido no encontrado.', 'open-facturarca'), 404);
        }

        $current_user_id = get_current_user_id();
        $is_admin = current_user_can('manage_woocommerce');
        $is_owner = ($current_user_id > 0 && $current_user_id === $order->get_customer_id());

        if (!$is_admin && !$is_owner) {
            wp_die(__('No tienes permisos para ver esta nota de pedido.', 'open-facturarca'), 403);
        }

        $pdf_content = self::build_sales_order_pdf($order);
        $filename = "Nota_Pedido_" . sprintf('%08d', $order_id) . ".pdf";

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf_content));
        echo $pdf_content;
        exit;
    }

    public static function build_sales_order_pdf($order) {
        $copies_mode = get_option('wc_arca_sales_order_copies', 'original');
        $copies = ['ORIGINAL'];
        if ($copies_mode === 'duplicado') {
            $copies = ['ORIGINAL', 'DUPLICADO'];
        }

        $company_id  = $order->get_meta('_arca_company_id');
        $company     = $company_id ? CompanyManager::get_company($company_id) : CompanyManager::get_default_company();
        $emisor_name = $company['name'] ?? get_bloginfo('name');
        $emisor_cuit = $company['cuit'] ?? '';

        $order_id = $order->get_id();
        $date     = $order->get_date_created() ? $order->get_date_created()->date('d/m/Y') : date('d/m/Y');

        $pdf = new SimplePdf();

        foreach ($copies as $copy_name) {
            $pdf->add_page();

            // Marco exterior
            $pdf->rect(20, 20, 555, 800);

            // Letra X Badge
            $pdf->rect(275, 20, 45, 45, true, true);
            $pdf->text(290, 52, 'X', 24, true);
            $pdf->text(280, 62, 'COD. 99', 8, true);

            // Indicador de Copia
            $pdf->text(500, 32, $copy_name, 9, true);

            // Datos Emisor (Izquierda)
            $pdf->text(30, 40, substr($emisor_name, 0, 35), 14, true);
            if ($emisor_cuit) {
                $pdf->text(30, 55, 'CUIT: ' . $emisor_cuit, 10, false);
            }
            $pdf->text(30, 68, 'Condicion IVA: ' . ucfirst($company['tax_condition'] ?? 'monotributo'), 10, false);

            // Datos Comprobante (Derecha)
            $pdf->text(340, 40, 'NOTA DE PEDIDO / VENTA', 13, true);
            $pdf->text(340, 55, 'Numero: NV-' . sprintf('%08d', $order_id), 10, true);
            $pdf->text(340, 68, 'Fecha: ' . $date, 10, false);

            // Banner Legal Obligatorio
            $pdf->rect(20, 75, 555, 16, true, true);
            $pdf->text(180, 87, 'DOCUMENTO NO VALIDO COMO FACTURA', 10, true);

            // Datos Cliente
            $cliente_nom = substr($order->get_formatted_billing_full_name(), 0, 40);
            $doc_nro     = $order->get_meta('_billing_dni') ?: ($order->get_meta('billing_dni') ?: '-');
            $cond_cli    = ucfirst(str_replace('_', ' ', $order->get_meta('_billing_tax_condition') ?: 'Consumidor Final'));

            $pdf->text(30, 105, 'Cliente: ' . $cliente_nom, 10, true);
            $pdf->text(30, 120, 'Documento: ' . $doc_nro, 10, false);
            $pdf->text(30, 135, 'Condicion IVA: ' . $cond_cli, 10, false);

            $pdf->text(340, 105, 'Pedido WooCommerce: #' . $order_id, 10, true);
            $pdf->text(340, 120, 'Estado del Pedido: ' . wc_get_order_status_name($order->get_status()), 10, false);
            $pdf->text(340, 135, 'Metodo de Pago: ' . substr($order->get_payment_method_title(), 0, 30), 10, false);

            // Encabezado Tabla
            $pdf->line(20, 150, 575, 150);
            $pdf->rect(20, 150, 555, 20, false, true);
            $pdf->text(30, 164, 'Descripcion del Producto', 9, true);
            $pdf->text(360, 164, 'Cant.', 9, true);
            $pdf->text(420, 164, 'Precio Unit.', 9, true);
            $pdf->text(500, 164, 'Subtotal', 9, true);

            $y = 185;
            foreach ($order->get_items() as $item) {
                if ($y > 700) break;
                $pdf->text(30, $y, substr($item->get_name(), 0, 48), 9, false);
                $pdf->text(365, $y, (string)$item->get_quantity(), 9, false);
                $pdf->text(420, $y, '$' . number_format($order->get_item_subtotal($item), 2, ',', '.'), 9, false);
                $pdf->text(500, $y, '$' . number_format($item->get_total(), 2, ',', '.'), 9, false);
                $pdf->line(20, $y + 5, 575, $y + 5, 0.5);
                $y += 20;
            }

            // Resumen de Totales
            $pdf->rect(380, 720, 195, 30);
            $pdf->text(390, 740, 'TOTAL: $' . number_format($order->get_total(), 2, ',', '.'), 14, true);

            // Pie informativo
            $pdf->line(20, 765, 575, 765);
            $pdf->text(30, 785, 'Comprobante emitido a efectos informativos y de control comercial.', 9, false);
            $pdf->text(30, 798, 'No reemplaza a la factura fiscal emitida ante ARCA / AFIP.', 8, false);
        }

        return $pdf->output();
    }
}

