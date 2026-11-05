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
        $company_id  = $order->get_meta('_arca_company_id');
        $company     = $company_id ? CompanyManager::get_company($company_id) : CompanyManager::get_default_company();
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

        $company_id   = $order->get_meta('_arca_company_id');
        $company      = $company_id ? CompanyManager::get_company($company_id) : CompanyManager::get_default_company();
        $pto_vta      = sprintf('%04d', (int) ($order->get_meta('_arca_pto_vta') ?: ($company['pto_vta'] ?? 1)));
        $cbte_nro     = sprintf('%08d', $order->get_id());
        $date         = $order->get_date_created() ? $order->get_date_created()->date('d/m/Y') : date('d/m/Y');

        $clean_doc = $order->get_meta('_billing_dni') ?: ($order->get_meta('_billing_doc_number') ?: ($order->get_meta('billing_dni') ?: '-'));
        $customer_address_parts = array_filter([
            $order->get_billing_address_1(),
            $order->get_billing_city(),
            $order->get_billing_state(),
            $order->get_billing_postcode()
        ]);
        $customer_address = !empty($customer_address_parts) ? implode(', ', $customer_address_parts) : '-';

        $total = (float) $order->get_total();
        $subtotal = (float) $order->get_subtotal();

        $data = [
            'order'            => $order,
            'company'          => $company,
            'pto_vta'          => $pto_vta,
            'cbte_nro'         => $cbte_nro,
            'date'             => $date,
            'clean_doc'        => $clean_doc,
            'customer_address' => $customer_address,
            'subtotal'         => $subtotal,
            'total'            => $total,
        ];

        $template_file = OPEN_FACTURARCA_DIR . 'templates/sales-order.php';
        $pdf = new SimplePdf();

        foreach ($copies as $copy_name) {
            $pdf->add_page();
            if (file_exists($template_file)) {
                include $template_file;
            }
        }

        return $pdf->output();
    }
}
