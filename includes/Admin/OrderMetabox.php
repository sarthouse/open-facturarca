<?php
namespace WcArca\Admin;

use WcArca\Orders\InvoiceTrigger;
use WcArca\Services\TaxResolver;
use WcArca\Services\CompanyManager;

defined('ABSPATH') || exit;

class OrderMetabox {
    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'register_metabox']);
        add_action('admin_notices', [__CLASS__, 'render_order_notices']);
        add_action('admin_post_wc_arca_manual_issue', [__CLASS__, 'handle_manual_issue']);
        add_action('admin_post_wc_arca_manual_credit_note', [__CLASS__, 'handle_manual_credit_note']);
        add_action('admin_post_wc_arca_reset_testing_invoice', [__CLASS__, 'handle_reset_testing_invoice']);
        add_action('admin_post_wc_arca_send_invoice_email', [__CLASS__, 'handle_send_invoice_email']);
        add_action('admin_post_wc_arca_send_slip_email', [__CLASS__, 'handle_send_slip_email']);
    }

    public static function render_order_notices() {
        if (!isset($_GET['arca_notice'])) return;

        $notice = sanitize_text_field($_GET['arca_notice']);
        $msg    = isset($_GET['arca_msg']) ? urldecode($_GET['arca_msg']) : '';

        if ($notice === 'success') {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Open FacturARCA:</strong> ' . esc_html__('Comprobante emitido y autorizado con éxito por ARCA.', 'open-facturarca') . '</p></div>';
        } elseif ($notice === 'error') {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Open FacturARCA - Error al Facturar:</strong> ' . esc_html($msg ?: __('Ocurrió un error al procesar el comprobante.', 'open-facturarca')) . '</p></div>';
        }
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

        // Mostrar aviso de éxito o error dentro del metabox (para compatibilidad total con HPOS y temas)
        if (isset($_GET['arca_notice'])) {
            $notice = sanitize_text_field($_GET['arca_notice']);
            $msg    = isset($_GET['arca_msg']) ? urldecode($_GET['arca_msg']) : '';
            if ($notice === 'success') {
                echo '<div style="background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; padding: 8px 10px; border-radius: 4px; margin-bottom: 12px; font-size: 12.5px;">';
                echo '<strong>Exito:</strong> ' . esc_html__('Comprobante emitido y autorizado con éxito por ARCA.', 'open-facturarca');
                echo '</div>';
            } elseif ($notice === 'error') {
                echo '<div style="background: #ffebee; color: #c62828; border: 1px solid #ef9a9a; padding: 8px 10px; border-radius: 4px; margin-bottom: 12px; font-size: 12.5px;">';
                echo '<strong>Error al facturar:</strong><br>' . esc_html($msg ?: __('Ocurrió un error al procesar el comprobante.', 'open-facturarca'));
                echo '</div>';
            }
        }

        $cae          = $order->get_meta('_arca_cae');
        $cae_vto      = $order->get_meta('_arca_cae_vto');
        $cbte_nro     = $order->get_meta('_arca_cbte_nro');
        $pto_vta      = $order->get_meta('_arca_pto_vta');
        $cbte_tipo    = $order->get_meta('_arca_cbte_tipo');
        $company_name = $order->get_meta('_arca_company_name');
        $arca_env     = $order->get_meta('_arca_environment') ?: 'testing';
        $credit_notes = $order->get_meta('_arca_credit_notes') ?: [];

        if ($cae) {
            $label = TaxResolver::get_cbte_label($cbte_tipo);
            $formatted_nro = sprintf('%04d-%08d', $pto_vta, $cbte_nro);
            $slip_url = \WcArca\Invoices\PackingSlipHandler::get_download_url($order->get_id());
            $send_inv_nonce = wp_create_nonce('wc_arca_mail_inv_' . $order->get_id());
            $send_slip_nonce = wp_create_nonce('wc_arca_mail_slip_' . $order->get_id());
            ?>
            <div class="wc-arca-details" style="font-size: 13px;">
                <?php if ($arca_env === 'testing'): ?>
                    <div style="background: #fff3e0; color: #e65100; border: 1px solid #ffe082; padding: 6px 8px; border-radius: 4px; margin-bottom: 8px; font-size: 11px; font-weight: bold;">
                        MODO HOMOLOGACIÓN / PRUEBA
                    </div>
                <?php endif; ?>
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
                    <?php if ($arca_env === 'testing'): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 6px;">
                            <input type="hidden" name="action" value="wc_arca_reset_testing_invoice">
                            <input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>">
                            <?php wp_nonce_field('wc_arca_reset_nonce'); ?>
                            <button type="submit" class="button" style="width: 100%; color: #d32f2f; border-color: #ef9a9a; font-size: 11.5px;" onclick="return confirm('¿Deseas desvincular este comprobante de prueba para poder refacturar el pedido? (Solo disponible para comprobantes de homologación)');">
                                Refacturar / Desvincular prueba
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($credit_notes)): ?>
                <div style="border-top: 1px dashed #ccc; padding-top: 10px; margin-top: 10px;">
                    <p style="color: #c62828; font-weight: bold; margin-bottom: 5px;">Notas de Crédito Emitidas:</p>
                    <ul style="margin: 0; padding-left: 0; list-style: none; font-size: 11.5px;">
                        <?php foreach ($credit_notes as $nc_idx => $nc): 
                            $nc_label = TaxResolver::get_cbte_label($nc['cbte_tipo']);
                            $nc_nro = sprintf('%04d-%08d', $nc['pto_vta'], $nc['cbte_nro']);
                            $nc_url = \WcArca\Invoices\PdfHandler::get_credit_note_download_url($order->get_id(), $nc_idx);
                        ?>
                            <li style="margin-bottom: 6px; padding-bottom: 6px; border-bottom: 1px dotted #e0e0e0;">
                                <strong><?php echo esc_html($nc_label); ?> <?php echo esc_html($nc_nro); ?></strong><br>
                                Importe: $<?php echo number_format($nc['importe'], 2, ',', '.'); ?> | CAE: <?php echo esc_html($nc['cae']); ?><br>
                                <a href="<?php echo esc_url($nc_url); ?>" class="button button-small" target="_blank" style="margin-top: 3px;">
                                    Descargar Nota de Crédito (PDF)
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div style="border-top: 1px solid #ddd; padding-top: 10px; margin-top: 10px;">
                <p style="font-weight: 600; margin-bottom: 5px;">Emitir Nota de Crédito Manual:</p>
                <div class="wc-arca-nc-form">
                    <p style="margin-bottom: 8px;">
                        <label style="font-size: 12px; display:block;">Monto a acreditar ($):</label>
                        <input type="number" step="0.01" max="<?php echo esc_attr($order->get_total()); ?>" id="wc_arca_nc_amount" value="<?php echo esc_attr($order->get_total()); ?>" style="width: 100%;">
                    </p>
                    <p style="margin-bottom: 8px;">
                        <label style="font-size: 12px; display:block;">Motivo / Concepto (Opcional):</label>
                        <input type="text" id="wc_arca_nc_reason" placeholder="Ej: Devolución de producto, Talle, Bonificación" style="width: 100%;">
                    </p>
                    <button type="button" class="button" style="width: 100%; border-color: #c62828; color: #c62828;" onclick="wcArcaSubmitCreditNote(<?php echo esc_attr($order->get_id()); ?>, '<?php echo esc_attr(wp_create_nonce('wc_arca_nc_nonce')); ?>');">
                        Emitir Nota de Crédito
                    </button>
                </div>
            </div>
            <?php
        } else {
            $companies = CompanyManager::get_companies();
            $default_id = get_option('wc_arca_default_company_id', 'default');
            ?>
            <div class="wc-arca-actions">
                <p style="margin-bottom: 10px;">No se ha emitido factura para este pedido.</p>
                
                <div class="wc-arca-issue-box">
                    <p style="margin-bottom: 10px;">
                        <label style="font-size: 12px; font-weight: bold; display: block; margin-bottom: 3px;">Facturar con Empresa:</label>
                        <select id="wc_arca_company_select" style="width: 100%;">
                            <?php foreach ($companies as $id => $c): ?>
                                <option value="<?php echo esc_attr($id); ?>" <?php selected($id, $default_id); ?>>
                                    <?php echo esc_html($c['name']); ?> (<?php echo esc_html($c['cuit']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>

                    <button type="button" id="wc_arca_btn_issue" class="button button-primary" style="width: 100%; text-align: center; margin-bottom: 8px;" onclick="wcArcaSubmitIssue(<?php echo esc_attr($order->get_id()); ?>, '<?php echo esc_attr(wp_create_nonce('wc_arca_issue_nonce')); ?>');">
                        Emitir Factura Ahora
                    </button>
                </div>

                <script>
                function wcArcaSubmitIssue(orderId, nonce) {
                    var btn = document.getElementById('wc_arca_btn_issue');
                    var companySelect = document.getElementById('wc_arca_company_select');
                    var companyId = companySelect ? companySelect.value : '';
                    
                    btn.disabled = true;
                    btn.innerText = 'Emitiendo ante ARCA...';

                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '<?php echo esc_url(admin_url('admin-post.php')); ?>';
                    
                    var fAction = document.createElement('input');
                    fAction.type = 'hidden'; fAction.name = 'action'; fAction.value = 'wc_arca_manual_issue';
                    form.appendChild(fAction);

                    var fOrder = document.createElement('input');
                    fOrder.type = 'hidden'; fOrder.name = 'order_id'; fOrder.value = orderId;
                    form.appendChild(fOrder);

                    var fCompany = document.createElement('input');
                    fCompany.type = 'hidden'; fCompany.name = 'company_id'; fCompany.value = companyId;
                    form.appendChild(fCompany);

                    var fNonce = document.createElement('input');
                    fNonce.type = 'hidden'; fNonce.name = '_wpnonce'; fNonce.value = nonce;
                    form.appendChild(fNonce);

                    document.body.appendChild(form);
                    form.submit();
                }

                function wcArcaSubmitCreditNote(orderId, nonce) {
                    if (!confirm('¿Confirmas la emisión de esta Nota de Crédito en ARCA? Esta acción es irreversible ante el fisco.')) {
                        return;
                    }
                    var amount = document.getElementById('wc_arca_nc_amount').value;
                    var reason = document.getElementById('wc_arca_nc_reason').value;

                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '<?php echo esc_url(admin_url('admin-post.php')); ?>';

                    var fAction = document.createElement('input');
                    fAction.type = 'hidden'; fAction.name = 'action'; fAction.value = 'wc_arca_manual_credit_note';
                    form.appendChild(fAction);

                    var fOrder = document.createElement('input');
                    fOrder.type = 'hidden'; fOrder.name = 'order_id'; fOrder.value = orderId;
                    form.appendChild(fOrder);

                    var fAmount = document.createElement('input');
                    fAmount.type = 'hidden'; fAmount.name = 'nc_amount'; fAmount.value = amount;
                    form.appendChild(fAmount);

                    var fReason = document.createElement('input');
                    fReason.type = 'hidden'; fReason.name = 'nc_reason'; fReason.value = reason;
                    form.appendChild(fReason);

                    var fNonce = document.createElement('input');
                    fNonce.type = 'hidden'; fNonce.name = '_wpnonce'; fNonce.value = nonce;
                    form.appendChild(fNonce);

                    document.body.appendChild(form);
                    form.submit();
                }
                </script>

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
        $reason   = isset($_POST['nc_reason']) ? sanitize_text_field($_POST['nc_reason']) : '';

        if (!$order_id || $amount <= 0) {
            wp_die(__('Parámetros inválidos para la Nota de Crédito.', 'wc-factura-arca'));
        }

        $result = InvoiceTrigger::process_credit_note($order_id, $amount, 0, $reason);
        self::redirect_back($order_id, $result);
    }

    public static function handle_reset_testing_invoice() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('No tienes permisos para realizar esta acción.', 'open-facturarca'));
        }

        check_admin_referer('wc_arca_reset_nonce');

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die(__('Pedido no encontrado.', 'open-facturarca'));
        }

        // Seguridad: solo permitir resetear si fue emitido en testing o si no tiene CAE oficial de produccion
        $arca_env = $order->get_meta('_arca_environment') ?: 'testing';
        if ($arca_env === 'production') {
            self::redirect_back($order_id, ['success' => false, 'message' => 'No se puede desvincular un comprobante fiscal emitido en entorno de Producción ante ARCA.']);
        }

        // Eliminar metadatos fiscales de prueba para habilitar refacturación
        $order->delete_meta_data('_arca_cae');
        $order->delete_meta_data('_arca_cae_vto');
        $order->delete_meta_data('_arca_cbte_nro');
        $order->delete_meta_data('_arca_pto_vta');
        $order->delete_meta_data('_arca_cbte_tipo');
        $order->delete_meta_data('_arca_cbte_fch');
        $order->delete_meta_data('_arca_company_id');
        $order->delete_meta_data('_arca_company_name');
        $order->delete_meta_data('_arca_company_cuit');
        $order->delete_meta_data('_arca_environment');
        $order->delete_meta_data('_arca_credit_notes');
        $order->save();

        $order->add_order_note(__('Comprobante de prueba (Homologación) desvinculado por el administrador. El pedido queda listo para refacturar.', 'open-facturarca'));

        self::redirect_back($order_id, ['success' => true, 'message' => 'Comprobante de prueba desvinculado con éxito. Ahora podés refacturar el pedido en Producción.']);
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
