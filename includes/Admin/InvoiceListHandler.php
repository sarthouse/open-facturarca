<?php
namespace WcArca\Admin;

use WcArca\Services\TaxResolver;
use WcArca\Services\CompanyManager;
use WcArca\Invoices\PdfHandler;

defined('ABSPATH') || exit;

class InvoiceListHandler {

    /**
     * Obtiene y pagina los comprobantes emitidos (Facturas y Notas de Crédito)
     */
    public static function get_comprobantes($filter_company = '', $filter_type = '', $search = '', $start_date = '', $end_date = '', $paged = 1, $per_page = 20) {
        // Obtenemos todas las órdenes facturadas con CAE
        $args = [
            'limit'        => -1,
            'status'       => ['completed', 'processing', 'refunded', 'on-hold'],
            'meta_key'     => '_arca_cae',
            'meta_compare' => 'EXISTS',
            'orderby'      => 'date',
            'order'        => 'DESC',
        ];

        if (!empty($start_date)) {
            $args['date_created'] = '>=' . $start_date . ' 00:00:00';
        }
        if (!empty($end_date)) {
            if (isset($args['date_created'])) {
                $args['date_created'] = $start_date . ' 00:00:00...' . $end_date . ' 23:59:59';
            } else {
                $args['date_created'] = '<=' . $end_date . ' 23:59:59';
            }
        }

        $orders = wc_get_orders($args);
        $items = [];

        foreach ($orders as $order) {
            $order_id = $order->get_id();
            $company_id = $order->get_meta('_arca_company_id') ?: 'default';
            $company_name = $order->get_meta('_arca_company_name') ?: '';
            
            $doc_tipo = $order->get_meta('_billing_arca_doc_tipo') ?: ($order->get_meta('_billing_dni') ? '96' : '99');
            $doc_nro  = $order->get_meta('_billing_arca_doc_nro') ?: $order->get_meta('_billing_dni');
            if (empty($doc_nro)) {
                $doc_nro = $order->get_meta('_billing_cuit') ?: '0';
            }
            $customer_name = trim($order->get_formatted_billing_full_name());
            if (empty($customer_name)) {
                $customer_name = $order->get_billing_company() ?: __('Consumidor Final', 'open-facturarca');
            }

            // 1. Factura principal
            $cbte_tipo = (int) $order->get_meta('_arca_cbte_tipo');
            $pto_vta   = (int) $order->get_meta('_arca_pto_vta');
            $cbte_nro  = (int) $order->get_meta('_arca_cbte_nro');
            $cae       = $order->get_meta('_arca_cae');
            $cae_vto   = $order->get_meta('_arca_cae_vto');
            $cbte_fch  = $order->get_meta('_arca_cbte_fch');
            $arca_env  = $order->get_meta('_arca_environment') ?: 'testing';
            $raw_order_date = $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i') : '';
            $total     = (float) $order->get_total();

            // Formatear Fecha de Emisión de la Factura (DD/MM/YYYY)
            $date_fmt = '';
            if (!empty($cbte_fch)) {
                $fch_clean = preg_replace('/[^0-9]/', '', (string)$cbte_fch);
                if (strlen($fch_clean) === 8) {
                    $date_fmt = substr($fch_clean, 6, 2) . '/' . substr($fch_clean, 4, 2) . '/' . substr($fch_clean, 0, 4);
                } else {
                    $date_fmt = date('d/m/Y', strtotime($cbte_fch));
                }
            } elseif ($order->get_date_created()) {
                $date_fmt = $order->get_date_created()->date('d/m/Y');
            }

            // Formatear Fecha de Vencimiento de CAE (DD/MM/YYYY)
            $cae_vto_fmt = '-';
            if (!empty($cae_vto)) {
                $cae_vto_clean = preg_replace('/[^0-9]/', '', (string)$cae_vto);
                if (strlen($cae_vto_clean) === 8) {
                    $cae_vto_fmt = substr($cae_vto_clean, 6, 2) . '/' . substr($cae_vto_clean, 4, 2) . '/' . substr($cae_vto_clean, 0, 4);
                } else {
                    $cae_vto_fmt = date('d/m/Y', strtotime($cae_vto));
                }
            }

            // Cálculo de IVA según tipo de comprobante
            // Monotributo (Factura C / NC C) no discrimina IVA (IVA = $0)
            if ($cbte_tipo === 11) {
                $iva = 0.00;
            } else {
                $iva = (float) $order->get_total_tax();
                if ($iva <= 0 && $total > 0) {
                    $iva = round($total - ($total / 1.21), 2);
                }
            }

            $items[] = [
                'order_id'      => $order_id,
                'company_id'    => $company_id,
                'company_name'  => $company_name,
                'environment'   => $arca_env,
                'is_nc'         => false,
                'nc_index'      => null,
                'cbte_tipo'     => $cbte_tipo,
                'cbte_label'    => TaxResolver::get_cbte_label($cbte_tipo),
                'pto_vta'       => $pto_vta,
                'cbte_nro'      => $cbte_nro,
                'formatted_nro' => sprintf('%04d-%08d', $pto_vta, $cbte_nro),
                'total'         => $total,
                'iva'           => $iva,
                'cae'           => $cae,
                'cae_vto'       => $cae_vto_fmt,
                'doc_nro'       => $doc_nro,
                'customer_name' => $customer_name,
                'date'          => $date_fmt ?: $raw_order_date,
                'raw_date'      => $raw_order_date,
                'download_url'  => PdfHandler::get_download_url($order_id),
            ];

            // 2. Notas de Crédito emitidas
            $credit_notes = $order->get_meta('_arca_credit_notes');
            if (is_array($credit_notes)) {
                foreach ($credit_notes as $nc_idx => $nc) {
                    $nc_cbte_tipo = (int) ($nc['cbte_tipo'] ?? 0);
                    $nc_pto_vta   = (int) ($nc['pto_vta'] ?? $pto_vta);
                    $nc_nro       = (int) ($nc['cbte_nro'] ?? 0);
                    $nc_cae       = $nc['cae'] ?? '';
                    $nc_cae_vto   = $nc['cae_vto'] ?? '';
                    $nc_total     = (float) ($nc['importe'] ?? 0);

                    // Formatear fecha de la Nota de Crédito
                    $nc_date_raw  = !empty($nc['cbte_fch']) ? $nc['cbte_fch'] : (!empty($nc['fecha']) ? $nc['fecha'] : '');
                    $nc_raw_date  = $raw_order_date;
                    if (!empty($nc_date_raw)) {
                        $nc_clean = preg_replace('/[^0-9]/', '', (string)$nc_date_raw);
                        if (strlen($nc_clean) === 8) {
                            $nc_date = substr($nc_clean, 6, 2) . '/' . substr($nc_clean, 4, 2) . '/' . substr($nc_clean, 0, 4);
                            $nc_raw_date = substr($nc_clean, 0, 4) . '-' . substr($nc_clean, 4, 2) . '-' . substr($nc_clean, 6, 2);
                        } else {
                            $nc_date = date('d/m/Y', strtotime($nc_date_raw));
                            $nc_raw_date = date('Y-m-d', strtotime($nc_date_raw));
                        }
                    } else {
                        $nc_date = $date_fmt ?: $raw_order_date;
                    }

                    // Formatear Vto CAE de la Nota de Crédito
                    $nc_cae_vto_fmt = '-';
                    if (!empty($nc_cae_vto)) {
                        $nc_vto_clean = preg_replace('/[^0-9]/', '', (string)$nc_cae_vto);
                        if (strlen($nc_vto_clean) === 8) {
                            $nc_cae_vto_fmt = substr($nc_vto_clean, 6, 2) . '/' . substr($nc_vto_clean, 4, 2) . '/' . substr($nc_vto_clean, 0, 4);
                        } else {
                            $nc_cae_vto_fmt = date('d/m/Y', strtotime($nc_cae_vto));
                        }
                    } else {
                        $nc_cae_vto_fmt = $nc_cae_vto;
                    }

                    // IVA de la Nota de Crédito
                    if ($nc_cbte_tipo === 13) {
                        $nc_iva = 0.00;
                    } else {
                        $nc_neto = round($nc_total / 1.21, 2);
                        $nc_iva  = round($nc_total - $nc_neto, 2);
                    }

                    $items[] = [
                        'order_id'      => $order_id,
                        'company_id'    => $company_id,
                        'company_name'  => $company_name,
                        'environment'   => $nc['environment'] ?? $arca_env,
                        'is_nc'         => true,
                        'nc_index'      => $nc_idx,
                        'cbte_tipo'     => $nc_cbte_tipo,
                        'cbte_label'    => TaxResolver::get_cbte_label($nc_cbte_tipo),
                        'pto_vta'       => $nc_pto_vta,
                        'cbte_nro'      => $nc_nro,
                        'formatted_nro' => sprintf('%04d-%08d', $nc_pto_vta, $nc_nro),
                        'total'         => $nc_total,
                        'iva'           => $nc_iva,
                        'cae'           => $nc_cae,
                        'cae_vto'       => $nc_cae_vto_fmt,
                        'doc_nro'       => $doc_nro,
                        'customer_name' => $customer_name,
                        'date'          => $nc_date,
                        'raw_date'      => $nc_raw_date,
                        'download_url'  => PdfHandler::get_credit_note_download_url($order_id, $nc_idx),
                    ];
                }
            }
        }

        // Ordenar por fecha descendente
        usort($items, function($a, $b) {
            return strcmp($b['raw_date'], $a['raw_date']);
        });

        // Filtrar por fecha exacta de NC si la NC tiene fecha distinta al pedido
        if (!empty($start_date)) {
            $items = array_filter($items, function($it) use ($start_date) {
                return substr($it['raw_date'], 0, 10) >= $start_date;
            });
        }
        if (!empty($end_date)) {
            $items = array_filter($items, function($it) use ($end_date) {
                return substr($it['raw_date'], 0, 10) <= $end_date;
            });
        }

        // Aplicar filtros
        if (!empty($filter_company)) {
            $items = array_filter($items, function($it) use ($filter_company) {
                return $it['company_id'] === $filter_company;
            });
        }

        if (!empty($filter_type)) {
            $items = array_filter($items, function($it) use ($filter_type) {
                if ($filter_type === 'invoices') {
                    return !$it['is_nc'];
                } elseif ($filter_type === 'credit_notes') {
                    return $it['is_nc'];
                } elseif (is_numeric($filter_type)) {
                    return $it['cbte_tipo'] === (int)$filter_type;
                }
                return true;
            });
        }

        if (!empty($search)) {
            $search_clean = mb_strtolower(trim($search));
            $items = array_filter($items, function($it) use ($search_clean) {
                $haystack = mb_strtolower(
                    $it['order_id'] . ' ' .
                    $it['formatted_nro'] . ' ' .
                    $it['customer_name'] . ' ' .
                    $it['doc_nro'] . ' ' .
                    $it['cae'] . ' ' .
                    $it['company_name']
                );
                return strpos($haystack, $search_clean) !== false;
            });
        }

        $total_items = count($items);
        $total_pages = ceil($total_items / $per_page);
        $offset = ($paged - 1) * $per_page;
        $paged_items = array_slice($items, $offset, $per_page);

        return [
            'items'       => $paged_items,
            'total_items' => $total_items,
            'total_pages' => $total_pages,
            'current_page'=> $paged,
        ];
    }

