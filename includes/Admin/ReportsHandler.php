<?php
namespace WcArca\Admin;

use WcArca\Services\TaxResolver;

defined('ABSPATH') || exit;

class ReportsHandler {
    public static function init() {
        add_action('admin_post_wc_arca_export_libro_iva', [__CLASS__, 'export_libro_iva_csv']);
        add_action('admin_post_wc_arca_export_iibb_provincias', [__CLASS__, 'export_iibb_provincias_csv']);
    }

    /**
     * Obtiene los pedidos facturados dentro de un rango de fechas y opcionalmente por empresa
     */
    public static function get_invoiced_orders($start_date = '', $end_date = '', $company_id = '') {
        $args = [
            'limit'      => -1,
            'status'     => ['completed', 'processing', 'refunded'],
            'meta_key'   => '_arca_cae',
            'meta_compare' => 'EXISTS',
        ];

        if ($start_date) {
            $args['date_created'] = '>=' . $start_date . ' 00:00:00';
        }
        if ($end_date) {
            $args['date_created'] = '<=' . $end_date . ' 23:59:59';
        }

        $orders = wc_get_orders($args);

        if (!empty($company_id)) {
            $orders = array_filter($orders, function($order) use ($company_id) {
                $order_company = $order->get_meta('_arca_company_id');
                // Compatibilidad con pedidos emitidos antes del soporte multi-empresa
                if (empty($order_company)) {
                    $order_company = 'default';
                }
                return $order_company === $company_id;
            });
        }

        return $orders;
    }

