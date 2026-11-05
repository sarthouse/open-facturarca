<?php
/**
 * Plantilla de Renderizado PDF para Factura C
 * Emisor Monotributista
 *
 * Variables esperadas:
 * @var \WcArca\Services\SimplePdf $pdf
 * @var string $copy_name
 * @var array $data
 */
defined('ABSPATH') || exit;

$order         = $data['order'];
$company       = $data['company'];
$pto_vta       = $data['pto_vta'];
$cbte_nro      = $data['cbte_nro'];
$cbte_tipo     = $data['cbte_tipo'];
$cbte_fch_fmt  = $data['cbte_fch_fmt'];
$voucher_title = $data['voucher_title'];
$letra         = $data['letra'];
$cuit_emisor   = $data['cuit_emisor'];
$razon_social  = $data['razon_social'];
$nombre_emisor = $data['nombre_emisor'];
$iibb_emisor   = $data['iibb_emisor'];
$fch_inicio    = $data['fch_inicio'];
$clean_doc     = $data['clean_doc'];
$customer_address = $data['customer_address'];
$cae           = $data['cae'];
$cae_vto_fmt   = $data['cae_vto_fmt'];
$subtotal      = $data['subtotal'];
$total         = $data['total'];

// Marco exterior
$pdf->rect(20, 20, 555, 800);

// Letra Badge Central
$pdf->rect(275, 20, 45, 45, true, true, [245, 245, 245], [0, 0, 0]);
$pdf->text(288, 50, $letra, 22, true);
$pdf->text(279, 60, sprintf('COD. %02d', $cbte_tipo), 8, true);

// Indicador de Copia
$pdf->text(515, 30, $copy_name, 8, true);

// Emisor (Izquierda)
$logo_url = $company['logo_url'] ?? '';
$y_emisor = 34;

if (!empty($logo_url)) {
    $has_logo = $pdf->image($logo_url, 30, 26, 120, 38);
    if ($has_logo) {
        $y_emisor = 68;
    } else {
        $pdf->text(30, $y_emisor, substr($nombre_emisor, 0, 26), 12, true);
        $y_emisor += 13;
    }
} else {
    $pdf->text(30, $y_emisor, substr($nombre_emisor, 0, 26), 12, true);
    $y_emisor += 13;
}

if (!empty($company['trade_name']) && !empty($razon_social) && $company['trade_name'] !== $razon_social && empty($logo_url)) {
    $pdf->text(30, $y_emisor, 'Razon Social: ' . substr($razon_social, 0, 28), 8, false);
    $y_emisor += 10;
}

$pdf->text(30, $y_emisor, 'CUIT: ' . $cuit_emisor, 8, false);
$pdf->text(30, $y_emisor + 10, 'Cond. IVA: Monotributo', 8, false);
if (!empty($company['address'])) {
    $dir = trim($company['address'] . (!empty($company['province']) ? ', ' . $company['province'] : ''));
    $pdf->text(30, $y_emisor + 20, 'Domicilio: ' . substr($dir, 0, 36), 8, false);
    $y_emisor += 10;
}
if (!empty($company['phone'])) {
    $pdf->text(30, $y_emisor + 20, 'Telefono: ' . substr($company['phone'], 0, 25), 8, false);
}

// Comprobante (Derecha)
$pdf->text(340, 36, $voucher_title, 12, true);
$pdf->text(340, 50, sprintf('Pto. Vta: %s   Comp: %s', $pto_vta, $cbte_nro), 8, true);
$pdf->text(340, 62, 'Fecha de Emision: ' . $cbte_fch_fmt, 8, false);
$pdf->text(340, 72, 'Concepto: 1 - Productos', 8, false);
$pdf->text(340, 82, 'Ingresos Brutos: ' . $iibb_emisor, 8, false);
$pdf->text(340, 92, 'Inicio Act.: ' . $fch_inicio, 8, false);

// Separador
$pdf->line(20, 102, 575, 102);

// Cliente / Receptor (Razón Social estandarizado)
$cond_cli = ucfirst(str_replace('_', ' ', $order->get_meta('_billing_tax_condition') ?: 'Consumidor Final'));
$pdf->text(30, 114, 'Razon Social: ' . substr($order->get_formatted_billing_full_name(), 0, 35), 8, true);
$pdf->text(30, 124, 'Documento: ' . $clean_doc . '   Cond. IVA: ' . $cond_cli, 8, false);
$pdf->text(30, 134, 'Domicilio: ' . substr($customer_address, 0, 48), 8, false);
$pdf->text(30, 144, 'Telefono: ' . substr($order->get_billing_phone() ?: '-', 0, 20), 8, false);

$pdf->text(340, 114, 'Pedido WooCommerce: #' . $order->get_id(), 8, true);
$pdf->text(340, 124, 'Condicion de Venta: ' . substr($order->get_payment_method_title() ?: 'Contado', 0, 25), 8, false);
$pdf->text(340, 134, 'Email: ' . substr($order->get_billing_email() ?: '-', 0, 30), 8, false);

