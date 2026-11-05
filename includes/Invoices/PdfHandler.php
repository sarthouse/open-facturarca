<?php
namespace WcArca\Invoices;

use WcArca\Services\TaxResolver;
use WcArca\Services\CompanyManager;
use WcArca\Services\DompdfRenderer;

defined('ABSPATH') || exit;

class PdfHandler {
    public static function init() {
        add_action('woocommerce_admin_order_actions_end', [__CLASS__, 'add_admin_order_pdf_button']);
        add_action('woocommerce_order_details_after_order_table', [__CLASS__, 'add_customer_download_button']);
        add_action('init', [__CLASS__, 'handle_pdf_download']);
    }

    public static function add_admin_order_pdf_button($order) {
        if ($order->get_meta('_arca_cae')) {
            $url = self::get_download_url($order->get_id());
            echo '<a class="button wc-action-button wc-action-button-arca-invoice" href="' . esc_url($url) . '" target="_blank" title="' . esc_attr__('Ver Factura Electrónica (PDF)', 'open-facturarca') . '"></a>';
        }
    }

    public static function add_customer_download_button($order) {
        if ($order->get_meta('_arca_cae')) {
            $url = self::get_download_url($order->get_id());
            ?>
            <div style="margin: 20px 0; padding: 15px; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 6px;">
                <h3 style="margin-top: 0; font-size: 16px;">Factura Electrónica</h3>
                <p style="margin-bottom: 12px; font-size: 14px;">Su comprobante fiscal ha sido emitido y autorizado por ARCA/AFIP.</p>
                <a href="<?php echo esc_url($url); ?>" class="button" target="_blank" style="display: inline-block;">
                    Descargar Factura Electrónica (PDF)
                </a>
            </div>
            <?php
        }
    }

    public static function add_email_invoice_button($order, $sent_to_admin, $plain_text, $email) {
        $cae = $order->get_meta('_arca_cae');
        if (!$cae) return;

        $url = self::get_download_url($order->get_id());
        $cbte_tipo = (int) $order->get_meta('_arca_cbte_tipo');
        $label = TaxResolver::get_cbte_label($cbte_tipo);
        $pto_vta = sprintf('%04d', $order->get_meta('_arca_pto_vta'));
        $cbte_nro = sprintf('%08d', $order->get_meta('_arca_cbte_nro'));

        if ($plain_text) {
            echo "\n================================================\n";
            echo "FACTURA ELECTRÓNICA: {$label} N° {$pto_vta}-{$cbte_nro}\n";
            echo "CAE: {$cae}\n";
            echo "Descargue su factura aquí: {$url}\n";
            echo "================================================\n\n";
        } else {
            ?>
            <div style="margin: 25px 0; padding: 15px; border: 1px solid #e2e4e7; border-left: 4px solid #2e7d32; background-color: #fcfcfc; border-radius: 4px;">
                <h3 style="color: #2e7d32; margin-top: 0; font-size: 16px; margin-bottom: 8px;">
                    Factura Electrónica Autorizada
                </h3>
                <p style="margin: 0 0 10px; font-size: 14px; color: #555;">
                    Se ha emitido su <strong><?php echo esc_html($label); ?> N° <?php echo esc_html("{$pto_vta}-{$cbte_nro}"); ?></strong> con CAE N° <strong><?php echo esc_html($cae); ?></strong>.
                </p>
                <p style="margin: 0;">
                    <a href="<?php echo esc_url($url); ?>" target="_blank" style="background-color: #2271b1; color: #ffffff; padding: 10px 18px; text-decoration: none; border-radius: 4px; font-weight: bold; display: inline-block; font-size: 14px;">
                        Descargar Factura en PDF
                    </a>
                </p>
            </div>
            <?php
        }
    }

    public static function get_download_url($order_id) {
        $order = is_numeric($order_id) ? wc_get_order($order_id) : $order_id;
        $id = $order ? $order->get_id() : (int) $order_id;
        $order_key = $order ? $order->get_order_key() : '';

        $nonce = wp_create_nonce('wc_arca_view_invoice_' . $id);
        $args = [
            'wc_arca_action' => 'download_invoice',
            'order_id'       => $id,
            'nonce'          => $nonce,
        ];
        if ($order_key) {
            $args['order_key'] = $order_key;
        }

        return add_query_arg($args, home_url('/'));
    }

