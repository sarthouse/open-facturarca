<?php
/**
 * Plantilla de Renderizado PDF para Remito de Entrega
 * Letra R - COD. 91
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
$emisor_name   = !empty($company['trade_name']) ? $company['trade_name'] : ($company['name'] ?? get_bloginfo('name'));
$razon_social  = $company['name'] ?? '';
$emisor_cuit   = $company['cuit'] ?? '';

// Marco exterior
$pdf->rect(20, 20, 555, 800);

// Letra Badge Central R (COD. 91)
$pdf->rect(275, 20, 45, 40, true, true, [245, 245, 245], [0, 0, 0]);
$pdf->text(291, 46, 'R', 20, true);
$pdf->text(280, 56, 'COD. 91', 8, true);

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
$pdf->text(340, 36, 'REMITO DE ENTREGA', 12, true);
$pdf->text(340, 50, sprintf('Pto. Vta: %s   Comp: %s', $pto_vta, $cbte_nro), 8, true);
$pdf->text(340, 62, 'Fecha de Emision: ' . $date, 8, false);

// Separador
$pdf->line(20, 95, 575, 95);

// Cliente / Destinatario (Razón Social estandarizado)
$destinatario = $order->get_formatted_shipping_full_name() ?: $order->get_formatted_billing_full_name();
$pdf->text(30, 108, 'Razon Social: ' . substr($destinatario, 0, 35), 8, true);
$pdf->text(30, 119, 'Documento: ' . $clean_doc, 8, false);
$pdf->text(30, 130, 'Direccion de Entrega: ' . substr($customer_address, 0, 48), 8, false);
$pdf->text(30, 141, 'Telefono de Contacto: ' . substr($order->get_billing_phone() ?: '-', 0, 20), 8, false);

$pdf->text(340, 108, 'Pedido WooCommerce: #' . $order->get_id(), 8, true);
$pdf->text(340, 119, 'Metodo de Envio: ' . substr($order->get_shipping_method() ?: 'Estandar', 0, 25), 8, false);
$pdf->text(340, 130, 'Metodo de Pago: ' . substr($order->get_payment_method_title() ?: '-', 0, 25), 8, false);

// Encabezado de Tabla de Ítems (Remito enfocado en cantidades, bultos y SKU)
$pdf->line(20, 154, 575, 154);
$pdf->rect(20, 154, 555, 18, false, true, [240, 240, 240], [0, 0, 0]);
$pdf->text(30, 166, 'Item', 8, true);
$pdf->text(70, 166, 'Descripcion del Producto', 8, true);
$pdf->text(380, 166, 'SKU', 8, true);
$pdf->text(480, 166, 'Cant. / Unid.', 8, true);

$y = 186;
$item_num = 1;
foreach ($order->get_items() as $item) {
    if ($y > 670) break;
    $product = $item->get_product();
    $sku = $product ? $product->get_sku() : '-';
    $qty = (float) $item->get_quantity();

    $pdf->text(30, $y, sprintf('%02d', $item_num++), 8, false);
    $pdf->text(70, $y, substr($item->get_name(), 0, 48), 8, true);
    $pdf->text(380, $y, substr($sku ?: '-', 0, 15), 8, false);
    $pdf->text(480, $y, number_format($qty, 2, ',', '.') . ' un.', 8, true);
    $pdf->line(20, $y + 4, 575, $y + 4, 0.5);
    $y += 18;
}

// Observaciones / Notas
if ($order->get_customer_note() && $y <= 690) {
    $pdf->text(30, $y + 6, 'Obs: ' . substr($order->get_customer_note(), 0, 80), 7, false);
}

// Cuadro de Firmas de Recepción
$pdf->line(20, 715, 575, 715);
$pdf->line(50, 780, 240, 780);
$pdf->text(70, 792, 'Despachado / Embalado por', 8, false);

$pdf->line(330, 780, 540, 780);
$pdf->text(345, 792, 'Firma, Aclaracion y DNI de recepcion', 8, false);
