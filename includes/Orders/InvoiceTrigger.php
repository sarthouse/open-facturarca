<?php
namespace WcArca\Orders;

use WcArca\Services\TaxResolver;
use WcArca\Services\WsfeClient;
use WcArca\Services\CompanyManager;

defined('ABSPATH') || exit;

class InvoiceTrigger {
    public static function init() {
        add_action('woocommerce_order_status_changed', [__CLASS__, 'on_order_status_changed'], 20, 3);
        add_action('woocommerce_order_refunded', [__CLASS__, 'on_order_refunded'], 20, 2);
    }

    public static function on_order_status_changed($order_id, $from_status, $to_status) {
        $auto_issue = get_option('wc_arca_auto_issue', 'yes');
        if ($auto_issue !== 'yes') {
            return;
        }

        $trigger_status = get_option('wc_arca_trigger_status', 'completed');
        if ($to_status === $trigger_status) {
            self::process_order_invoicing($order_id);
        }
    }

    public static function on_order_refunded($order_id, $refund_id) {
        $auto_nc = get_option('wc_arca_auto_credit_note', 'yes');
        if ($auto_nc !== 'yes') {
            return;
        }

        $refund = wc_get_refund($refund_id);
        if (!$refund) return;

        $refund_amount = abs((float)$refund->get_amount());
        if ($refund_amount <= 0) return;

        self::process_credit_note($order_id, $refund_amount, $refund_id);
    }