// Encabezado de Tabla de Ítems
$pdf->line(20, 154, 575, 154);
$pdf->rect(20, 154, 555, 18, false, true, [240, 240, 240], [0, 0, 0]);
$pdf->text(30, 166, 'Descripcion del Producto', 8, true);
$pdf->text(300, 166, 'Cant.', 8, true);
$pdf->text(340, 166, 'U.M.', 8, true);
$pdf->text(400, 166, 'Precio Unit.', 8, true);
$pdf->text(500, 166, 'Subtotal', 8, true);

$y = 186;
foreach ($order->get_items() as $item) {
    if ($y > 640) break;
    $qty = (float) $item->get_quantity();
    $subtotal_line = (float) $order->get_item_subtotal($item);
    $price_unit = $qty > 0 ? ($subtotal_line / $qty) : 0;

    $pdf->text(30, $y, substr($item->get_name(), 0, 45), 8, false);
    $pdf->text(300, $y, number_format($qty, 2, ',', '.'), 8, false);
    $pdf->text(340, $y, 'unidades', 7, false);
    $pdf->text(400, $y, '$' . number_format($price_unit, 2, ',', '.'), 8, false);
    $pdf->text(500, $y, '$' . number_format($subtotal_line, 2, ',', '.'), 8, false);
    $pdf->line(20, $y + 4, 575, $y + 4, 0.5);
    $y += 16;
}

// Descuentos (Cupones)
$discount_total = (float) $order->get_total_discount();
if ($discount_total > 0 && $y <= 650) {
    $coupon_codes = implode(', ', $order->get_coupon_codes());
    $desc_label = 'Descuento' . ($coupon_codes ? " ({$coupon_codes})" : '');
    $pdf->text(30, $y, substr($desc_label, 0, 45), 8, false);
    $pdf->text(300, $y, '1,00', 8, false);
    $pdf->text(340, $y, 'unidades', 7, false);
    $pdf->text(400, $y, '-$' . number_format($discount_total, 2, ',', '.'), 8, false);
    $pdf->text(500, $y, '-$' . number_format($discount_total, 2, ',', '.'), 8, false);
    $pdf->line(20, $y + 4, 575, $y + 4, 0.5);
    $y += 16;
}

// Recargos / Tasas (Fees)
foreach ($order->get_fees() as $fee) {
    if ($y > 660) break;
    $fee_total = (float) $fee->get_total();
    $pdf->text(30, $y, substr($fee->get_name(), 0, 45), 8, false);
    $pdf->text(300, $y, '1,00', 8, false);
    $pdf->text(340, $y, 'servicios', 7, false);
    $pdf->text(400, $y, '$' . number_format($fee_total, 2, ',', '.'), 8, false);
    $pdf->text(500, $y, '$' . number_format($fee_total, 2, ',', '.'), 8, false);
    $pdf->line(20, $y + 4, 575, $y + 4, 0.5);
    $y += 16;
}

// Envío
$shipping_total = (float) $order->get_shipping_total();
if ($shipping_total > 0 && $y <= 670) {
    $ship_name = $order->get_shipping_method() ?: 'Envio a domicilio';
    $pdf->text(30, $y, substr($ship_name, 0, 45), 8, false);
    $pdf->text(300, $y, '1,00', 8, false);
    $pdf->text(340, $y, 'servicios', 7, false);
    $pdf->text(400, $y, '$' . number_format($shipping_total, 2, ',', '.'), 8, false);
    $pdf->text(500, $y, '$' . number_format($shipping_total, 2, ',', '.'), 8, false);
    $pdf->line(20, $y + 4, 575, $y + 4, 0.5);
    $y += 16;
}

// Observaciones / Notas
if ($order->get_customer_note() && $y <= 680) {
    $pdf->text(30, $y + 6, 'Obs: ' . substr($order->get_customer_note(), 0, 80), 7, false);
}

// Resumen de Totales
$pdf->rect(360, 695, 215, 45);
$pdf->text(370, 707, 'Subtotal: $' . number_format($subtotal, 2, ',', '.'), 8, false);
if ($discount_total > 0) {
    $pdf->text(370, 718, 'Descuentos: -$' . number_format($discount_total, 2, ',', '.'), 8, false);
}
$pdf->line(360, 723, 575, 723);
$pdf->text(370, 735, 'TOTAL: $' . number_format($total, 2, ',', '.'), 11, true);

// Pie Fiscal ARCA
$pdf->line(20, 755, 575, 755);
if (!empty($data['qr_image_url'])) {
    $pdf->image($data['qr_image_url'], 30, 762, 50, 50);
}
$pdf->text(90, 775, 'CAE Nro: ' . $cae, 10, true);
$pdf->text(90, 788, 'Fecha de Vto. de CAE: ' . $cae_vto_fmt, 9, false);
$pdf->text(340, 775, 'Comprobante Autorizado por ARCA / AFIP', 9, true);
$pdf->text(340, 788, 'Verificacion fiscal disponible con codigo de autorizacion', 7, false);
