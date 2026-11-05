<?php
/**
 * Plantilla HTML de Nota de Crédito A para Dompdf
 * Letra A - COD. 003
 */
defined('ABSPATH') || exit;

$cliente_nombre = $order->get_formatted_billing_full_name() ?: $order->get_formatted_shipping_full_name();
$telefono       = $order->get_billing_phone() ?: '-';
$email          = $order->get_billing_email() ?: '-';
$logo_url       = $company['logo_url'] ?? '';

$neto = $amount / 1.21;
$iva_total = $amount - $neto;
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

        <!-- DATOS DEL CLIENTE (RI) -->
        <table class="client-table">
            <tr>
                <td class="client-col">
                    <div class="meta-line"><strong>Razón Social:</strong> <?php echo esc_html($cliente_nombre); ?></div>
                    <div class="meta-line"><strong>CUIT:</strong> <?php echo esc_html($clean_doc); ?></div>
                    <div class="meta-line"><strong>Condición frente al IVA:</strong> IVA Responsable Inscripto</div>
                    <div class="meta-line"><strong>Método de Pago:</strong> <?php echo esc_html($order->get_payment_method_title() ?: 'Contado'); ?></div>
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

        <!-- COMPROBANTE ASOCIADO (OBLIGATORIO ARCA) -->
        <div style="padding: 6px 12px; background-color: #fff; border-bottom: 1.5px solid #000; font-size: 9.5px;">
            <strong>Comprobante Asociado:</strong> <?php echo esc_html($asoc_tipo_label); ?> &nbsp;|&nbsp; 
            <strong>Pto. Vta:</strong> <?php echo esc_html($asoc_pto_vta); ?> &nbsp;|&nbsp; 
            <strong>N°:</strong> <?php echo esc_html($asoc_cbte_nro); ?>
        </div>

        <!-- TABLA DE DETALLE (CON IVA DISCRIMINADO) -->
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 44%;">Descripción / Concepto</th>
                    <th class="text-center" style="width: 10%;">Cant.</th>
                    <th class="text-right" style="width: 14%;">Precio Neto</th>
                    <th class="text-center" style="width: 10%;">Alíc. IVA</th>
                    <th class="text-right" style="width: 11%;">IVA</th>
                    <th class="text-right" style="width: 11%;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong>Nota de Crédito por Anulación / Ajuste</strong><br>
                        <span style="font-size: 8.5px; color: #555;">Comprobante asociado <?php echo esc_html($asoc_tipo_label); ?> N° <?php echo esc_html("{$asoc_pto_vta}-{$asoc_cbte_nro}"); ?></span>
                    </td>
                    <td class="text-center">1</td>
                    <td class="text-right">$<?php echo number_format($neto, 2, ',', '.'); ?></td>
                    <td class="text-center">21%</td>
                    <td class="text-right">$<?php echo number_format($iva_total, 2, ',', '.'); ?></td>
                    <td class="text-right">$<?php echo number_format($amount, 2, ',', '.'); ?></td>
                </tr>
            </tbody>
        </table>

        <!-- TOTALES DISCRIMINADOS Y AUTORIZACIÓN ARCA -->
        <div class="footer-pinned">
            <table class="totals-table">
                <tr>
                    <td class="totals-col-left">
                        <strong>Comprobante Electrónico Autorizado</strong><br>
                        Operación sujeta a la Ley del Impuesto al Valor Agregado.
                    </td>
                    <td class="totals-col-right">
                        <table class="subtotals-table">
                            <tr>
                                <td><strong>Neto Gravado:</strong></td>
                                <td class="text-right">$<?php echo number_format($neto, 2, ',', '.'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>IVA Débito Fiscal:</strong></td>
                                <td class="text-right">$<?php echo number_format($iva_total, 2, ',', '.'); ?></td>
                            </tr>
                            <tr class="total-highlight">
                                <td style="padding-top: 6px; padding-bottom: 6px;"><strong>TOTAL:</strong></td>
                                <td class="text-right" style="padding-top: 6px; padding-bottom: 6px;"><strong>$<?php echo number_format($amount, 2, ',', '.'); ?></strong></td>
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
                                Comprobante Autorizado por ARCA (Agencia de Recaudación y Control Aduanero). Escanee el código QR para validar la autenticidad fiscal.
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>
