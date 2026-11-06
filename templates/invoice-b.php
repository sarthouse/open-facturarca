<?php
/**
 * Plantilla HTML de Factura B para Dompdf
 * Letra B - COD. 006
 *
 * Variables esperadas:
 * @var WC_Order $order
 * @var array    $company
 * @var string   $pto_vta
 * @var string   $cbte_nro
 * @var int      $cbte_tipo
 * @var string   $cbte_fch_fmt
 * @var string   $voucher_title
 * @var string   $letra
 * @var string   $cuit_emisor
 * @var string   $razon_social
 * @var string   $nombre_emisor
 * @var string   $iibb_emisor
 * @var string   $fch_inicio
 * @var string   $clean_doc
 * @var string   $customer_address
 * @var string   $cae
 * @var string   $cae_vto_fmt
 * @var float    $subtotal
 * @var float    $total
 * @var float    $iva_contenido
 * @var string   $qr_image_url
 * @var string   $copy_name
 */
defined('ABSPATH') || exit;

$cliente_nombre = $order->get_formatted_billing_full_name() ?: $order->get_formatted_shipping_full_name();
$telefono       = $order->get_billing_phone() ?: '-';
$email          = $order->get_billing_email() ?: '-';
$logo_url       = $company['logo_url'] ?? '';

