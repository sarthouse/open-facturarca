<?php
namespace WcArca\Invoices;

use WcArca\Services\CompanyManager;

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
        $filename = "Remito_R-" . sprintf('%08d', $order_id) . ".pdf";

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf_content));
        echo $pdf_content;
        exit;
    }

    /**
     * Construye el Remito en formato PDF real
     */
    public static function build_packing_slip_pdf($order) {
        $copies_mode = get_option('wc_arca_slip_copies', 'duplicado');
        $copies = ['ORIGINAL'];
        if ($copies_mode === 'duplicado') {
            $copies = ['ORIGINAL', 'DUPLICADO'];
        } elseif ($copies_mode === 'triplicado') {
            $copies = ['ORIGINAL', 'DUPLICADO', 'TRIPLICADO'];
        }

        $company_id  = $order->get_meta('_arca_company_id');
        $company     = $company_id ? \WcArca\Services\CompanyManager::get_company($company_id) : \WcArca\Services\CompanyManager::get_default_company();
        $emisor_name = $order->get_meta('_arca_company_name') ?: ($company['name'] ?? get_bloginfo('name'));
        $emisor_cuit = $order->get_meta('_arca_company_cuit') ?: ($company['cuit'] ?? '');

        $order_id = $order->get_id();
        $date     = $order->get_date_created() ? $order->get_date_created()->date('d/m/Y') : date('d/m/Y');
        $shipping = strip_tags($order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address());

        $pdf = new \WcArca\Services\SimplePdf();

        foreach ($copies as $copy_name) {
            $pdf->add_page();

            // Marco
            $pdf->rect(20, 20, 555, 800);

            // Letra X Badge
            $pdf->rect(275, 20, 45, 45, true, true);
            $pdf->text(290, 52, 'X', 24, true);
            $pdf->text(280, 62, 'COD. 99', 8, true);

            // Copia
            $pdf->text(500, 32, $copy_name, 9, true);

            // Emisor
            $pdf->text(30, 40, substr($emisor_name, 0, 35), 14, true);
            if ($emisor_cuit) {
                $pdf->text(30, 55, 'CUIT: ' . $emisor_cuit, 10, false);
            }

            // Remito
            $pdf->text(340, 40, 'REMITO DE ENTREGA', 14, true);
            $pdf->text(340, 55, 'Remito Nro: R-' . sprintf('%08d', $order_id), 10, true);
            $pdf->text(340, 68, 'Fecha: ' . $date, 10, false);

            // Banner No Válido
            $pdf->rect(20, 75, 555, 16, true, true);
            $pdf->text(180, 87, 'DOCUMENTO NO VALIDO COMO FACTURA', 10, true);

            // Datos Envío
            $destinatario = substr($order->get_formatted_shipping_full_name() ?: $order->get_formatted_billing_full_name(), 0, 35);
            $telefono = $order->get_billing_phone() ?: '-';

            $pdf->text(30, 110, 'Destinatario: ' . $destinatario, 10, true);
            $pdf->text(30, 125, 'Telefono: ' . $telefono, 10, false);
            $pdf->text(30, 140, 'Direccion: ' . substr($shipping, 0, 45), 9, false);

            $pdf->text(340, 110, 'Pedido WooCommerce: #' . $order_id, 10, true);
            $pdf->text(340, 125, 'Envio: ' . substr($order->get_shipping_method() ?: 'Estandar', 0, 30), 10, false);

            // Tabla
            $pdf->rect(20, 160, 555, 20, false, true);
            $pdf->text(30, 174, 'Item', 9, true);
            $pdf->text(60, 174, 'Descripcion', 9, true);
            $pdf->text(400, 174, 'SKU', 9, true);
            $pdf->text(500, 174, 'Cantidad', 9, true);

            $y = 195;
            $i = 1;
            foreach ($order->get_items() as $item) {
                if ($y > 700) break;
                $product = $item->get_product();
                $sku = $product ? $product->get_sku() : '-';

                $pdf->text(35, $y, (string)$i++, 9, false);
                $pdf->text(60, $y, substr($item->get_name(), 0, 48), 9, false);
                $pdf->text(400, $y, substr($sku ?: '-', 0, 15), 9, false);
                $pdf->text(515, $y, (string)$item->get_quantity(), 10, true);
                $pdf->line(20, $y + 5, 575, $y + 5, 0.5);
                $y += 20;
            }

            // Firmas
            $pdf->line(50, 780, 250, 780);
            $pdf->text(80, 795, 'Despachado / Embalado por', 9, false);

            $pdf->line(330, 780, 540, 780);
            $pdf->text(350, 795, 'Firma, Aclaracion y DNI de recepcion', 9, false);
        }

        return $pdf->output();
    }

    private static function render_html_slip($order) {
        $copies_mode = get_option('wc_arca_slip_copies', 'original');
        $copies = ['ORIGINAL'];
        if ($copies_mode === 'duplicado') {
            $copies = ['ORIGINAL', 'DUPLICADO'];
        } elseif ($copies_mode === 'triplicado') {
            $copies = ['ORIGINAL', 'DUPLICADO', 'TRIPLICADO'];
        }

        $company_id  = $order->get_meta('_arca_company_id');
        $company     = $company_id ? CompanyManager::get_company($company_id) : CompanyManager::get_default_company();
        $emisor_name = $order->get_meta('_arca_company_name') ?: ($company['name'] ?? get_bloginfo('name'));
        $emisor_cuit = $order->get_meta('_arca_company_cuit') ?: ($company['cuit'] ?? '');

        $order_id = $order->get_id();
        $date     = $order->get_date_created() ? $order->get_date_created()->date('d/m/Y') : date('d/m/Y');
        $shipping = $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address();
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <title>Remito de Entrega #<?php echo $order_id; ?></title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #222; margin: 25px; font-size: 13px; }
                .slip-container { max-width: 800px; margin: 0 auto 30px; border: 2px solid #111; padding: 20px; page-break-after: always; position: relative; }
                .slip-container:last-child { page-break-after: avoid; }
                .header { border-bottom: 2px solid #111; padding-bottom: 12px; margin-bottom: 15px; position: relative; }
                .letter-badge { width: 55px; height: 55px; border: 2px solid #111; position: absolute; left: 50%; margin-left: -28px; top: 0; background: #fff; text-align: center; }
                .letter-badge h1 { margin: 2px 0 0; font-size: 32px; line-height: 1; }
                .letter-badge span { font-size: 8px; display: block; font-weight: bold; }
                .row { display: flex; justify-content: space-between; }
                .col { width: 48%; }
                .no-valid-banner { background: #eee; text-align: center; font-weight: bold; font-size: 11px; padding: 4px; margin-top: 10px; border: 1px dashed #777; letter-spacing: 1px; }
                .copy-badge { position: absolute; right: 20px; top: 15px; font-size: 12px; font-weight: bold; background: #333; color: #fff; padding: 3px 8px; border-radius: 3px; }
                .table-items { width: 100%; border-collapse: collapse; margin-top: 20px; }
                .table-items th, .table-items td { border: 1px solid #999; padding: 8px; text-align: left; }
                .table-items th { background-color: #f0f0f0; }
                .signatures { margin-top: 35px; display: flex; justify-content: space-between; padding-top: 20px; }
                .sig-box { width: 45%; border-top: 1px solid #111; text-align: center; padding-top: 5px; font-size: 12px; }
                @media print {
                    .no-print { display: none; }
                    body { margin: 0; }
                }
            </style>
        </head>
        <body>
            <div class="no-print" style="max-width: 800px; margin: 0 auto 15px; text-align: right;">
                <button onclick="window.print()" style="padding: 8px 16px; font-size: 14px; cursor: pointer; font-weight: bold;">Imprimir Remito (<?php echo count($copies); ?> Copia/s)</button>
            </div>

            <?php foreach ($copies as $copy_name): ?>
            <div class="slip-container">
                <span class="copy-badge"><?php echo esc_html($copy_name); ?></span>

                <div class="header">
                    <div class="letter-badge">
                        <h1>X</h1>
                        <span>COD. 99</span>
                    </div>
                    <div class="row">
                        <div class="col">
                            <h2 style="margin: 0 0 5px; font-size: 18px;"><?php echo esc_html($emisor_name); ?></h2>
                            <?php if ($emisor_cuit): ?>
                                <p style="margin: 0;"><strong>CUIT:</strong> <?php echo esc_html($emisor_cuit); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="col" style="text-align: right; padding-right: 90px;">
                            <h2 style="margin: 0 0 5px; font-size: 18px;">REMITO DE ENTREGA</h2>
                            <p style="margin: 0;"><strong>Remito N°:</strong> R-<?php echo sprintf('%08d', $order_id); ?><br>
                            <strong>Fecha:</strong> <?php echo esc_html($date); ?></p>
                        </div>
                    </div>
                    <div class="no-valid-banner">
                        DOCUMENTO NO VÁLIDO COMO FACTURA
                    </div>
                </div>

                <div class="row" style="margin-bottom: 20px;">
                    <div class="col" style="background: #fafafa; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                        <p style="margin: 0 0 5px; font-weight: bold; border-bottom: 1px solid #eee; padding-bottom: 3px;">Datos de Envío y Destinatario:</p>
                        <p style="margin: 0; font-size: 12px; line-height: 1.4;">
                            <strong>Destinatario:</strong> <?php echo esc_html($order->get_formatted_shipping_full_name() ?: $order->get_formatted_billing_full_name()); ?><br>
                            <strong>Teléfono:</strong> <?php echo esc_html($order->get_billing_phone() ?: '-'); ?><br>
                            <strong>Dirección:</strong> <?php echo wp_kses_post($shipping ?: 'Retiro en local'); ?>
                        </p>
                    </div>
                    <div class="col" style="background: #fafafa; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                        <p style="margin: 0 0 5px; font-weight: bold; border-bottom: 1px solid #eee; padding-bottom: 3px;">Datos del Pedido:</p>
                        <p style="margin: 0; font-size: 12px; line-height: 1.4;">
                            <strong>Pedido WooCommerce:</strong> #<?php echo $order_id; ?><br>
                            <strong>Método de Envío:</strong> <?php echo esc_html($order->get_shipping_method() ?: 'Estándar'); ?><br>
                            <strong>Método de Pago:</strong> <?php echo esc_html($order->get_payment_method_title() ?: '-'); ?>
                        </p>
                    </div>
                </div>

                <table class="table-items">
                    <thead>
                        <tr>
                            <th style="width: 50px; text-align: center;">Item</th>
                            <th>Descripción del Producto</th>
                            <th style="width: 120px; text-align: center;">SKU</th>
                            <th style="width: 80px; text-align: center;">Cantidad</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $i = 1;
                        foreach ($order->get_items() as $item): 
                            $product = $item->get_product();
                            $sku = $product ? $product->get_sku() : '-';
                        ?>
                            <tr>
                                <td style="text-align: center;"><?php echo $i++; ?></td>
                                <td><strong><?php echo esc_html($item->get_name()); ?></strong></td>
                                <td style="text-align: center; font-size: 12px;"><?php echo esc_html($sku ?: '-'); ?></td>
                                <td style="text-align: center; font-size: 14px; font-weight: bold;"><?php echo esc_html($item->get_quantity()); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($order->get_customer_note()): ?>
                    <div style="margin-top: 15px; font-size: 12px; background: #fffbe6; padding: 8px; border: 1px solid #ffe58f;">
                        <strong>Nota del Cliente:</strong> <?php echo esc_html($order->get_customer_note()); ?>
                    </div>
                <?php endif; ?>

                <div class="signatures">
                    <div class="sig-box">
                        Despachado / Embalado por
                    </div>
                    <div class="sig-box">
                        Firma, Aclaración y DNI de quien recibe
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </body>
        </html>
        <?php
    }
}