    /**
     * Calcula métricas y estadísticas de facturación
     */
    public static function get_analytics($start_date = '', $end_date = '', $company_id = '') {
        $orders = self::get_invoiced_orders($start_date, $end_date, $company_id);

        $total_invoiced = 0;
        $total_neto = 0;
        $total_iva = 0;
        $count_by_type = [
            'Factura A' => 0,
            'Factura B' => 0,
            'Factura C' => 0,
            'Notas de Crédito' => 0,
        ];
        $by_province = [];

        foreach ($orders as $order) {
            $total = (float)$order->get_total();
            $neto  = (float)$order->get_subtotal();
            $iva   = (float)$order->get_total_tax();
            $tipo  = (int)$order->get_meta('_arca_cbte_tipo');

            $total_invoiced += $total;
            $total_neto     += $neto;
            $total_iva      += $iva;

            if ($tipo === 1) $count_by_type['Factura A']++;
            elseif ($tipo === 6) $count_by_type['Factura B']++;
            elseif ($tipo === 11) $count_by_type['Factura C']++;

            // Descontar Notas de Crédito emitidas para este pedido
            $total_nc_order = 0;
            $credit_notes = $order->get_meta('_arca_credit_notes') ?: [];
            foreach ($credit_notes as $nc) {
                $nc_importe = (float)($nc['importe'] ?? 0);
                $nc_neto    = ($tipo === 11) ? $nc_importe : round($nc_importe / 1.21, 2);
                $nc_iva     = $nc_importe - $nc_neto;

                $total_invoiced -= $nc_importe;
                $total_neto     -= $nc_neto;
                $total_iva      -= $nc_iva;
                $total_nc_order += $nc_importe;
                $count_by_type['Notas de Crédito']++;
            }

            // Mapeo por Provincia (billing_state) para Coeficientes de Convenio Multilateral / IIBB
            $state_code = $order->get_billing_state() ?: 'CF';
            $state_name = WC()->countries->get_states('AR')[$state_code] ?? $state_code;

            if (!isset($by_province[$state_code])) {
                $by_province[$state_code] = [
                    'code'   => $state_code,
                    'name'   => $state_name,
                    'total'  => 0,
                    'count'  => 0,
                ];
            }
            // Facturación neta atribuible a la jurisdicción (Factura menos Notas de Crédito)
            $by_province[$state_code]['total'] += ($total - $total_nc_order);
            $by_province[$state_code]['count']++;
        }

        // Ordenar provincias por mayor volumen de facturación
        uasort($by_province, function($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        return [
            'total_invoiced' => $total_invoiced,
            'total_neto'     => $total_neto,
            'total_iva'      => $total_iva,
            'orders_count'   => count($orders),
            'count_by_type'  => $count_by_type,
            'by_province'    => $by_province,
        ];
    }

    /**
     * Exporta el Libro IVA Ventas en CSV (compatible con Excel y software contable)
     */
    public static function export_libro_iva_csv() {
        if (!current_user_can('manage_woocommerce')) wp_die('No autorizado');
        check_admin_referer('wc_arca_export_reports_nonce');

        $start_date = sanitize_text_field($_POST['start_date'] ?? '');
        $end_date   = sanitize_text_field($_POST['end_date'] ?? '');
        $company_id = sanitize_key($_POST['company_id'] ?? '');
        $orders     = self::get_invoiced_orders($start_date, $end_date, $company_id);

        $suffix = $company_id ? "_{$company_id}" : '';
        $filename = 'Libro_IVA_Ventas' . $suffix . '_' . ($start_date ?: 'inicio') . '_al_' . ($end_date ?: date('Y-m-d')) . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        // BOM UTF-8 para que Excel en español reconozca caracteres y tildes correctamente
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

        // Encabezados estándar de Libro IVA Ventas
        fputcsv($out, [
            'Fecha',
            'Tipo Comprobante',
            'Punto de Venta',
            'Número Desde',
            'Número Hasta',
            'Doc Tipo',
            'Número Documento',
            'Denominación Comprador',
            'Condición IVA',
            'Importe Total',
            'Imp. Total No Gravado',
            'Imp. Op. Exentas',
            'Neto Gravado',
            'Alícuota IVA',
            'Impuesto Liquidado',
            'CAE',
            'Vto. CAE',
            'ID Pedido WC',
        ], ';');

        foreach ($orders as $order) {
            $fch = $order->get_meta('_arca_cbte_fch') ?: ($order->get_date_created() ? $order->get_date_created()->date('Ymd') : date('Ymd'));
            $fch_fmt = substr($fch, 6, 2) . '/' . substr($fch, 4, 2) . '/' . substr($fch, 0, 4);

            $cbte_tipo = (int)$order->get_meta('_arca_cbte_tipo');
            $tipo_label = TaxResolver::get_cbte_label($cbte_tipo);
            $pto_vta   = sprintf('%04d', $order->get_meta('_arca_pto_vta'));
            $cbte_nro  = sprintf('%08d', $order->get_meta('_arca_cbte_nro'));

            $doc_tipo  = $order->get_meta('_billing_doc_type') ?: '96';
            $doc_nro   = $order->get_meta('_billing_dni') ?: ($order->get_meta('billing_dni') ?: '-');
            $nombre    = $order->get_formatted_billing_full_name();
            $cond_iva  = ucfirst(str_replace('_', ' ', $order->get_meta('_billing_tax_condition') ?: 'Consumidor Final'));

            $total = (float)$order->get_total();
            $neto  = (float)$order->get_subtotal();
            $iva   = (float)$order->get_total_tax();

            fputcsv($out, [
                $fch_fmt,
                $tipo_label,
                $pto_vta,
                $cbte_nro,
                $cbte_nro,
                $doc_tipo == 80 ? '80 (CUIT)' : '96 (DNI)',
                $doc_nro,
                $nombre,
                $cond_iva,
                number_format($total, 2, ',', ''),
                '0,00',
                '0,00',
                number_format($neto, 2, ',', ''),
                ($cbte_tipo === 11 || $cbte_tipo === 13) ? '0%' : '21%',
                number_format($iva, 2, ',', ''),
                $order->get_meta('_arca_cae'),
                $order->get_meta('_arca_cae_vto'),
                $order->get_id(),
            ], ';');

            // Exportar filas de Notas de Crédito emitidas para este pedido en el Libro IVA Ventas
            $credit_notes = $order->get_meta('_arca_credit_notes') ?: [];
            foreach ($credit_notes as $nc) {
                $nc_fch = (string)($nc['cbte_fch'] ?? '');
                $nc_fch_fmt = strlen($nc_fch) === 8 
                    ? substr($nc_fch, 6, 2) . '/' . substr($nc_fch, 4, 2) . '/' . substr($nc_fch, 0, 4) 
                    : (!empty($nc_fch) ? date('d/m/Y', strtotime($nc_fch)) : $fch_fmt);

                $nc_tipo = (int)($nc['cbte_tipo'] ?? 0);
                $nc_label = TaxResolver::get_cbte_label($nc_tipo);
                $nc_pto_vta = sprintf('%04d', $nc['pto_vta'] ?? 0);
                $nc_nro = sprintf('%08d', $nc['cbte_nro'] ?? 0);

                $nc_total = (float)($nc['importe'] ?? 0);
                $nc_neto  = ($cbte_tipo === 11 || $nc_tipo === 13) ? $nc_total : round($nc_total / 1.21, 2);
                $nc_iva   = round($nc_total - $nc_neto, 2);

                // En el Libro IVA Ventas, las Notas de Crédito se declaran restando (números en negativo)
                fputcsv($out, [
                    $nc_fch_fmt,
                    $nc_label,
                    $nc_pto_vta,
                    $nc_nro,
                    $nc_nro,
                    $doc_tipo == 80 ? '80 (CUIT)' : '96 (DNI)',
                    $doc_nro,
                    $nombre,
                    $cond_iva,
                    '-' . number_format($nc_total, 2, ',', ''),
                    '0,00',
                    '0,00',
                    '-' . number_format($nc_neto, 2, ',', ''),
                    ($cbte_tipo === 11 || $nc_tipo === 13) ? '0%' : '21%',
                    '-' . number_format($nc_iva, 2, ',', ''),
                    $nc['cae'] ?? '-',
                    $nc['cae_vto'] ?? '-',
                    $order->get_id(),
                ], ';');
            }
        }

        fclose($out);
        exit;
    }

    /**
     * Exporta el desglose por Jurisdicción / Provincia para Convenio Multilateral e IIBB (Coeficientes)
     */
    public static function export_iibb_provincias_csv() {
        if (!current_user_can('manage_woocommerce')) wp_die('No autorizado');
        check_admin_referer('wc_arca_export_reports_nonce');

        $start_date = sanitize_text_field($_POST['start_date'] ?? '');
        $end_date   = sanitize_text_field($_POST['end_date'] ?? '');
        $company_id = sanitize_key($_POST['company_id'] ?? '');
        $analytics  = self::get_analytics($start_date, $end_date, $company_id);

        $suffix = $company_id ? "_{$company_id}" : '';
        $filename = 'Coeficientes_IIBB_Provincias' . $suffix . '_' . ($start_date ?: 'inicio') . '_al_' . ($end_date ?: date('Y-m-d')) . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

        fputcsv($out, [
            'Código Provincia',
            'Jurisdicción / Provincia',
            'Cantidad Operaciones',
            'Facturación Total ($)',
            'Coeficiente Ingresos (% del Total)',
        ], ';');

        $gran_total = $analytics['total_invoiced'] > 0 ? $analytics['total_invoiced'] : 1;

        foreach ($analytics['by_province'] as $code => $prov) {
            $coeficiente = ($prov['total'] / $gran_total) * 100;
            fputcsv($out, [
                $code,
                $prov['name'],
                $prov['count'],
                number_format($prov['total'], 2, ',', ''),
                number_format($coeficiente, 4, ',', '') . '%',
            ], ';');
        }

        // Fila de Total
        fputcsv($out, [
            'TOTAL',
            'Todas las Jurisdicciones',
            $analytics['orders_count'],
            number_format($analytics['total_invoiced'], 2, ',', ''),
            '100,0000%',
        ], ';');

        fclose($out);
        exit;
    }
}

