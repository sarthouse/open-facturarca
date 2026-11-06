<?php
namespace WcArca\Admin;

use WcArca\Orders\InvoiceTrigger;
use WcArca\Invoices\PdfHandler;
use WcArca\Services\TaxResolver;
use WcArca\Services\CompanyManager;

defined('ABSPATH') || exit;

class BulkActions {
    public static function init() {
        // Registrar acciones en lote en WooCommerce (soporte clásico y HPOS)
        add_filter('bulk_actions-edit-shop_order', [__CLASS__, 'register_bulk_actions']);
        add_filter('bulk_actions-woocommerce_page_wc-orders', [__CLASS__, 'register_bulk_actions']);

        // Procesar acciones en lote
        add_filter('handle_bulk_actions-edit-shop_order', [__CLASS__, 'handle_bulk_actions'], 10, 3);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [__CLASS__, 'handle_bulk_actions'], 10, 3);

        // Notificaciones admin tras ejecución
        add_action('admin_notices', [__CLASS__, 'render_bulk_notices']);
        add_action('init', [__CLASS__, 'handle_zip_download']);
    }

    public static function register_bulk_actions($actions) {
        $companies = CompanyManager::get_companies();

        if (count($companies) <= 1) {
            $actions['arca_bulk_issue'] = __('Facturar en ARCA (Lote)', 'open-facturarca');
        } else {
            foreach ($companies as $id => $comp) {
                $name = !empty($comp['name']) ? $comp['name'] : (!empty($comp['trade_name']) ? $comp['trade_name'] : $id);
                $actions['arca_bulk_issue_' . $id] = sprintf(__('Facturar en ARCA: %s (Lote)', 'open-facturarca'), $name);
            }
        }

        $actions['arca_bulk_export'] = __('Exportar Facturas a ZIP (Lote)', 'open-facturarca');
        return $actions;
    }

    public static function handle_bulk_actions($redirect_to, $action, $order_ids) {
        // Detectar si la acción es de facturación masiva (general o específica por empresa)
        $is_bulk_issue = false;
        $target_company_id = '';

        if ($action === 'arca_bulk_issue') {
            $is_bulk_issue = true;
            $target_company_id = ''; // Utiliza la empresa por defecto
        } elseif (strpos($action, 'arca_bulk_issue_') === 0) {
            $is_bulk_issue = true;
            $target_company_id = substr($action, strlen('arca_bulk_issue_'));
        }

        if ($is_bulk_issue) {
            // Aumentar tiempo de ejecución para lotes grandes
            if (function_exists('set_time_limit')) {
                @set_time_limit(300);
            }

            $issued = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($order_ids as $order_id) {
                $order = wc_get_order($order_id);
                if (!$order) continue;

                // Omitir si ya tiene factura emitida
                if ($order->get_meta('_arca_cae')) {
                    $skipped++;
                    continue;
                }

                $res = InvoiceTrigger::process_order_invoicing($order_id, $target_company_id);
                if ($res['success']) {
                    $issued++;
                } else {
                    $errors++;
                }

                // Liberar memoria periódicamente
                if (function_exists('wp_cache_flush_runtime')) {
                    wp_cache_flush_runtime();
                }
            }

            return add_query_arg([
                'arca_bulk_processed' => 1,
                'arca_issued'         => $issued,
                'arca_skipped'        => $skipped,
                'arca_errors'         => $errors,
            ], $redirect_to);
        }

        if ($action === 'arca_bulk_export') {
            // Guardar IDs en un transient y redirigir a la descarga directa del ZIP
            $token = wp_generate_password(16, false);
            set_transient('wc_arca_zip_' . $token, $order_ids, HOUR_IN_SECONDS);

            $download_url = add_query_arg([
                'wc_arca_action' => 'download_invoices_zip',
                'zip_token'      => $token,
            ], admin_url('admin-post.php'));

            wp_redirect($download_url);
            exit;
        }

        return $redirect_to;
    }

    public static function handle_zip_download() {
        if (!isset($_GET['wc_arca_action']) || $_GET['wc_arca_action'] !== 'download_invoices_zip') {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('No tienes permisos suficientes.', 'open-facturarca'), 403);
        }

        $token = sanitize_text_field($_GET['zip_token'] ?? '');
        $order_ids = get_transient('wc_arca_zip_' . $token);

        if (!$order_ids || !is_array($order_ids)) {
            wp_die(__('La sesión de descarga del ZIP ha expirado.', 'open-facturarca'), 400);
        }

        delete_transient('wc_arca_zip_' . $token);

        if (!class_exists('ZipArchive')) {
            wp_die(__('La extensión PHP ZipArchive no está habilitada en su servidor.', 'open-facturarca'));
        }

        // Aumentar tiempo de ejecución y memoria para generación masiva de PDFs en ZIP
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        $zip = new \ZipArchive();
        $zip_file = tempnam(sys_get_temp_dir(), 'arca_zip_');

        if ($zip->open($zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            wp_die(__('No se pudo crear el archivo ZIP temporal.', 'open-facturarca'));
        }

        $added_count = 0;

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order || !$order->get_meta('_arca_cae')) {
                continue;
            }

            $cbte_tipo = (int)$order->get_meta('_arca_cbte_tipo');
            $pto_vta   = (int)$order->get_meta('_arca_pto_vta');
            $cbte_nro  = (int)$order->get_meta('_arca_cbte_nro');

            $letra = ($cbte_tipo === 1 || $cbte_tipo === 2 || $cbte_tipo === 3) ? 'A' : (($cbte_tipo === 6 || $cbte_tipo === 7 || $cbte_tipo === 8) ? 'B' : 'C');
            $tipo_nombre = ($cbte_tipo === 3 || $cbte_tipo === 8 || $cbte_tipo === 13) ? 'NC' : 'Factura';

            // Archivo PDF real dentro del ZIP
            $filename = sprintf('%s_%s_%04d-%08d_Pedido_%d.pdf', $tipo_nombre, $letra, $pto_vta, $cbte_nro, $order->get_id());

            // Generar el archivo binario PDF real
            $pdf_content = PdfHandler::build_invoice_pdf($order);

            $zip->addFromString($filename, $pdf_content);
            $added_count++;

            // Liberar memoria de Dompdf y WC_Order
            if (function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime();
            }
        }

        $zip->close();

        if ($added_count === 0) {
            if (file_exists($zip_file)) unlink($zip_file);
            wp_die(__('Ninguno de los pedidos seleccionados tiene una factura emitida para exportar.', 'open-facturarca'));
        }

        // Nombre del ZIP de descarga: Facturas_ARCA_2026-11-04_1234.zip
        $zip_download_name = 'Facturas_ARCA_' . date('Y-m-d_His') . '.zip';

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_download_name . '"');
        header('Content-Length: ' . filesize($zip_file));
        readfile($zip_file);
        unlink($zip_file);
        exit;
    }

    public static function render_bulk_notices() {
        if (!empty($_GET['arca_bulk_processed'])) {
            $issued  = intval($_GET['arca_issued'] ?? 0);
            $skipped = intval($_GET['arca_skipped'] ?? 0);
            $errors  = intval($_GET['arca_errors'] ?? 0);

            $msg = sprintf(
                __('Procesamiento ARCA completado: %d facturas emitidas, %d omitidas (ya facturadas), %d con error.', 'open-facturarca'),
                $issued,
                $skipped,
                $errors
            );
            echo '<div class="notice notice-success is-dismissible"><p><strong>Open FacturARCA:</strong> ' . esc_html($msg) . '</p></div>';
            ?>
            <script>
            (function() {
                if (window.history && window.history.replaceState) {
                    var cleanUrl = new URL(window.location.href);
                    if (cleanUrl.searchParams.has('arca_bulk_processed')) {
                        cleanUrl.searchParams.delete('arca_bulk_processed');
                        cleanUrl.searchParams.delete('arca_issued');
                        cleanUrl.searchParams.delete('arca_skipped');
                        cleanUrl.searchParams.delete('arca_errors');
                        window.history.replaceState({}, document.title, cleanUrl.toString());
                    }
                }
            })();
            </script>
            <?php
        }
    }
}

