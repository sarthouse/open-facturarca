<?php
/**
 * Plantilla de Renderizado PDF para Nota de Pedido / Venta
 * Letra X - COD. 99
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
$date          = $data['date'];
$clean_doc     = $data['clean_doc'];
$customer_address = $data['customer_address'];
$subtotal      = $data['subtotal'];
$total         = $data['total'];
$emisor_name   = !empty($company['trade_name']) ? $company['trade_name'] : ($company['name'] ?? get_bloginfo('name'));
$razon_social  = $company['name'] ?? '';
$emisor_cuit   = $company['cuit'] ?? '';

// Marco exterior
$pdf->rect(20, 20, 555, 800);

// Letra Badge Central X (COD. 99)
$pdf->rect(275, 20, 45, 40, true, true, [245, 245, 245], [0, 0, 0]);
$pdf->text(291, 46, 'X', 20, true);
$pdf->text(280, 56, 'COD. 99', 8, true);

// Leyenda obligatoria debajo de la letra
$pdf->text(225, 70, 'DOCUMENTO NO VALIDO', 8, true);
$pdf->text(236, 80, 'COMO FACTURA', 8, true);

// Indicador de Copia
$pdf->text(515, 30, $copy_name, 8, true);

// Emisor (Izquierda)
$logo_url = $company['logo_url'] ?? '';
$y_emisor = 34;

if (!empty($logo_url)) {
    $has_logo = $pdf->image($logo_url, 30, 24, 80, 28);
    if ($has_logo) {
        $y_emisor = 58;
    } else {
        $pdf->text(30, $y_emisor, substr($emisor_name, 0, 26), 12, true);
        $y_emisor += 13;
    }
} else {
    $pdf->text(30, $y_emisor, substr($emisor_name, 0, 26), 12, true);
    $y_emisor += 13;
}

if (!empty($razon_social) && $emisor_name !== $razon_social && empty($logo_url)) {
    $pdf->text(30, $y_emisor, 'Razon Social: ' . substr($razon_social, 0, 28), 8, false);
    $y_emisor += 10;
}

if (!empty($emisor_cuit)) {
    $pdf->text(30, $y_emisor, 'CUIT: ' . $emisor_cuit, 8, false);
    $y_emisor += 10;
}
if (!empty($company['address'])) {
    $dir = trim($company['address'] . (!empty($company['province']) ? ', ' . $company['province'] : ''));
    $pdf->text(30, $y_emisor, 'Domicilio: ' . substr($dir, 0, 36), 8, false);
    $y_emisor += 10;
}
if (!empty($company['phone'])) {
    $pdf->text(30, $y_emisor, 'Telefono: ' . substr($company['phone'], 0, 25), 8, false);
}

// Comprobante (Derecha)
$pdf->text(340, 36, 'NOTA DE PEDIDO / VENTA', 11, true);
$pdf->text(340, 50, sprintf('Pto. Vta: %s   Comp: %s', $pto_vta, $cbte_nro), 8, true);
$pdf->text(340, 62, 'Fecha de Emision: ' . $date, 8, false);

// Separador
$pdf->line(20, 95, 575, 95);

// Cliente / Receptor (Razón Social estandarizado)
$cond_cli = ucfirst(str_replace('_', ' ', $order->get_meta('_billing_tax_condition') ?: 'Consumidor Final'));
$pdf->text(30, 108, 'Razon Social: ' . substr($order->get_formatted_billing_full_name(), 0, 35), 8, true);
$pdf->text(30, 119, 'Documento: ' . $clean_doc . '   Cond. IVA: ' . $cond_cli, 8, false);
$pdf->text(30, 130, 'Domicilio: ' . substr($customer_address, 0, 48), 8, false);
$pdf->text(30, 141, 'Telefono: ' . substr($order->get_billing_phone() ?: '-', 0, 20), 8, false);

$pdf->text(340, 108, 'Pedido WooCommerce: #' . $order->get_id(), 8, true);
$pdf->text(340, 119, 'Condicion de Venta: ' . substr($order->get_payment_method_title() ?: 'Contado', 0, 25), 8, false);
$pdf->text(340, 130, 'Email: ' . substr($order->get_billing_email() ?: '-', 0, 30), 8, false);

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
    if ($y > 650) break;
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
if ($discount_total > 0 && $y <= 660) {
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
    if ($y > 670) break;
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
if ($shipping_total > 0 && $y <= 680) {
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
if ($order->get_customer_note() && $y <= 690) {
    $pdf->text(30, $y + 6, 'Obs: ' . substr($order->get_customer_note(), 0, 80), 7, false);
}

// Resumen de Totales
$pdf->rect(360, 715, 215, 40);
$pdf->text(370, 727, 'Subtotal: $' . number_format($subtotal, 2, ',', '.'), 8, false);
if ($discount_total > 0) {
    $pdf->text(370, 737, 'Descuentos: -$' . number_format($discount_total, 2, ',', '.'), 8, false);
}
$pdf->line(360, 741, 575, 741);
$pdf->text(370, 751, 'TOTAL: $' . number_format($total, 2, ',', '.'), 11, true);

// Pie informativo
$pdf->line(20, 765, 575, 765);
$pdf->text(30, 785, 'Comprobante emitido a efectos informativos y de control comercial.', 8, false);
$pdf->text(30, 796, 'No reemplaza a la factura fiscal emitida ante ARCA / AFIP.', 7, false);