    /**
     * Renderiza la tabla y los filtros en la vista de administración
     */
    public static function render_view() {
        $filter_company = isset($_GET['filter_company']) ? sanitize_text_field($_GET['filter_company']) : '';
        $filter_type    = isset($_GET['filter_type']) ? sanitize_text_field($_GET['filter_type']) : '';
        $start_date     = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
        $end_date       = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';
        $search         = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $paged          = isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;

        $companies = CompanyManager::get_companies();
        $data = self::get_comprobantes($filter_company, $filter_type, $search, $start_date, $end_date, $paged, 20);

        ?>
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h2 style="margin: 0; font-size: 18px; font-weight: 600;">Comprobantes Electrónicos Emitidos</h2>
                    <p style="margin: 4px 0 0; color: #666; font-size: 13px;">Listado consolidado de Facturas y Notas de Crédito autorizadas por ARCA / AFIP.</p>
                </div>
                <div>
                    <span style="font-weight: 600; font-size: 13px; color: #444; background: #f0f0f1; padding: 6px 12px; border-radius: 4px; border: 1px solid #dcdcde;">
                        Total registrados: <strong><?php echo esc_html($data['total_items']); ?></strong>
                    </span>
                </div>
            </div>

            <!-- Barra de Filtros y Búsqueda -->
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="background: #f9f9f9; padding: 12px 15px; border: 1px solid #e2e4e7; border-radius: 4px; margin-bottom: 20px;">
                <input type="hidden" name="page" value="open-facturarca-invoices">
                <input type="hidden" name="section" value="invoices">

                <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                    <!-- Búsqueda -->
                    <div style="flex: 1; min-width: 220px;">
                        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Buscar por Orden #, Nro Factura, Cliente, CUIT/DNI, CAE..." style="width: 100%; height: 32px;">
                    </div>

                    <!-- Filtro Fechas -->
                    <div style="display: flex; align-items: center; gap: 5px;">
                        <label style="font-size: 12px; color: #555;">Desde:</label>
                        <input type="date" name="start_date" value="<?php echo esc_attr($start_date); ?>" style="height: 32px; padding: 0 6px;">
                    </div>
                    <div style="display: flex; align-items: center; gap: 5px;">
                        <label style="font-size: 12px; color: #555;">Hasta:</label>
                        <input type="date" name="end_date" value="<?php echo esc_attr($end_date); ?>" style="height: 32px; padding: 0 6px;">
                    </div>

                    <!-- Filtro Empresa -->
                    <?php if (count($companies) > 1): ?>
                        <div>
                            <select name="filter_company" style="height: 32px;">
                                <option value="">Todas las Empresas</option>
                                <?php foreach ($companies as $id => $c): ?>
                                    <option value="<?php echo esc_attr($id); ?>" <?php selected($filter_company, $id); ?>>
                                        <?php echo esc_html($c['name']); ?> (<?php echo esc_html($c['cuit']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <!-- Filtro Tipo de Comprobante -->
                    <div>
                        <select name="filter_type" style="height: 32px;">
                            <option value="">Todos los Tipos</option>
                            <option value="invoices" <?php selected($filter_type, 'invoices'); ?>>Solo Facturas (A, B, C)</option>
                            <option value="credit_notes" <?php selected($filter_type, 'credit_notes'); ?>>Solo Notas de Crédito</option>
                            <option value="1" <?php selected($filter_type, '1'); ?>>Factura A</option>
                            <option value="6" <?php selected($filter_type, '6'); ?>>Factura B</option>
                            <option value="11" <?php selected($filter_type, '11'); ?>>Factura C</option>
                            <option value="3" <?php selected($filter_type, '3'); ?>>Nota de Crédito A</option>
                            <option value="8" <?php selected($filter_type, '8'); ?>>Nota de Crédito B</option>
                            <option value="13" <?php selected($filter_type, '13'); ?>>Nota de Crédito C</option>
                        </select>
                    </div>

                    <button type="submit" class="button button-primary">Filtrar</button>
                    
                    <?php if (!empty($search) || !empty($start_date) || !empty($end_date) || !empty($filter_company) || !empty($filter_type)): ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=open-facturarca-invoices&section=invoices')); ?>" class="button">Restablecer</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Tabla de Comprobantes -->
            <table class="wp-list-table widefat fixed striped table-view-list" style="border: 1px solid #ccd0d4;">
                <thead>
                    <tr>
                        <th scope="col" style="width: 105px;">Fecha</th>
                        <th scope="col" style="width: 140px;">Comprobante</th>
                        <th scope="col" style="width: 120px;">Número</th>
                        <th scope="col" style="width: 80px;">Pedido</th>
                        <th scope="col">Cliente / Receptor</th>
                        <th scope="col" style="width: 110px;">CUIT / DNI</th>
                        <th scope="col" style="width: 95px; text-align: right;">IVA</th>
                        <th scope="col" style="width: 105px; text-align: right;">Total</th>
                        <th scope="col" style="width: 140px;">CAE / Vto</th>
                        <th scope="col" style="width: 95px; text-align: center;">Entorno</th>
                        <th scope="col" style="width: 105px; text-align: center;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($data['items'])): ?>
                        <tr>
                            <td colspan="11" style="text-align: center; padding: 25px; color: #666;">
                                No se encontraron comprobantes emitidos con los criterios especificados.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($data['items'] as $item): 
                            $order_edit_url = admin_url('admin.php?page=wc-orders&action=edit&id=' . $item['order_id']);
                            $is_nc = $item['is_nc'];
                            $badge_bg = $is_nc ? '#ffebee' : '#e8f5e9';
                            $badge_color = $is_nc ? '#c62828' : '#2e7d32';
                            $badge_border = $is_nc ? '#ef9a9a' : '#a5d6a7';
                            $is_prod = ($item['environment'] ?? 'testing') === 'production';
                        ?>
                            <tr>
                                <td style="font-size: 12px; color: #555;">
                                    <?php echo esc_html($item['date']); ?>
                                </td>
                                <td>
                                    <span style="display: inline-block; padding: 2px 7px; border-radius: 3px; font-weight: 600; font-size: 11px; background: <?php echo $badge_bg; ?>; color: <?php echo $badge_color; ?>; border: 1px solid <?php echo $badge_border; ?>;">
                                        <?php echo esc_html($item['cbte_label']); ?>
                                    </span>
                                    <?php if (!empty($item['company_name'])): ?>
                                        <div style="font-size: 10.5px; color: #777; margin-top: 2px;">
                                            <?php echo esc_html($item['company_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="font-weight: 600; font-size: 12.5px;">
                                    <?php echo esc_html($item['formatted_nro']); ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url($order_edit_url); ?>" style="font-weight: 600;">
                                        #<?php echo esc_html($item['order_id']); ?>
                                    </a>
                                </td>
                                <td style="font-size: 12.5px;">
                                    <strong><?php echo esc_html($item['customer_name']); ?></strong>
                                </td>
                                <td style="font-size: 12px; color: #444;">
                                    <?php echo esc_html($item['doc_nro']); ?>
                                </td>
                                <td style="text-align: right; font-size: 12.5px; color: <?php echo $is_nc ? '#c62828' : '#555'; ?>;">
                                    <?php echo ($is_nc && $item['iva'] > 0 ? '-' : '') . '$' . number_format($item['iva'], 2, ',', '.'); ?>
                                </td>
                                <td style="text-align: right; font-weight: 600; font-size: 13px; color: <?php echo $is_nc ? '#c62828' : '#111'; ?>;">
                                    <?php echo ($is_nc ? '-' : '') . '$' . number_format($item['total'], 2, ',', '.'); ?>
                                </td>
                                <td style="font-size: 11px; line-height: 1.3;">
                                    <strong style="color: #2e7d32;">CAE:</strong> <?php echo esc_html($item['cae']); ?><br>
                                    <span style="color: #666;">Vto: <?php echo esc_html($item['cae_vto']); ?></span>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($is_prod): ?>
                                        <span style="display: inline-block; padding: 2px 6px; border-radius: 3px; font-weight: 600; font-size: 10px; background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7;">
                                            PRODUCCIÓN
                                        </span>
                                    <?php else: ?>
                                        <span style="display: inline-block; padding: 2px 6px; border-radius: 3px; font-weight: 600; font-size: 10px; background: #fff3e0; color: #e65100; border: 1px solid #ffe082;">
                                            PRUEBA
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <a href="<?php echo esc_url($item['download_url']); ?>" class="button button-small" target="_blank" title="Descargar PDF">
                                        Descargar PDF
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Paginación -->
            <?php if ($data['total_pages'] > 1): ?>
                <div class="tablenav" style="margin-top: 15px;">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo esc_html($data['total_items']); ?> comprobantes</span>
                        <?php
                        $page_links = paginate_links([
                            'base'      => add_query_arg('paged', '%#%'),
                            'format'    => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total'     => $data['total_pages'],
                            'current'   => $data['current_page'],
                        ]);
                        if ($page_links) {
                            echo '<span class="pagination-links">' . $page_links . '</span>';
                        }
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
