<?php
namespace WcArca\Admin;

use WcArca\Orders\InvoiceTrigger;
use WcArca\Services\TaxResolver;
use WcArca\Services\CompanyManager;

defined('ABSPATH') || exit;

class OrderMetabox {
    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'register_metabox']);
        add_action('admin_post_wc_arca_manual_issue', [__CLASS__, 'handle_manual_issue']);
        add_action('admin_post_wc_arca_manual_credit_note', [__CLASS__, 'handle_manual_credit_note']);
        add_action('admin_post_wc_arca_send_invoice_email', [__CLASS__, 'handle_send_invoice_email']);
        add_action('admin_post_wc_arca_send_slip_email', [__CLASS__, 'handle_send_slip_email']);
    }

    public static function register_metabox() {
        $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';

        add_meta_box(
            'wc_arca_invoice_box',
            __('Facturación ARCA / AFIP', 'wc-factura-arca'),
            [__CLASS__, 'render_metabox'],
            $screen,
            'side',
            'high'
        );

        // Si la pantalla es distinta de shop_order tradicional, agregar también a shop_order como fallback
        if ($screen !== 'shop_order') {
            add_meta_box(
                'wc_arca_invoice_box',
                __('Facturación ARCA / AFIP', 'wc-factura-arca'),
                [__CLASS__, 'render_metabox'],
                'shop_order',
                'side',
                'high'
            );
        }
    }

    public static function render_metabox($post_or_order) {
        $order = ($post_or_order instanceof \WC_Order) ? $post_or_order : wc_get_order($post_or_order->ID);
        if (!$order) return;

        $cae          = $order->get_meta('_arca_cae');
        $cae_vto      = $order->get_meta('_arca_cae_vto');
        $cbte_nro     = $order->get_meta('_arca_cbte_nro');
        $pto_vta      = $order->get_meta('_arca_pto_vta');
        $cbte_tipo    = $order->get_meta('_arca_cbte_tipo');
        $company_name = $order->get_meta('_arca_company_name');
        $credit_notes = $order->get_meta('_arca_credit_notes') ?: [];

        if ($cae) {
            $label = TaxResolver::get_cbte_label($cbte_tipo);
            $formatted_nro = sprintf('%04d-%08d', $pto_vta, $cbte_nro);
            $slip_url = \WcArca\Invoices\PackingSlipHandler::get_download_url($order->get_id());
            $send_inv_nonce = wp_create_nonce('wc_arca_mail_inv_' . $order->get_id());
            $send_slip_nonce = wp_create_nonce('wc_arca_mail_slip_' . $order->get_id());
            ?>
            <div class="wc-arca-details" style="font-size: 13px;">
                <p style="color: #2e7d32; font-weight: bold; margin-bottom: 5px;">Comprobante Autorizado</p>
                <?php if ($company_name): ?>
                    <p style="margin-bottom: 5px;"><strong>Emisor:</strong> <?php echo esc_html($company_name); ?></p>
                <?php endif; ?>
                <p><strong>Tipo:</strong> <?php echo esc_html($label); ?><br>
                <strong>Número:</strong> <?php echo esc_html($formatted_nro); ?><br>
                <strong>CAE:</strong> <?php echo esc_html($cae); ?><br>
                <strong>Vto CAE:</strong> <?php echo esc_html($cae_vto); ?></p>

                <!-- Botones de Acción de Factura y Remito -->
                <div style="margin-top: 12px; display: flex; flex-direction: column; gap: 6px;">
                    <a href="<?php echo esc_url(\WcArca\Invoices\PdfHandler::get_download_url($order->get_id())); ?>" class="button button-primary" target="_blank" style="text-align: center;">
                        Ver / Descargar Factura (PDF)
                    </a>
                    <a href="<?php echo esc_url(\WcArca\Invoices\SalesOrderHandler::get_download_url($order->get_id())); ?>" class="button" target="_blank" style="text-align: center;">
                        Ver Nota de Pedido / Venta (PDF)
                    </a>
                    <a href="<?php echo esc_url($slip_url); ?>" class="button" target="_blank" style="text-align: center;">
                        Ver / Descargar Remito (PDF)
                    </a>
                </div>
            </div>

            <?php if (!empty($credit_notes)): ?>
                <div style="border-top: 1px dashed #ccc; padding-top: 10px; margin-top: 10px;">
                    <p style="color: #c62828; font-weight: bold; margin-bottom: 5px;">Notas de Crédito Emitidas:</p>
                    <ul style="margin: 0; padding-left: 15px; font-size: 12px;">
                        <?php foreach ($credit_notes as $nc): 
                            $nc_label = TaxResolver::get_cbte_label($nc['cbte_tipo']);
                            $nc_nro = sprintf('%04d-%08d', $nc['pto_vta'], $nc['cbte_nro']);
                        ?>
                            <li><strong><?php echo esc_html($nc_label); ?> <?php echo esc_html($nc_nro); ?></strong> ($<?php echo number_format($nc['importe'], 2, ',', '.'); ?>) - CAE: <?php echo esc_html($nc['cae']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div style="border-top: 1px solid #ddd; padding-top: 10px; margin-top: 10px;">
                <p style="font-weight: 600; margin-bottom: 5px;">Emitir Nota de Crédito Manual:</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wc_arca_manual_credit_note">
                    <input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>">
                    <?php wp_nonce_field('wc_arca_nc_nonce'); ?>
                    
                    <p style="margin-bottom: 8px;">
                        <label style="font-size: 12px; display:block;">Monto a acreditar ($):</label>
                        <input type="number" step="0.01" max="<?php echo esc_attr($order->get_total()); ?>" name="nc_amount" value="<?php echo esc_attr($order->get_total()); ?>" style="width: 100%;">
                    </p>
                    <button type="submit" class="button" style="width: 100%; border-color: #c62828; color: #c62828;" onclick="return confirm('¿Confirmas la emisión de esta Nota de Crédito en ARCA? Esta acción es irreversible ante el fisco.');">
                        Emitir Nota de Crédito
                    </button>
                </form>
            </div>
            <?php
        } else {
            $companies = CompanyManager::get_companies();
            $default_id = get_option('wc_arca_default_company_id', 'default');
            ?>
            <div class="wc-arca-actions">
                <p style="margin-bottom: 10px;">No se ha emitido factura para este pedido.</p>
                
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wc_arca_manual_issue">
                    <input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>">
                    <?php wp_nonce_field('wc_arca_issue_nonce'); ?>

                    <p style="margin-bottom: 10px;">
                        <label style="font-size: 12px; font-weight: bold; display: block; margin-bottom: 3px;">Facturar con Empresa:</label>
                        <select name="company_id" style="width: 100%;">
                            <?php foreach ($companies as $id => $c): ?>
                                <option value="<?php echo esc_attr($id); ?>" <?php selected($id, $default_id); ?>>
                                    <?php echo esc_html($c['name']); ?> (<?php echo esc_html($c['cuit']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>

                    <button type="submit" class="button button-primary" style="width: 100%; text-align: center; margin-bottom: 8px;">
                        Emitir Factura Ahora
                    </button>
                </form>

                <?php 
                $slip_url = \WcArca\Invoices\PackingSlipHandler::get_download_url($order->get_id());
                ?>
                <div style="margin-top: 8px; display: flex; flex-direction: column; gap: 5px;">
                    <a href="<?php echo esc_url(\WcArca\Invoices\SalesOrderHandler::get_download_url($order->get_id())); ?>" class="button" target="_blank" style="width: 100%; text-align: center;">
                        Ver Nota de Pedido / Venta (PDF)
                    </a>
                    <a href="<?php echo esc_url($slip_url); ?>" class="button" target="_blank" style="width: 100%; text-align: center;">
                        Ver Remito de Entrega (PDF)
                    </a>
                </div>
            </div>
            <?php
        }
    }

    public static function handle_manual_issue() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('No tienes permisos para realizar esta acción.', 'wc-factura-arca'));
        }

        check_admin_referer('wc_arca_issue_nonce');

        $order_id   = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $company_id = isset($_POST['company_id']) ? sanitize_key($_POST['company_id']) : '';

        if (!$order_id) {
            wp_die(__('ID de orden inválido.', 'wc-factura-arca'));
        }

        $result = InvoiceTrigger::process_order_invoicing($order_id, $company_id);
        self::redirect_back($order_id, $result);
    }

    public static function handle_manual_credit_note() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('No tienes permisos para realizar esta acción.', 'wc-factura-arca'));
        }

        check_admin_referer('wc_arca_nc_nonce');

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $amount   = isset($_POST['nc_amount']) ? (float)$_POST['nc_amount'] : 0;

        if (!$order_id || $amount <= 0) {
            wp_die(__('Parámetros inválidos para la Nota de Crédito.', 'wc-factura-arca'));
        }

        $result = InvoiceTrigger::process_credit_note($order_id, $amount);
        self::redirect_back($order_id, $result);
    }

    public static function handle_send_invoice_email() {
        if (!current_user_can('manage_woocommerce')) wp_die('No autorizado');
        $order_id = absint($_GET['order_id'] ?? 0);
        check_admin_referer('wc_arca_mail_inv_' . $order_id);

        $result = \WcArca\Services\Mailer::send_invoice_email($order_id);
        self::redirect_back($order_id, $result);
    }

    public static function handle_send_slip_email() {
        if (!current_user_can('manage_woocommerce')) wp_die('No autorizado');
        $order_id = absint($_GET['order_id'] ?? 0);
        check_admin_referer('wc_arca_mail_slip_' . $order_id);

        $result = \WcArca\Services\Mailer::send_packing_slip_email($order_id);
        self::redirect_back($order_id, $result);
    }

    private static function redirect_back($order_id, $result) {
        $redirect_url = admin_url('post.php?post=' . $order_id . '&action=edit');
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $redirect_url = admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id);
        }

        if ($result['success']) {
            wp_redirect(add_query_arg('arca_notice', 'success', $redirect_url));
        } else {
            wp_redirect(add_query_arg(['arca_notice' => 'error', 'arca_msg' => urlencode($result['message'])], $redirect_url));
        }
        exit;
    }
}
