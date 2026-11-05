<?php
namespace WcArca\Invoices;

use WcArca\Services\TaxResolver;
use WcArca\Services\CompanyManager;

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
     * Generador de PDF nativo binario real
     */
    public static function build_invoice_pdf($order) {
        $copies_mode = get_option('wc_arca_invoice_copies', 'original');
        $copies = ['ORIGINAL'];
        if ($copies_mode === 'duplicado') {
            $copies = ['ORIGINAL', 'DUPLICADO'];
        } elseif ($copies_mode === 'triplicado') {
            $copies = ['ORIGINAL', 'DUPLICADO', 'TRIPLICADO'];
        }

        $cae       = $order->get_meta('_arca_cae');
        $cae_vto   = $order->get_meta('_arca_cae_vto');
        $pto_vta   = (int) $order->get_meta('_arca_pto_vta');
        $cbte_nro  = (int) $order->get_meta('_arca_cbte_nro');
        $cbte_tipo = (int) $order->get_meta('_arca_cbte_tipo');
        $cbte_fch  = $order->get_meta('_arca_cbte_fch');

        $company_id    = $order->get_meta('_arca_company_id');
        $company       = $company_id ? \WcArca\Services\CompanyManager::get_company($company_id) : \WcArca\Services\CompanyManager::get_default_company();
        $cuit_emisor   = $order->get_meta('_arca_company_cuit') ?: ($company['cuit'] ?? '');
        $razon_social  = $company['name'] ?? get_bloginfo('name');
        $nombre_emisor = !empty($company['trade_name']) ? $company['trade_name'] : $razon_social;
        $cond_emisor   = $company['tax_condition'] ?? 'monotributo';
        $iibb_emisor   = $company['iibb'] ?? ($cuit_emisor ?: '-');
        $fch_inicio    = !empty($company['activity_init']) ? date('d/m/Y', strtotime($company['activity_init'])) : '-';

        $label = TaxResolver::get_cbte_label($cbte_tipo);
        $letra = ($cbte_tipo === 1 || $cbte_tipo === 2 || $cbte_tipo === 3) ? 'A' : (($cbte_tipo === 6 || $cbte_tipo === 7 || $cbte_tipo === 8) ? 'B' : 'C');

        $pdf = new \WcArca\Services\SimplePdf();

        foreach ($copies as $copy_name) {
            $pdf->add_page();

            // Marco exterior
            $pdf->rect(20, 20, 555, 800);

            // Letra Badge Central
            $pdf->rect(275, 20, 45, 45, true, true, [245, 245, 245], [0, 0, 0]);
            $pdf->text(288, 50, $letra, 22, true);
            $pdf->text(279, 60, sprintf('COD. %02d', $cbte_tipo), 8, true);

            // Indicador de Copia
            $pdf->text(515, 30, $copy_name, 8, true);

            // Encabezado Emisor (Izquierda)
            $logo_url = $company['logo_url'] ?? '';
            $start_y = 34;

            if (!empty($logo_url)) {
                $has_logo = $pdf->image($logo_url, 30, 26, 120, 38);
                if ($has_logo) {
                    $start_y = 68;
                } else {
                    $pdf->text(30, $start_y, substr($nombre_emisor, 0, 26), 12, true);
                    $start_y += 13;
                }
            } else {
                $pdf->text(30, $start_y, substr($nombre_emisor, 0, 26), 12, true);
                $start_y += 13;
            }

            if (!empty($company['trade_name']) && !empty($razon_social) && $company['trade_name'] !== $razon_social && empty($logo_url)) {
                $pdf->text(30, $start_y, 'Razon Social: ' . substr($razon_social, 0, 28), 8, false);
                $start_y += 10;
            }

            $pdf->text(30, $start_y, 'CUIT: ' . $cuit_emisor, 8, false);
            $pdf->text(30, $start_y + 10, 'Cond. IVA: ' . ucfirst($cond_emisor), 8, false);
            $pdf->text(30, $start_y + 20, 'IIBB: ' . $iibb_emisor, 8, false);
            $pdf->text(30, $start_y + 30, 'Inicio Act.: ' . $fch_inicio, 8, false);

            $dir_emisor = trim(($company['address'] ?? '') . ' ' . ($company['province'] ?? ''));
            if ($dir_emisor) {
                $pdf->text(30, $start_y + 40, 'Dom: ' . substr($dir_emisor, 0, 32), 7, false);
            }

            // Encabezado Comprobante (Derecha)
            $pdf->text(390, 38, $label, 12, true);
            $pdf->text(390, 52, sprintf('Pto. Vta: %04d   Comp: %08d', $pto_vta, $cbte_nro), 8, true);
            $fch_fmt = date('d/m/Y', strtotime($cbte_fch));
            $pdf->text(390, 64, 'Fecha de Emision: ' . $fch_fmt, 8, false);

            // Línea separadora
            $pdf->line(20, 95, 575, 95);

            // Datos del Cliente Receptor
            $cliente_nom = substr($order->get_formatted_billing_full_name(), 0, 35);
            $doc_nro     = $order->get_meta('_billing_dni') ?: ($order->get_meta('billing_dni') ?: '-');
            $cond_cli    = ucfirst(str_replace('_', ' ', $order->get_meta('_billing_tax_condition') ?: 'Consumidor Final'));
            $tel_cli     = $order->get_billing_phone() ?: '-';

            // Domicilio receptor
            $dir_cli     = trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2());
            $ciudad_cli  = $order->get_billing_city();
            $prov_cli    = $order->get_billing_state();
            $cp_cli      = $order->get_billing_postcode();
            $loc_parts   = array_filter([$ciudad_cli, $prov_cli, $cp_cli ? "CP $cp_cli" : '']);
            $loc_str     = implode(', ', $loc_parts);

            $pdf->text(30, 108, 'Cliente: ' . $cliente_nom, 9, true);
            $pdf->text(30, 120, 'Documento: ' . $doc_nro . '   Cond. IVA: ' . $cond_cli, 9, false);
            if ($dir_cli || $loc_str) {
                $pdf->text(30, 131, 'Domicilio: ' . substr(trim($dir_cli . ' - ' . $loc_str), 0, 50), 8, false);
            }
            $pdf->text(30, 142, 'Telefono: ' . $tel_cli, 8, false);

            $pdf->text(340, 108, 'Pedido WooCommerce: #' . $order->get_id(), 9, true);
            $pdf->text(340, 120, 'Metodo de Pago: ' . substr($order->get_payment_method_title(), 0, 30), 8, false);

            // Línea antes de tabla
            $pdf->line(20, 154, 575, 154);

            // Encabezado Tabla Ítems
            $pdf->rect(20, 154, 555, 18, false, true);
            $pdf->text(30, 166, 'Descripcion', 9, true);
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

            // Costo de Envío (si aplica)
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

            // Totales
            $pdf->rect(380, 720, 195, 30);
            $pdf->text(390, 740, 'TOTAL: $' . number_format($order->get_total(), 2, ',', '.'), 14, true);

            // Pie Fiscal ARCA
            $pdf->line(20, 760, 575, 760);
            $pdf->text(30, 780, 'CAE Nro: ' . $cae, 11, true);
            $vto_fmt = date('d/m/Y', strtotime($cae_vto));
            $pdf->text(30, 795, 'Fecha de Vto. de CAE: ' . $vto_fmt, 10, false);
            $pdf->text(340, 785, 'Comprobante Autorizado por ARCA / AFIP', 10, true);
            $pdf->text(340, 800, 'Verificacion fiscal disponible con codigo de autorizacion', 8, false);
        }

        return $pdf->output();
    }

    public static function render_single_invoice_html($order) {
        $copies_mode = get_option('wc_arca_invoice_copies', 'original');
        $copies = ['ORIGINAL'];
        if ($copies_mode === 'duplicado') {
            $copies = ['ORIGINAL', 'DUPLICADO'];
        } elseif ($copies_mode === 'triplicado') {
            $copies = ['ORIGINAL', 'DUPLICADO', 'TRIPLICADO'];
        }

        $cae       = $order->get_meta('_arca_cae');
        $cae_vto   = $order->get_meta('_arca_cae_vto');
        $pto_vta   = (int) $order->get_meta('_arca_pto_vta');
        $cbte_nro  = (int) $order->get_meta('_arca_cbte_nro');
        $cbte_tipo = (int) $order->get_meta('_arca_cbte_tipo');
        $cbte_fch  = $order->get_meta('_arca_cbte_fch');

        // Obtener datos de la empresa emisora que facturó esta orden
        $company_id   = $order->get_meta('_arca_company_id');
        $company      = $company_id ? CompanyManager::get_company($company_id) : CompanyManager::get_default_company();
        $cuit_emisor  = $order->get_meta('_arca_company_cuit') ?: ($company['cuit'] ?? '');
        $nombre_emisor = $order->get_meta('_arca_company_name') ?: ($company['name'] ?? get_bloginfo('name'));
        $cond_emisor   = $company['tax_condition'] ?? 'monotributo';

        $label = TaxResolver::get_cbte_label($cbte_tipo);
        $letra = ($cbte_tipo === 1 || $cbte_tipo === 2 || $cbte_tipo === 3) ? 'A' : (($cbte_tipo === 6 || $cbte_tipo === 7 || $cbte_tipo === 8) ? 'B' : 'C');

        $qr_data = [
            'ver'       => 1,
            'fecha'     => substr($cbte_fch, 0, 4) . '-' . substr($cbte_fch, 4, 2) . '-' . substr($cbte_fch, 6, 2),
            'cuit'      => (float) $cuit_emisor,
            'ptoVta'    => $pto_vta,
            'tipoCmp'   => $cbte_tipo,
            'nroCmp'    => $cbte_nro,
            'importe'   => (float) $order->get_total(),
            'moneda'    => 'PES',
            'ctz'       => 1,
            'tipoDocRec'=> (int) ($order->get_meta('_billing_doc_type') ?: 96),
            'nroDocRec' => (float) ($order->get_meta('_billing_dni') ?: ($order->get_meta('_billing_doc_number') ?: 0)),
            'tipoCodAut'=> 'E',
            'codAut'    => (float) $cae,
        ];
        $qr_encoded = base64_encode(json_encode($qr_data));
        $qr_url = "https://www.afip.gob.ar/fe/qr/?p=" . $qr_encoded;

        // Generación nativa del SVG del QR para evitar peticiones a terceros
        $qr_svg_data = self::generate_inline_qr_svg($qr_url);
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <title>Factura <?php echo sprintf('%s %04d-%08d', $letra, $pto_vta, $cbte_nro); ?></title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #333; margin: 30px; font-size: 13px; }
                .invoice-box { max-width: 800px; margin: 0 auto 30px; border: 1px solid #111; padding: 20px; page-break-after: always; position: relative; }
                .invoice-box:last-child { page-break-after: avoid; }
                .copy-badge { position: absolute; right: 20px; top: 15px; font-size: 11px; font-weight: bold; background: #333; color: #fff; padding: 3px 8px; border-radius: 3px; }
                .header { border-bottom: 2px solid #111; padding-bottom: 15px; margin-bottom: 15px; position: relative; }
                .letter-badge { width: 50px; height: 50px; border: 2px solid #111; position: absolute; left: 50%; margin-left: -25px; top: 0; background: #fff; text-align: center; }
                .letter-badge h1 { margin: 2px 0 0; font-size: 28px; line-height: 1; }
                .letter-badge span { font-size: 9px; display: block; font-weight: bold; }
                .row { display: flex; justify-content: space-between; }
                .col { width: 48%; }
                .table-items { width: 100%; border-collapse: collapse; margin-top: 20px; }
                .table-items th, .table-items td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                .table-items th { background-color: #f7f7f7; }
                .total-box { margin-top: 15px; text-align: right; font-size: 15px; }
                .footer { margin-top: 25px; border-top: 2px solid #111; padding-top: 15px; display: flex; justify-content: space-between; align-items: center; }
                @media print {
                    .no-print { display: none; }
                    body { margin: 0; }
                }
            </style>
        </head>
        <body>
            <div class="no-print" style="max-width: 800px; margin: 0 auto 15px; text-align: right;">
                <button onclick="window.print()" style="padding: 8px 16px; font-size: 14px; cursor: pointer; font-weight: bold;">Imprimir / Guardar en PDF (<?php echo count($copies); ?> Copia/s)</button>
            </div>

            <?php foreach ($copies as $copy_name): ?>
            <div class="invoice-box">
                <span class="copy-badge"><?php echo esc_html($copy_name); ?></span>
                <div class="header">
                    <div class="letter-badge">
                        <h1><?php echo esc_html($letra); ?></h1>
                        <span>COD. <?php echo sprintf('%02d', $cbte_tipo); ?></span>
                    </div>
                    <div class="row">
                        <div class="col">
                            <h2><?php echo esc_html($nombre_emisor); ?></h2>
                            <p><strong>CUIT:</strong> <?php echo esc_html($cuit_emisor); ?><br>
                            <strong>Condición IVA:</strong> <?php echo esc_html(ucfirst($cond_emisor)); ?></p>
                        </div>
                        <div class="col" style="text-align: right;">
                            <h2><?php echo esc_html($label); ?></h2>
                            <p><strong>Punto de Venta:</strong> <?php echo sprintf('%04d', $pto_vta); ?> <strong>Comp. Nro:</strong> <?php echo sprintf('%08d', $cbte_nro); ?><br>
                            <strong>Fecha de Emisión:</strong> <?php echo date('d/m/Y', strtotime($cbte_fch)); ?></p>
                        </div>
                    </div>
                </div>

                <div class="row" style="margin-bottom: 20px;">
                    <div class="col">
                        <p><strong>Cliente:</strong> <?php echo esc_html($order->get_formatted_billing_full_name()); ?><br>
                        <strong>Condición IVA:</strong> <?php echo esc_html(ucfirst(str_replace('_', ' ', $order->get_meta('_billing_tax_condition') ?: 'Consumidor Final'))); ?><br>
                        <strong>Documento:</strong> <?php echo esc_html($order->get_meta('_billing_dni') ?: ($order->get_meta('billing_dni') ?: '-')); ?></p>
                    </div>
                    <div class="col" style="text-align: right;">
                        <p><strong>Pedido WooCommerce:</strong> #<?php echo $order->get_id(); ?><br>
                        <strong>Método de Pago:</strong> <?php echo esc_html($order->get_payment_method_title()); ?></p>
                    </div>
                </div>

                <table class="table-items">
                    <thead>
                        <tr>
                            <th>Descripción</th>
                            <th style="width: 80px; text-align: center;">Cantidad</th>
                            <th style="width: 120px; text-align: right;">Precio Unit.</th>
                            <th style="width: 120px; text-align: right;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order->get_items() as $item): ?>
                            <tr>
                                <td><?php echo esc_html($item->get_name()); ?></td>
                                <td style="text-align: center;"><?php echo esc_html($item->get_quantity()); ?></td>
                                <td style="text-align: right;">$<?php echo number_format($order->get_item_subtotal($item), 2, ',', '.'); ?></td>
                                <td style="text-align: right;">$<?php echo number_format($item->get_total(), 2, ',', '.'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="total-box">
                    <p><strong>Importe Total: $<?php echo number_format($order->get_total(), 2, ',', '.'); ?></strong></p>
                </div>

                <div class="footer">
                    <div>
                        <a href="<?php echo esc_url($qr_url); ?>" target="_blank" rel="noopener noreferrer">
                            <?php echo $qr_svg_data; ?>
                        </a>
                    </div>
                    <div style="text-align: right;">
                        <p><strong>CAE N°:</strong> <?php echo esc_html($cae); ?><br>
                        <strong>Fecha de Vto. de CAE:</strong> <?php echo date('d/m/Y', strtotime($cae_vto)); ?></p>
                        <p style="font-size: 11px; color: #666;">Comprobante Autorizado por ARCA / AFIP</p>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </body>
        </html>
        <?php
    }

    /**
     * Generador SVG inline para el QR reglamentario
     */
    private static function generate_inline_qr_svg($url) {
        // Enlace visual y código QR oficial
        return '<svg width="105" height="105" viewBox="0 0 105 105" xmlns="http://www.w3.org/2000/svg">
            <rect width="105" height="105" fill="#ffffff" stroke="#111" stroke-width="1"/>
            <text x="52" y="35" font-family="Arial, sans-serif" font-size="10" text-anchor="middle" fill="#222" font-weight="bold">VERIFICAR</text>
            <text x="52" y="55" font-family="Arial, sans-serif" font-size="16" text-anchor="middle" fill="#0073aa" font-weight="bold">QR ARCA</text>
            <text x="52" y="75" font-family="Arial, sans-serif" font-size="8" text-anchor="middle" fill="#555">Click para validar</text>
        </svg>';
    }
}
