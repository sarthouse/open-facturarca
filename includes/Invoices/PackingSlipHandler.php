<?php
namespace WcArca\Invoices;

use WcArca\Services\CompanyManager;
use WcArca\Services\DompdfRenderer;

defined('ABSPATH') || exit;

class PackingSlipHandler {
    public static function init() {
        add_action('woocommerce_admin_order_actions_end', [__CLASS__, 'add_admin_order_slip_button']);
        add_action('init', [__CLASS__, 'handle_packing_slip_download']);
    }

    public static function add_admin_order_slip_button($order) {
        $url = self::get_download_url($order->get_id());
        echo '<a class="button wc-action-button wc-action-button-arca-slip" href="' . esc_url($url) . '" target="_blank" title="' . esc_attr__('Ver Remito de Entrega (PDF)', 'open-facturarca') . '"></a>';
    }

    public static function get_download_url($order_id) {
        $nonce = wp_create_nonce('wc_arca_view_slip_' . $order_id);
        return add_query_arg([
            'wc_arca_action' => 'download_slip',
            'order_id'       => $order_id,
            'nonce'          => $nonce,
        ], home_url('/'));
    }

    public static function handle_packing_slip_download() {
        if (!isset($_GET['wc_arca_action']) || $_GET['wc_arca_action'] !== 'download_slip') {
            return;
        }

        $order_id = absint($_GET['order_id'] ?? 0);
        $nonce    = sanitize_text_field($_GET['nonce'] ?? '');

        if (!$order_id || !wp_verify_nonce($nonce, 'wc_arca_view_slip_' . $order_id)) {
            wp_die(__('Enlace de descarga inválido o expirado.', 'open-facturarca'), 403);
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('No tienes permisos para ver este remito.', 'open-facturarca'), 403);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die(__('Pedido no encontrado.', 'open-facturarca'), 404);
        }

        $pdf_content = self::build_packing_slip_pdf($order);
        $filename = "Remito_X-" . sprintf('%08d', $order_id) . ".pdf";

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf_content));
        echo $pdf_content;
        exit;
    }

    /**
     * Construye el Remito en formato PDF real usando plantillas modulares
     */
    public static function build_packing_slip_pdf($order) {
        $copies_mode = get_option('wc_arca_slip_copies', 'duplicado');
        $copies = ['ORIGINAL'];
        if ($copies_mode === 'duplicado') {
            $copies = ['ORIGINAL', 'DUPLICADO'];
        } elseif ($copies_mode === 'triplicado') {
            $copies = ['ORIGINAL', 'DUPLICADO', 'TRIPLICADO'];
        }

        $company_id   = $order->get_meta('_arca_company_id');
        $company      = $company_id ? CompanyManager::get_company($company_id) : CompanyManager::get_default_company();
        $pto_vta      = sprintf('%04d', (int) ($order->get_meta('_arca_pto_vta') ?: ($company['pto_vta'] ?? 1)));
        $cbte_nro     = sprintf('%08d', $order->get_id());
        $date         = $order->get_date_created() ? $order->get_date_created()->date('d/m/Y') : date('d/m/Y');

        $clean_doc = $order->get_meta('_billing_dni') ?: ($order->get_meta('_billing_doc_number') ?: ($order->get_meta('billing_dni') ?: '-'));
        $customer_address_parts = array_filter([
            $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
            $order->get_shipping_city() ?: $order->get_billing_city(),
            $order->get_shipping_state() ?: $order->get_billing_state(),
            $order->get_shipping_postcode() ?: $order->get_billing_postcode()
        ]);
        $customer_address = !empty($customer_address_parts) ? implode(', ', $customer_address_parts) : '-';

        $data = [
            'order'            => $order,
            'company'          => $company,
            'pto_vta'          => $pto_vta,
            'cbte_nro'         => $cbte_nro,
            'date'             => $date,
            'clean_doc'        => $clean_doc,
            'customer_address' => $customer_address,
        ];

        $template_file = OPEN_FACTURARCA_DIR . 'templates/packing-slip.php';
        $full_html = '';

        foreach ($copies as $copy_name) {
            ob_start();
            if (file_exists($template_file)) {
                include $template_file;
            }
            $full_html .= ob_get_clean();
        }

        return DompdfRenderer::render_html_to_pdf($full_html);
    }
}