    public static function process_order_invoicing($order_id, $company_id = '') {
        $order = wc_get_order($order_id);
        if (!$order) {
            return ['success' => false, 'message' => 'Pedido no encontrado.'];
        }

        if ($order->get_meta('_arca_cae')) {
            return ['success' => false, 'message' => 'El pedido ya cuenta con una factura emitida (CAE existente).'];
        }

        if ($order->get_meta('_arca_is_processing')) {
            return ['success' => false, 'message' => 'El pedido ya está en proceso de facturación.'];
        }

        $order->update_meta_data('_arca_is_processing', 'yes');
        $order->save();

        try {
            // Resolver empresa (elegida manualmente o la default)
            $company = $company_id ? CompanyManager::get_company($company_id) : null;
            if (!$company) {
                $company = CompanyManager::get_default_company();
            }

            $issuer_condition   = $company['tax_condition'] ?? 'monotributo';
            $receiver_condition = $order->get_meta('_billing_tax_condition') ?: 'consumidor_final';

            $doc_nro = $order->get_meta('_billing_dni') 
                ?: ($order->get_meta('billing_dni') ?: ($order->get_meta('_billing_doc_number') ?: ''));
            $clean_doc = preg_replace('/[^0-9]/', '', (string)$doc_nro);

            // Determinar Tipo de Documento y aplicar Fallback a DocTipo 99 (Sin Identificar)
            $total = (float) $order->get_total();

            if (empty($clean_doc) || $clean_doc === '0') {
                if ($receiver_condition === 'responsable_inscripto') {
                    throw new \Exception(__('Para emitir Factura A es obligatorio ingresar un CUIT válido del cliente.', 'open-facturarca'));
                }

                // Tope legal de ARCA para ventas a Consumidor Final sin identificar (Tope actualizado por IPC)
                $max_anonymous_limit = 350000;
                if ($total >= $max_anonymous_limit) {
                    throw new \Exception(sprintf(__('Para montos superiores a $%s, ARCA exige identificar al cliente con su DNI o CUIT.', 'open-facturarca'), number_format($max_anonymous_limit, 2, ',', '.')));
                }

                // Fallback oficial ARCA: Tipo 99 (Sin identificar / Venta Global Diaria)
                $doc_tipo  = 99;
                $clean_doc = '0';
            } else {
                $doc_tipo  = (strlen($clean_doc) === 11) ? 80 : 96;
            }

            $cbte_tipo = TaxResolver::resolve_cbte_tipo($issuer_condition, $receiver_condition, 'invoice');

            $iva_total = (float) $order->get_total_tax();

            $iva_array = [];
            if ($cbte_tipo === TaxResolver::CBTE_FACTURA_C) {
                // Monotributo no discrimina IVA; el total facturado es el total final
                $neto = $total;
            } else {
                // Responsable Inscripto (Facturas A y B)
                // En ARCA WSFE, tanto Factura A como Factura B exigen informar ImpNeto e ImpIVA en el payload
                if ($iva_total > 0) {
                    $neto = round($total - $iva_total, 2);
                    $iva_calc = round($iva_total, 2);
                } else {
                    // Si WooCommerce no tiene habilitado el desglose de impuestos, calculamos la alícuota estándar 21%
                    $neto = round($total / 1.21, 2);
                    $iva_calc = round($total - $neto, 2);
                }

                if ($iva_calc > 0) {
                    $iva_array[] = [
                        'Id'      => 5, // 21% estándar en tabla alícuotas AFIP
                        'BaseImp' => round($neto, 2),
                        'Importe' => round($iva_calc, 2),
                    ];
                } else {
                    $neto = $total;
                }
            }

            $cond_iva_receptor_id = TaxResolver::get_receiver_iva_id($receiver_condition);

            $client = new WsfeClient($company);
            $result = $client->authorize_invoice($cbte_tipo, $doc_tipo, $clean_doc, $total, $neto, $iva_array, [], $cond_iva_receptor_id);

            $order->update_meta_data('_arca_cae', $result['cae']);
            $order->update_meta_data('_arca_cae_vto', $result['cae_vto']);
            $order->update_meta_data('_arca_cbte_nro', $result['cbte_nro']);
            $order->update_meta_data('_arca_pto_vta', $result['pto_vta']);
            $order->update_meta_data('_arca_cbte_tipo', $result['cbte_tipo']);
            $order->update_meta_data('_arca_cbte_fch', $result['cbte_fch']);
            $order->update_meta_data('_arca_company_id', $company['id']);
            $order->update_meta_data('_arca_company_name', $company['name']);
            $order->update_meta_data('_arca_company_cuit', $company['cuit']);
            $order->update_meta_data('_arca_environment', $company['environment'] ?? 'testing');

            $cbte_label = TaxResolver::get_cbte_label($cbte_tipo);
            $formatted_nro = sprintf('%05d-%08d', (int) $result['pto_vta'], (int) $result['cbte_nro']);

            $order->add_order_note(
                sprintf(
                    __('Comprobante emitido exitosamente en ARCA: %s N° %s | CAE: %s (Vto: %s)', 'open-facturarca'),
                    $cbte_label,
                    $formatted_nro,
                    $result['cae'],
                    $result['cae_vto']
                )
            );

            $order->delete_meta_data('_arca_is_processing');
            $order->save();

            return ['success' => true, 'message' => "Factura {$formatted_nro} emitida con éxito."];

        } catch (\Exception $e) {
            $order->delete_meta_data('_arca_is_processing');
            $order->save();

            $order->add_order_note(
                __('Error al intentar emitir comprobante en ARCA: ', 'wc-factura-arca') . $e->getMessage()
            );

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public static function process_credit_note($order_id, $amount, $refund_id = null, $reason = '') {
        $order = wc_get_order($order_id);
        if (!$order) {
            return ['success' => false, 'message' => 'Pedido no encontrado.'];
        }

        $factura_cae = $order->get_meta('_arca_cae');
        if (!$factura_cae) {
            return ['success' => false, 'message' => 'No se puede emitir una Nota de Crédito porque el pedido no tiene una Factura emitida previa.'];
        }

        $amount = round((float)$amount, 2);
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'El importe de la Nota de Crédito debe ser mayor a cero.'];
        }

        try {
            // Utilizar la misma empresa con la que se emitió la factura original
            $company_id = $order->get_meta('_arca_company_id');
            $company = $company_id ? CompanyManager::get_company($company_id) : CompanyManager::get_default_company();

            $issuer_condition   = $company['tax_condition'] ?? 'monotributo';
            $receiver_condition = $order->get_meta('_billing_tax_condition') ?: 'consumidor_final';

            $doc_nro = $order->get_meta('_billing_dni') 
                ?: ($order->get_meta('billing_dni') ?: ($order->get_meta('_billing_doc_number') ?: '0'));
            $clean_doc = preg_replace('/[^0-9]/', '', (string)$doc_nro);
            if (empty($clean_doc) || $clean_doc === '0') {
                $doc_tipo  = 99;
                $clean_doc = '0';
            } else {
                $doc_tipo  = (strlen($clean_doc) === 11) ? 80 : 96;
            }

            $cbte_tipo = TaxResolver::resolve_cbte_tipo($issuer_condition, $receiver_condition, 'credit_note');

            $orig_cbte_tipo = (int)$order->get_meta('_arca_cbte_tipo');
            $orig_pto_vta   = (int)$order->get_meta('_arca_pto_vta');
            $orig_cbte_nro  = (int)$order->get_meta('_arca_cbte_nro');
            $orig_cbte_fch  = $order->get_meta('_arca_cbte_fch') ?: date('Ymd');

            $cbtes_asoc = [
                'Tipo'    => $orig_cbte_tipo,
                'PtoVta'  => $orig_pto_vta,
                'Nro'     => $orig_cbte_nro,
                'Cuit'    => (float) $company['cuit'],
                'CbteFch' => $orig_cbte_fch,
            ];

            $total_factura = (float)$order->get_total();
            $neto = 0;
            $iva_array = [];

            if ($cbte_tipo !== TaxResolver::CBTE_NOTA_CREDITO_C) {
                // Responsable Inscripto (Nota de Crédito A y B)
                $iva_order = (float)$order->get_total_tax();
                if ($iva_order > 0 && $total_factura > 0) {
                    $ratio = $amount / $total_factura;
                    $neto  = round(((float)$order->get_subtotal()) * $ratio, 2);
                    $iva   = round($amount - $neto, 2);
                } else {
                    // Si WC no tiene impuestos habilitados, desglosamos 21%
                    $neto = round($amount / 1.21, 2);
                    $iva  = round($amount - $neto, 2);
                }

                if ($iva > 0) {
                    $iva_array[] = [
                        'Id'      => 5,
                        'BaseImp' => $neto,
                        'Importe' => $iva,
                    ];
                } else {
                    $neto = $amount;
                }
            }

            $cond_iva_receptor_id = TaxResolver::get_receiver_iva_id($receiver_condition);

            $client = new WsfeClient($company);
            $result = $client->authorize_invoice($cbte_tipo, $doc_tipo, $clean_doc, $amount, $neto, $iva_array, $cbtes_asoc, $cond_iva_receptor_id);

            $existing_ncs = $order->get_meta('_arca_credit_notes') ?: [];
            $nc_entry = [
                'cae'       => $result['cae'],
                'cae_vto'   => $result['cae_vto'],
                'cbte_nro'  => $result['cbte_nro'],
                'pto_vta'   => $result['pto_vta'],
                'cbte_tipo' => $result['cbte_tipo'],
                'cbte_fch'  => $result['cbte_fch'],
                'importe'   => $amount,
                'refund_id' => $refund_id,
                'reason'    => $reason,
                'environment' => $company['environment'] ?? 'testing',
            ];
            $existing_ncs[] = $nc_entry;
            $order->update_meta_data('_arca_credit_notes', $existing_ncs);

            $label = TaxResolver::get_cbte_label($cbte_tipo);
            $formatted_nro = sprintf('%05d-%08d', (int) $result['pto_vta'], (int) $result['cbte_nro']);

            $order->add_order_note(
                sprintf(
                    __('[%s] %s emitida en ARCA por $%s: N° %s | CAE: %s', 'wc-factura-arca'),
                    $company['name'],
                    $label,
                    number_format($amount, 2, ',', '.'),
                    $formatted_nro,
                    $result['cae']
                )
            );
            $order->save();

            return ['success' => true, 'message' => "{$label} N° {$formatted_nro} emitida por \${$amount}."];

        } catch (\Exception $e) {
            $order->add_order_note(
                __('Error al intentar emitir Nota de Crédito en ARCA: ', 'wc-factura-arca') . $e->getMessage()
            );
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
