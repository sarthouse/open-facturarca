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
        echo '<a class="button wc-action-button wc-action-button-arca-sales" href="' . esc_url($url) . '" target="_blank" title="' . esc_attr__('Ver Nota de Pedido / Venta (PDF)', 'open-facturarca') . '"></a>';
    }

    public static function add_customer_sales_button($order) {
        // Si aún no está facturado, permitir al cliente descargar su Nota de Pedido
        if (!$order->get_meta('_arca_cae')) {
            $url = self::get_download_url($order->get_id());
            ?>
            <div style="margin: 20px 0; padding: 15px; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 6px;">
                <h3 style="margin-top: 0; font-size: 16px;">Comprobante de Pedido / Venta</h3>
                <p style="margin-bottom: 12px; font-size: 14px;">Puede descargar el detalle comercial de su compra en PDF.</p>
                <a href="<?php echo esc_url($url); ?>" class="button" target="_blank" style="display: inline-block;">
                    Descargar Nota de Pedido (PDF)
                </a>
            </div>
            <?php
        }
    }

    public static function get_download_url($order_id) {
        $order = is_numeric($order_id) ? wc_get_order($order_id) : $order_id;
        $id = $order ? $order->get_id() : (int) $order_id;
        $order_key = $order ? $order->get_order_key() : '';

        $nonce = wp_create_nonce('wc_arca_view_sales_' . $id);
        $args = [
            'wc_arca_action' => 'download_sales_order',
            'order_id'       => $id,
            'nonce'          => $nonce,
        ];
        if ($order_key) {
            $args['order_key'] = $order_key;
        }

        return add_query_arg($args, home_url('/'));
    }

    public static function handle_sales_order_download() {
        if (!isset($_GET['wc_arca_action']) || $_GET['wc_arca_action'] !== 'download_sales_order') {
            return;
        }

        $order_id  = absint($_GET['order_id'] ?? 0);
        $nonce     = sanitize_text_field($_GET['nonce'] ?? '');
        $order_key = sanitize_text_field($_GET['order_key'] ?? '');

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
        $is_guest_with_key = (!empty($order_key) && hash_equals($order->get_order_key(), $order_key));

        if (!$is_admin && !$is_owner && !$is_guest_with_key) {
            wp_die(__('No tienes permisos para ver esta nota de pedido.', 'open-facturarca'), 403);
        }

        $pdf_content = self::build_sales_order_pdf($order);
        $pto_vta = sprintf('%04d', (int) ($order->get_meta('_arca_pto_vta') ?: ($company['pto_vta'] ?? 1)));
        $filename = "Nota_Pedido_" . $pto_vta . "-" . sprintf('%08d', $order_id) . ".pdf";

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
        $emisor_name = !empty($company['trade_name']) ? $company['trade_name'] : ($company['name'] ?? get_bloginfo('name'));
        $razon_social = $company['name'] ?? '';
        $emisor_cuit = $company['cuit'] ?? '';

        $order_id = $order->get_id();
        $date     = $order->get_date_created() ? $order->get_date_created()->date('d/m/Y') : date('d/m/Y');
        $pto_vta  = sprintf('%04d', (int) ($order->get_meta('_arca_pto_vta') ?: ($company['pto_vta'] ?? 1)));
        $cbte_nro = sprintf('%08d', $order_id);

        $pdf = new SimplePdf();

        foreach ($copies as $copy_name) {
            $pdf->add_page();

            // Marco exterior
            $pdf->rect(20, 20, 555, 800);

            // Letra X Badge (Central)
            $pdf->rect(275, 20, 45, 40, true, true, [245, 245, 245], [0, 0, 0]);
            $pdf->text(291, 46, 'X', 20, true);
            $pdf->text(280, 56, 'COD. 99', 8, true);

            // Leyenda obligatoria: exclusivamente debajo del recuadro central de la letra
            $pdf->rect(215, 62, 165, 26, true, true, [240, 240, 240], [0, 0, 0]);
            $pdf->text(225, 73, 'DOCUMENTO NO VALIDO', 8, true);
            $pdf->text(236, 84, 'COMO FACTURA', 8, true);

            // Indicador de Copia (esquina superior derecha)
            $pdf->text(515, 30, $copy_name, 8, true);

            // Datos Emisor (Izquierda)
            $logo_url = $company['logo_url'] ?? '';
            $y_emisor = 34;

            if (!empty($logo_url)) {
                $has_logo = $pdf->image($logo_url, 30, 26, 120, 38);
                if ($has_logo) {
                    $y_emisor = 68;
                } else {
                    $pdf->text(30, $y_emisor, substr($emisor_name, 0, 26), 12, true);
                    $y_emisor += 13;
                }
            } else {
                $pdf->text(30, $y_emisor, substr($emisor_name, 0, 26), 12, true);
                $y_emisor += 13;
            }

            if (!empty($company['trade_name']) && !empty($razon_social) && $company['trade_name'] !== $razon_social && empty($logo_url)) {
                $pdf->text(30, $y_emisor, 'Razon Social: ' . substr($razon_social, 0, 28), 8, false);
                $y_emisor += 10;
            }
            if ($emisor_cuit) {
                $pdf->text(30, $y_emisor, 'CUIT: ' . $emisor_cuit, 8, false);
                $y_emisor += 10;
            }
            $pdf->text(30, $y_emisor, 'Cond. IVA: ' . ucfirst($company['tax_condition'] ?? 'monotributo'), 8, false);
            $y_emisor += 10;

            $dir_emisor = trim(($company['address'] ?? '') . ' ' . ($company['province'] ?? ''));
            if ($dir_emisor) {
                $pdf->text(30, $y_emisor, 'Dom: ' . substr($dir_emisor, 0, 32), 7, false);
                $y_emisor += 9;
            }
            if (!empty($company['phone'])) {
                $pdf->text(30, $y_emisor, 'Tel: ' . substr($company['phone'], 0, 22), 7, false);
            }

            // Datos Comprobante (Derecha)
            $pdf->text(390, 38, 'NOTA DE PEDIDO / VENTA', 11, true);
            $pdf->text(390, 52, sprintf('Pto. Vta: %s   Comp: %s', $pto_vta, $cbte_nro), 8, true);
            $pdf->text(390, 64, 'Fecha de Emision: ' . $date, 8, false);

            // Línea separadora encabezado
            $pdf->line(20, 95, 575, 95);

            // Datos Cliente y Facturación / Entrega
            $cliente_nom = substr($order->get_formatted_billing_full_name(), 0, 35);
            $doc_nro     = $order->get_meta('_billing_dni') ?: ($order->get_meta('billing_dni') ?: '-');
            $cond_cli    = ucfirst(str_replace('_', ' ', $order->get_meta('_billing_tax_condition') ?: 'Consumidor Final'));
            $tel_cli     = $order->get_billing_phone() ?: '-';

            // Domicilio, provincia, CP
            $dir_cli     = trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2());
            $ciudad_cli  = $order->get_billing_city();
            $prov_cli    = $order->get_billing_state();
            $cp_cli      = $order->get_billing_postcode();

            $loc_parts   = array_filter([$ciudad_cli, $prov_cli, $cp_cli ? "CP $cp_cli" : '']);
            $loc_str     = implode(', ', $loc_parts);

            $pdf->text(30, 108, 'Cliente: ' . $cliente_nom, 9, true);
            $pdf->text(30, 120, 'Documento: ' . $doc_nro . '   Cond. IVA: ' . $cond_cli, 8, false);
            if ($dir_cli || $loc_str) {
                $pdf->text(30, 131, 'Domicilio: ' . substr(trim($dir_cli . ' - ' . $loc_str), 0, 50), 8, false);
            }
            $pdf->text(30, 142, 'Telefono: ' . $tel_cli, 8, false);

            $pdf->text(340, 108, 'Pedido WooCommerce: #' . $order_id, 9, true);
            $pdf->text(340, 120, 'Estado: ' . wc_get_order_status_name($order->get_status()), 8, false);
            $pdf->text(340, 131, 'Metodo de Pago: ' . substr($order->get_payment_method_title(), 0, 30), 8, false);

            // Encabezado Tabla
            $pdf->line(20, 154, 575, 154);
            $pdf->rect(20, 154, 555, 18, false, true);
            $pdf->text(30, 166, 'Descripcion del Producto', 9, true);
            $pdf->text(360, 166, 'Cant.', 9, true);
            $pdf->text(420, 166, 'Precio Unit.', 9, true);
            $pdf->text(500, 166, 'Subtotal', 9, true);

            $y = 186;
            foreach ($order->get_items() as $item) {
                if ($y > 670) break;
                $pdf->text(30, $y, substr($item->get_name(), 0, 48), 9, false);
                $pdf->text(365, $y, (string)$item->get_quantity(), 9, false);
                $pdf->text(420, $y, '$' . number_format($order->get_item_subtotal($item), 2, ',', '.'), 9, false);
                $pdf->text(500, $y, '$' . number_format($item->get_total(), 2, ',', '.'), 9, false);
                $pdf->line(20, $y + 5, 575, $y + 5, 0.5);
                $y += 18;
            }

            // Descuentos (Cupones)
            $discount_total = (float) $order->get_total_discount();
            if ($discount_total > 0 && $y <= 680) {
                $coupon_codes = implode(', ', $order->get_coupon_codes());
                $desc_label = 'Descuento' . ($coupon_codes ? " ({$coupon_codes})" : '');
                $pdf->text(30, $y, substr($desc_label, 0, 48), 9, false);
                $pdf->text(365, $y, '1', 9, false);
                $pdf->text(420, $y, '-$' . number_format($discount_total, 2, ',', '.'), 9, false);
                $pdf->text(500, $y, '-$' . number_format($discount_total, 2, ',', '.'), 9, false);
                $pdf->line(20, $y + 5, 575, $y + 5, 0.5);
                $y += 18;
            }

            // Recargos / Tasas / Gastos (Fees)
            foreach ($order->get_fees() as $fee) {
                if ($y > 690) break;
                $fee_total = (float) $fee->get_total();
                $pdf->text(30, $y, substr($fee->get_name(), 0, 48), 9, false);
                $pdf->text(365, $y, '1', 9, false);
                $pdf->text(420, $y, '$' . number_format($fee_total, 2, ',', '.'), 9, false);
                $pdf->text(500, $y, '$' . number_format($fee_total, 2, ',', '.'), 9, false);
                $pdf->line(20, $y + 5, 575, $y + 5, 0.5);
                $y += 18;
            }

            // Costo de Envío
            $shipping_total = (float) $order->get_shipping_total();
            if ($shipping_total > 0 && $y <= 700) {
                $ship_name = $order->get_shipping_method() ?: 'Envio a domicilio';
                $pdf->text(30, $y, substr($ship_name, 0, 48), 9, false);
                $pdf->text(365, $y, '1', 9, false);
                $pdf->text(420, $y, '$' . number_format($shipping_total, 2, ',', '.'), 9, false);
                $pdf->text(500, $y, '$' . number_format($shipping_total, 2, ',', '.'), 9, false);
                $pdf->line(20, $y + 5, 575, $y + 5, 0.5);
                $y += 18;
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