// En Factura B (Consumidor Final), todos los valores deben incluir IVA
$discount_total = (float) $order->get_discount_total() + (float) $order->get_discount_tax();
$shipping_total = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
?>
<div class="voucher-page">
    <div class="border-box">
        <!-- FRANJA SUPERIOR DE COPIA (ESTILO AFIP) -->
        <div class="header-copy-bar"><?php echo esc_html($copy_name); ?></div>

        <!-- RECUADRO CENTRAL DE LA LETRA (ESTILO AFIP) -->
        <div class="header-badge-box">
            <span class="letter-badge"><?php echo esc_html($letra); ?></span>
            <span class="letter-code">COD. <?php echo sprintf('%03d', $cbte_tipo); ?></span>
        </div>

        <!-- ENCABEZADO -->
        <table class="header-table">
            <tr>
                <!-- Columna Izquierda: Emisor -->
                <td class="header-col-left">
                    <?php if (!empty($logo_url)): ?>
                        <img src="<?php echo esc_url($logo_url); ?>" alt="Logo" class="logo">
                    <?php endif; ?>
                    <div class="brand-title"><?php echo esc_html($nombre_emisor); ?></div>
                    <div class="meta-line"><strong>Razón Social:</strong> <?php echo esc_html($razon_social); ?></div>
                    <?php if (!empty($company['address'])): 
                        $dir_full = $company['address'] . (!empty($company['province']) ? ', ' . $company['province'] : '') . (!empty($company['postcode']) ? ' (CP ' . $company['postcode'] . ')' : '');
                    ?>
                        <div class="meta-line"><strong>Domicilio Comercial:</strong> <?php echo esc_html($dir_full); ?></div>
                    <?php endif; ?>
                    <div class="meta-line"><strong>Condición frente al IVA:</strong> Responsable Inscripto</div>
                    <?php if (!empty($company['phone'])): ?>
                        <div class="meta-line"><strong>Teléfono:</strong> <?php echo esc_html($company['phone']); ?></div>
                    <?php endif; ?>
                </td>

                <!-- Columna Derecha: Datos del Comprobante -->
                <td class="header-col-right">
                    <div class="voucher-title"><?php echo esc_html($voucher_title); ?></div>
                    <div class="meta-line"><strong>Punto de Venta:</strong> <?php echo esc_html($pto_vta); ?> &nbsp;&nbsp;&nbsp; <strong>Comp Nro:</strong> <?php echo esc_html($cbte_nro); ?></div>
                    <div class="meta-line"><strong>Fecha de Emisión:</strong> <?php echo esc_html($cbte_fch_fmt); ?></div>
                    <?php if (!empty($cuit_emisor)): ?>
                        <div class="meta-line"><strong>CUIT:</strong> <?php echo esc_html($cuit_emisor); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($iibb_emisor)): ?>
                        <div class="meta-line"><strong>Ingresos Brutos:</strong> <?php echo esc_html($iibb_emisor); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($fch_inicio)): ?>
                        <div class="meta-line"><strong>Fecha de Inicio de Actividades:</strong> <?php echo esc_html($fch_inicio); ?></div>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <!-- DATOS DEL CLIENTE -->
        <table class="client-table">
            <tr>
                <td class="client-col">
                    <div class="meta-line"><strong>Razón Social / Cliente:</strong> <?php echo esc_html($cliente_nombre); ?></div>
                    <div class="meta-line"><strong>DNI / CUIT:</strong> <?php echo esc_html($clean_doc); ?></div>
                    <div class="meta-line"><strong>Condición IVA:</strong> Consumidor Final</div>
                    <div class="meta-line"><strong>Método de pago:</strong> <?php echo esc_html($order->get_payment_method_title() ?: 'Contado'); ?></div>
                </td>
                <td class="client-col">
                    <div class="meta-line"><strong>Domicilio:</strong> <?php echo esc_html($domicilio); ?></div>
                    <div class="meta-line"><strong>Localidad:</strong> <?php echo esc_html($localidad); ?></div>
                    <div class="meta-line"><strong>Provincia:</strong> <?php echo esc_html($provincia); ?></div>
                    <div class="meta-line"><strong>Teléfono:</strong> <?php echo esc_html($telefono); ?></div>
                    <div class="meta-line"><strong>Email:</strong> <?php echo esc_html($email); ?></div>
                    <div class="meta-line"><strong>Pedido WooCommerce:</strong> #<?php echo esc_html($order->get_id()); ?></div>
                </td>
            </tr>
        </table>

        <!-- TABLA DE PRODUCTOS -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 48%;">Descripción del Producto</th>
                    <th class="text-center" style="width: 12%;">Cantidad</th>
                    <th class="text-center" style="width: 12%;">U.M.</th>
                    <th class="text-right" style="width: 14%;">Precio Unit.</th>
                    <th class="text-right" style="width: 14%;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                foreach ($order->get_items() as $item): 
                    $qty = (float) $item->get_quantity();
                    // Subtotal de la línea de producto con impuestos incluidos (Factura B)
                    $subtotal_line = (float) $item->get_subtotal() + (float) $item->get_subtotal_tax();
                    $price_unit = $qty > 0 ? ($subtotal_line / $qty) : 0;
                ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($item->get_name()); ?></strong>
                        <?php 
                        $item_meta = wc_display_item_meta($item, ['echo' => false]);
                        if (!empty($item_meta)): ?>
                            <div class="item-meta-list"><?php echo $item_meta; ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><?php echo number_format($qty, 2, ',', '.'); ?></td>
                    <td class="text-center">unidades</td>
                    <td class="text-right">$<?php echo number_format($price_unit, 2, ',', '.'); ?></td>
                    <td class="text-right">$<?php echo number_format($subtotal_line, 2, ',', '.'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="footer-pinned">
            <!-- TOTALES, LEY 27.743 Y AUTORIZACIÓN ARCA -->
            <table class="totals-table">
                <tr>
                    <td class="totals-col-left">
                        <strong>Régimen de Transparencia Fiscal (Ley 27.743):</strong><br>
                        El importe total discriminado contiene IVA estimado por <strong>$<?php echo number_format($iva_contenido, 2, ',', '.'); ?></strong>.
                    </td>
                    <td class="totals-col-right">
                        <table class="subtotals-table">
                            <?php
                            // Subtotal de productos con IVA incluido
                            $items_subtotal = 0;
                            foreach ($order->get_items() as $item) {
                                $items_subtotal += (float) $item->get_subtotal() + (float) $item->get_subtotal_tax();
                            }
                            ?>
                            <tr>
                                <td><strong>Subtotal:</strong></td>
                                <td class="text-right">$<?php echo number_format($items_subtotal, 2, ',', '.'); ?></td>
                            </tr>
                            <?php if ($discount_total > 0): ?>
                            <tr>
                                <td><strong>(-) Descuento:</strong></td>
                                <td class="text-right">-$<?php echo number_format($discount_total, 2, ',', '.'); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($shipping_total > 0): ?>
                            <tr>
                                <td><strong>(+) Envío:</strong></td>
                                <td class="text-right">$<?php echo number_format($shipping_total, 2, ',', '.'); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php 
                            $fees_total = 0;
                            foreach ($order->get_fees() as $fee) {
                                $fees_total += (float) $fee->get_total() + (float) $fee->get_total_tax();
                            }
                            if ($fees_total > 0): ?>
                            <tr>
                                <td><strong>(+) Recargos:</strong></td>
                                <td class="text-right">$<?php echo number_format($fees_total, 2, ',', '.'); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr class="total-highlight">
                                <td style="padding-top: 6px; padding-bottom: 6px;"><strong>TOTAL:</strong></td>
                                <td class="text-right" style="padding-top: 6px; padding-bottom: 6px;"><strong>$<?php echo number_format($total, 2, ',', '.'); ?></strong></td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        
            <div class="arca-footer">
                <table>
                    <tr>
                        <td class="arca-qr-cell">
                            <?php if (!empty($qr_image_url)): ?>
                                <img src="<?php echo esc_url($qr_image_url); ?>" alt="QR ARCA" class="arca-qr">
                            <?php endif; ?>
                        </td>
                        <td class="arca-data-cell">
                            <div><strong>CAE N°:</strong> <?php echo esc_html($cae ?: '-'); ?></div>
                            <div style="margin-top: 3px;"><strong>Fecha de Vto. de CAE:</strong> <?php echo esc_html($cae_vto_fmt); ?></div>
                            <div style="font-size: 8px; color: #666; margin-top: 4px;">
                                <?php if (!empty($is_testing)): ?>
                                    <strong style="color: #c62828;">[ENTORNO DE HOMOLOGACIÓN / PRUEBAS - AFIP]</strong> Comprobante emitido a fines de prueba y desarrollo. No posee validez tributaria.
                                <?php else: ?>
                                    Comprobante Autorizado por ARCA (Agencia de Recaudación y Control Aduanero). Escanee el código QR para validar la autenticidad fiscal.
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>