    public static function handle_pdf_download() {
        if (!isset($_GET['wc_arca_action']) || $_GET['wc_arca_action'] !== 'download_invoice') {
            return;
        }

        $order_id  = absint($_GET['order_id'] ?? 0);
        $nonce     = sanitize_text_field($_GET['nonce'] ?? '');
        $order_key = sanitize_text_field($_GET['order_key'] ?? '');

        if (!$order_id || !wp_verify_nonce($nonce, 'wc_arca_view_invoice_' . $order_id)) {
            wp_die(__('Enlace de descarga inválido o expirado.', 'wc-factura-arca'), 403);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die(__('Pedido no encontrado.', 'wc-factura-arca'), 404);
        }

        $current_user_id = get_current_user_id();
        $is_admin = current_user_can('manage_woocommerce');
        $is_owner = ($current_user_id > 0 && $current_user_id === $order->get_customer_id());
        $is_guest_with_key = (!empty($order_key) && hash_equals($order->get_order_key(), $order_key));

        if (!$is_admin && !$is_owner && !$is_guest_with_key) {
            wp_die(__('No tienes permisos para descargar este comprobante.', 'open-facturarca'), 403);
        }

        $pdf_content = self::build_invoice_pdf($order);

        $cbte_tipo = (int)$order->get_meta('_arca_cbte_tipo');
        $letra = ($cbte_tipo === 1 || $cbte_tipo === 2 || $cbte_tipo === 3) ? 'A' : (($cbte_tipo === 6 || $cbte_tipo === 7 || $cbte_tipo === 8) ? 'B' : 'C');
        $pto_vta = sprintf('%04d', $order->get_meta('_arca_pto_vta'));
        $cbte_nro = sprintf('%08d', $order->get_meta('_arca_cbte_nro'));
        $filename = "Factura_{$letra}_{$pto_vta}-{$cbte_nro}.pdf";

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf_content));
        echo $pdf_content;
        exit;
    }

    /**
     * Generador de PDF nativo binario real usando plantillas modulares
     */
    public static function build_invoice_pdf($order) {
        $copies_mode = get_option('wc_arca_invoice_copies', 'original');
        $copies = ['ORIGINAL'];
        if ($copies_mode === 'duplicado') {
            $copies = ['ORIGINAL', 'DUPLICADO'];
        } elseif ($copies_mode === 'triplicado') {
            $copies = ['ORIGINAL', 'DUPLICADO', 'TRIPLICADO'];
        }

        $cae       = (string) $order->get_meta('_arca_cae');
        $cae_vto   = (string) $order->get_meta('_arca_cae_vto');
        $pto_vta   = sprintf('%04d', (int) $order->get_meta('_arca_pto_vta'));
        $cbte_nro  = sprintf('%08d', (int) $order->get_meta('_arca_cbte_nro'));
        $cbte_tipo = (int) $order->get_meta('_arca_cbte_tipo');
        $cbte_fch  = (string) $order->get_meta('_arca_cbte_fch');

        $company_id    = $order->get_meta('_arca_company_id');
        $company       = $company_id ? CompanyManager::get_company($company_id) : CompanyManager::get_default_company();
        $cuit_emisor   = $order->get_meta('_arca_company_cuit') ?: ($company['cuit'] ?? '');
        $razon_social  = $company['name'] ?? get_bloginfo('name');
        $nombre_emisor = !empty($company['trade_name']) ? $company['trade_name'] : $razon_social;
        $cond_emisor   = $company['tax_condition'] ?? 'monotributo';
        $iibb_emisor   = $company['iibb'] ?? ($cuit_emisor ?: '-');
        $fch_inicio    = !empty($company['activity_init']) ? date('d/m/Y', strtotime($company['activity_init'])) : '-';

        $voucher_title = TaxResolver::get_cbte_label($cbte_tipo);
        $letra = ($cbte_tipo === 1 || $cbte_tipo === 2 || $cbte_tipo === 3) ? 'A' : (($cbte_tipo === 6 || $cbte_tipo === 7 || $cbte_tipo === 8) ? 'B' : 'C');

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
        $total_tax = (float) $order->get_total_tax();
        $neto = $total_tax > 0 ? round($total - $total_tax, 2) : round($total / 1.21, 2);
        $iva_total = round($total - $neto, 2);
        $iva_contenido = $iva_total;

        $fch_qr = strlen($cbte_fch) === 8 ? substr($cbte_fch, 0, 4) . '-' . substr($cbte_fch, 4, 2) . '-' . substr($cbte_fch, 6, 2) : date('Y-m-d');
        $qr_data = [
            'ver'       => 1,
            'fecha'     => $fch_qr,
            'cuit'      => (float) $cuit_emisor,
            'ptoVta'    => (int) $pto_vta,
            'tipoCmp'   => $cbte_tipo,
            'nroCmp'    => (int) $cbte_nro,
            'importe'   => $total,
            'moneda'    => 'PES',
            'ctz'       => 1,
            'tipoDocRec'=> (int) ($order->get_meta('_billing_doc_type') ?: 96),
            'nroDocRec' => (float) (preg_replace('/[^0-9]/', '', $clean_doc) ?: 0),
            'tipoCodAut'=> 'E',
            'codAut'    => (float) $cae,
        ];
        $qr_encoded = base64_encode(json_encode($qr_data));
        $qr_url = "https://www.afip.gob.ar/fe/qr/?p=" . $qr_encoded;
        $qr_image_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($qr_url);

        $data = [
            'order'            => $order,
            'company'          => $company,
            'pto_vta'          => $pto_vta,
            'cbte_nro'         => $cbte_nro,
            'cbte_tipo'        => $cbte_tipo,
            'cbte_fch_fmt'     => !empty($cbte_fch) ? date('d/m/Y', strtotime($cbte_fch)) : date('d/m/Y'),
            'voucher_title'    => $voucher_title,
            'letra'            => $letra,
            'cuit_emisor'      => $cuit_emisor,
            'razon_social'     => $razon_social,
            'nombre_emisor'    => $nombre_emisor,
            'cond_emisor'      => $cond_emisor,
            'iibb_emisor'      => $iibb_emisor,
            'fch_inicio'       => $fch_inicio,
            'clean_doc'        => $clean_doc,
            'customer_address' => $customer_address,
            'cae'              => $cae,
            'cae_vto_fmt'      => !empty($cae_vto) ? date('d/m/Y', strtotime($cae_vto)) : '-',
            'subtotal'         => $subtotal,
            'neto'             => $neto,
            'iva_total'        => $iva_total,
            'iva_contenido'    => $iva_contenido,
            'total'            => $total,
            'qr_image_url'     => $qr_image_url,
        ];

        $template_file = OPEN_FACTURARCA_DIR . 'templates/invoice-c.php';
        if ($letra === 'A') {
            $template_file = OPEN_FACTURARCA_DIR . 'templates/invoice-a.php';
        } elseif ($letra === 'B') {
            $template_file = OPEN_FACTURARCA_DIR . 'templates/invoice-b.php';
        }

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
