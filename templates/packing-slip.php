<?php
/**
 * Plantilla HTML de Remito para Dompdf
 * Letra X - COD. 099
 *
 * Variables esperadas:
 * @var WC_Order $order
 * @var array    $company
 * @var string   $pto_vta
 * @var string   $cbte_nro
 * @var string   $date
 * @var string   $clean_doc
 * @var string   $customer_address
 * @var string   $copy_name
 */
defined('ABSPATH') || exit;

$emisor_name  = !empty($company['trade_name']) ? $company['trade_name'] : ($company['name'] ?? get_bloginfo('name'));
$razon_social = $company['name'] ?? '';
$emisor_cuit  = $company['cuit'] ?? '';
$logo_url     = $company['logo_url'] ?? '';

$destinatario = $order->get_formatted_shipping_full_name() ?: $order->get_formatted_billing_full_name();
$telefono     = $order->get_billing_phone() ?: '-';
$shipping_method = $order->get_shipping_method() ?: 'Coordinar entrega';
?>
<div class="voucher-page">
    <div class="border-box">
        <!-- FRANJA SUPERIOR DE COPIA (ESTILO AFIP) -->
        <div class="header-copy-bar"><?php echo esc_html($copy_name); ?></div>

        <!-- RECUADRO CENTRAL DE LA LETRA (ESTILO AFIP) -->
        <div class="header-badge-box">
            <span class="letter-badge">X</span>
            <span class="letter-code">COD. 099</span>
            <div class="non-fiscal-warning">NO VÁLIDO FACTURA</div>
        </div>

        <!-- ENCABEZADO -->
        <table class="header-table">
            <tr>
                <!-- Columna Izquierda: Emisor -->
                <td class="header-col-left">
                    <?php if (!empty($logo_url)): ?>
                        <img src="<?php echo esc_url($logo_url); ?>" alt="Logo" class="logo">
                    <?php endif; ?>
                    <div class="brand-title"><?php echo esc_html($emisor_name); ?></div>
                    <?php if (!empty($razon_social) && $emisor_name !== $razon_social): ?>
                        <div class="meta-line"><strong>Razón Social:</strong> <?php echo esc_html($razon_social); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($emisor_cuit)): ?>
                        <div class="meta-line"><strong>CUIT:</strong> <?php echo esc_html($emisor_cuit); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($company['address'])): 
                        $dir_full = $company['address'] . (!empty($company['province']) ? ', ' . $company['province'] : '') . (!empty($company['postcode']) ? ' (CP ' . $company['postcode'] . ')' : '');
                    ?>
                        <div class="meta-line"><strong>Domicilio Comercial:</strong> <?php echo esc_html($dir_full); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($company['phone'])): ?>
                        <div class="meta-line"><strong>Teléfono:</strong> <?php echo esc_html($company['phone']); ?></div>
                    <?php endif; ?>
                </td>

                <!-- Columna Derecha: Datos del Remito -->
                <td class="header-col-right">
                    <div class="voucher-title">REMITO</div>
                    <div class="meta-line"><strong>Punto de Venta:</strong> <?php echo esc_html($pto_vta); ?> &nbsp;&nbsp;&nbsp; <strong>Remito Nro:</strong> <?php echo esc_html($cbte_nro); ?></div>
                    <div class="meta-line"><strong>Fecha de Emisión:</strong> <?php echo esc_html($date); ?></div>
                    <?php if (!empty($company['iibb'])): ?>
                        <div class="meta-line"><strong>Ingresos Brutos:</strong> <?php echo esc_html($company['iibb']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($company['activity_init'])): ?>
                        <div class="meta-line"><strong>Fecha de Inicio de Actividades:</strong> <?php echo esc_html(date('d/m/Y', strtotime($company['activity_init']))); ?></div>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <!-- DATOS DE DESTINATARIO Y ENVÍO -->
        <table class="client-table">
            <tr>
                <td class="client-col">
                    <div class="meta-line"><strong>Razón Social / Destinatario:</strong> <?php echo esc_html($destinatario); ?></div>
                    <div class="meta-line"><strong>Documento / DNI:</strong> <?php echo esc_html($clean_doc); ?></div>
                    <div class="meta-line"><strong>Dirección de Entrega:</strong> <?php echo esc_html($customer_address); ?></div>
                    <div class="meta-line"><strong>Teléfono de Contacto:</strong> <?php echo esc_html($telefono); ?></div>
                </td>
                <td class="client-col">
                    <div class="meta-line"><strong>Pedido WooCommerce:</strong> #<?php echo esc_html($order->get_id()); ?></div>
                    <div class="meta-line"><strong>Método de Envío:</strong> <?php echo esc_html($shipping_method); ?></div>
                    <div class="meta-line"><strong>Método de Pago:</strong> <?php echo esc_html($order->get_payment_method_title() ?: '-'); ?></div>
                </td>
            </tr>
        </table>

        <!-- TABLA DE PRODUCTOS -->
        <table class="items-table">
            <thead>
                <tr>
                    <th class="text-center" style="width: 8%;">Ítem</th>
                    <th style="width: 54%;">Descripción del Producto</th>
                    <th style="width: 22%;">SKU / Código</th>
                    <th class="text-center" style="width: 16%;">Cantidad</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $num = 1;
                foreach ($order->get_items() as $item): 
                    $product = $item->get_product();
                    $sku = $product ? $product->get_sku() : '-';
                    $qty = (float) $item->get_quantity();
                ?>
                <tr>
                    <td class="text-center"><?php echo sprintf('%02d', $num++); ?></td>
                    <td><strong><?php echo esc_html($item->get_name()); ?></strong></td>
                    <td><?php echo esc_html($sku ?: '-'); ?></td>
                    <td class="text-center"><strong><?php echo number_format($qty, 2, ',', '.') . ' un.'; ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="footer-pinned">

            <?php if ($order->get_customer_note()): ?>
            <div class="section-notes">
                <strong>Observaciones de Entrega:</strong> <?php echo esc_html($order->get_customer_note()); ?>
            </div>
            <?php endif; ?>

            <!-- FIRMAS DE RECEPCIÓN Y CONFORMIDAD -->
            <table class="signatures-table">
                <tr>
                    <td class="signature-cell">
                        <div class="signature-line"></div>
                        <div class="signature-label">Despachado / Embalado por</div>
                        <div class="signature-sub">Control de mercadería en origen</div>
                    </td>
                    <td class="signature-cell">
                        <div class="signature-line"></div>
                        <div class="signature-label">Recibí Conforme</div>
                        <div class="signature-sub">Firma, Aclaración y DNI de recepción</div>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</div>
