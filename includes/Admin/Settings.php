<?php
namespace WcArca\Admin;

use WcArca\Services\CompanyManager;
use WcArca\Services\CertGenerator;

defined('ABSPATH') || exit;

class Settings {
    public static function init() {
        add_filter('woocommerce_settings_tabs_array', [__CLASS__, 'add_settings_tab'], 50);
        add_action('woocommerce_settings_tabs_arca_invoicing', [__CLASS__, 'render_settings_tab']);
        add_action('woocommerce_update_options_arca_invoicing', [__CLASS__, 'save_settings']);

        // Handlers para acciones de certificados y empresas
        add_action('admin_post_wc_arca_generate_cert_request', [__CLASS__, 'handle_generate_csr']);
        add_action('admin_post_wc_arca_save_company', [__CLASS__, 'handle_save_company']);
        add_action('admin_post_wc_arca_delete_company', [__CLASS__, 'handle_delete_company']);
        add_action('admin_post_wc_arca_upload_certificate', [__CLASS__, 'handle_upload_certificate']);
    }

    public static function add_settings_tab($settings_tabs) {
        $settings_tabs['arca_invoicing'] = __('Open FacturARCA', 'open-facturarca');
        return $settings_tabs;
    }

    public static function render_settings_tab() {
        $current_section = isset($_GET['section']) ? sanitize_text_field($_GET['section']) : 'companies';
        $logo_url = OPEN_FACTURARCA_URL . 'assets/logo.svg';
        ?>
        <div style="margin: 15px 0 25px;">
            <img src="<?php echo esc_url($logo_url); ?>" alt="Open FacturARCA" style="height: 52px; width: auto; display: block;">
        </div>
        <nav class="nav-tab-wrapper woo-nav-tab-wrapper" style="margin-bottom: 20px;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=arca_invoicing&section=companies')); ?>" class="nav-tab <?php echo $current_section === 'companies' ? 'nav-tab-active' : ''; ?>">
                🏢 Empresas Emisoras (Multi-empresa)
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=arca_invoicing&section=cert_tool')); ?>" class="nav-tab <?php echo $current_section === 'cert_tool' ? 'nav-tab-active' : ''; ?>">
                🔑 Generador de Clave y CSR
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=arca_invoicing&section=automation')); ?>" class="nav-tab <?php echo $current_section === 'automation' ? 'nav-tab-active' : ''; ?>">
                ⚙️ Ajustes de Automatización
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=arca_invoicing&section=reports')); ?>" class="nav-tab <?php echo $current_section === 'reports' ? 'nav-tab-active' : ''; ?>">
                📊 Estadísticas y Libros Contables
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=arca_invoicing&section=guide')); ?>" class="nav-tab <?php echo $current_section === 'guide' ? 'nav-tab-active' : ''; ?>">
                📖 Tutorial ARCA / AFIP
            </a>
        </nav>
        <?php

        if ($current_section === 'companies') {
            self::render_companies_view();
        } elseif ($current_section === 'cert_tool') {
            self::render_cert_tool_view();
        } elseif ($current_section === 'reports') {
            self::render_reports_view();
        } elseif ($current_section === 'guide') {
            self::render_tutorial_view();
        } else {
            woocommerce_admin_fields(self::get_automation_settings());
        }
    }

    public static function get_automation_settings() {
        $companies = CompanyManager::get_companies();
        $company_options = [];
        foreach ($companies as $id => $c) {
            $company_options[$id] = $c['name'] . ' (CUIT: ' . $c['cuit'] . ')';
        }

        return [
            [
                'title' => __('Automatización de Comprobantes (Facturas y Notas de Crédito)', 'wc-factura-arca'),
                'type'  => 'title',
                'id'    => 'wc_arca_section_automation',
            ],
            [
                'title'   => __('Empresa Emisora por Defecto', 'wc-factura-arca'),
                'desc'    => __('Empresa utilizada para la emisión automática de pedidos.', 'wc-factura-arca'),
                'id'      => 'wc_arca_default_company_id',
                'type'    => 'select',
                'default' => 'default',
                'options' => $company_options,
            ],
            [
                'title'   => __('Facturación Automática', 'wc-factura-arca'),
                'desc'    => __('Emitir factura de forma automática al cambiar el estado del pedido.', 'wc-factura-arca'),
                'id'      => 'wc_arca_auto_issue',
                'type'    => 'checkbox',
                'default' => 'yes',
            ],
            [
                'title'   => __('Estado disparador de Factura', 'wc-factura-arca'),
                'desc'    => __('Estado de WooCommerce que dispara la emisión automática de la factura.', 'wc-factura-arca'),
                'id'      => 'wc_arca_trigger_status',
                'type'    => 'select',
                'default' => 'completed',
                'options' => [
                    'completed'  => __('Completado (Completed)', 'wc-factura-arca'),
                    'processing' => __('Procesando (Processing)', 'wc-factura-arca'),
                ],
            ],
            [
                'title'   => __('Nota de Crédito Automática en Reembolsos', 'open-facturarca'),
                'desc'    => __('Emitir automáticamente una Nota de Crédito por el monto exacto devuelto al crear un reembolso en WooCommerce.', 'open-facturarca'),
                'id'      => 'wc_arca_auto_credit_note',
                'type'    => 'checkbox',
                'default' => 'yes',
            ],
            [
                'type' => 'sectionend',
                'id'   => 'wc_arca_section_automation',
            ],
            [
                'title' => __('Configuración de Impresión (Copias: Original, Duplicado, Triplicado)', 'open-facturarca'),
                'type'  => 'title',
                'desc'  => __('Defina cuántas copias desea que salgan preparadas al imprimir Facturas, Notas de Crédito y Remitos.', 'open-facturarca'),
                'id'    => 'wc_arca_section_print_copies',
            ],
            [
                'title'   => __('Copias de Factura / Nota de Crédito', 'open-facturarca'),
                'desc'    => __('Define los ejemplares a generar al imprimir comprobantes fiscales.', 'open-facturarca'),
                'id'      => 'wc_arca_invoice_copies',
                'type'    => 'select',
                'default' => 'original',
                'options' => [
                    'original'   => __('Solo Original (1 página)', 'open-facturarca'),
                    'duplicado'  => __('Original y Duplicado (2 páginas)', 'open-facturarca'),
                    'triplicado' => __('Original, Duplicado y Triplicado (3 páginas)', 'open-facturarca'),
                ],
            ],
            [
                'title'   => __('Copias del Remito de Entrega', 'open-facturarca'),
                'desc'    => __('Define los ejemplares del remito (ej. Original para el cliente, Duplicado conformado para archivo).', 'open-facturarca'),
                'id'      => 'wc_arca_slip_copies',
                'type'    => 'select',
                'default' => 'duplicado',
                'options' => [
                    'original'   => __('Solo Original (1 página)', 'open-facturarca'),
                    'duplicado'  => __('Original y Duplicado (Recomendado para firma)', 'open-facturarca'),
                    'triplicado' => __('Original, Duplicado y Triplicado (Transportista/Archivo)', 'open-facturarca'),
                ],
            ],
            [
                'type' => 'sectionend',
                'id'   => 'wc_arca_section_print_copies',
            ],
        ];
    }

    public static function save_settings() {
        $current_section = isset($_GET['section']) ? sanitize_text_field($_GET['section']) : 'companies';
        if ($current_section === 'automation') {
            woocommerce_update_options(self::get_automation_settings());
        }
    }

    /**
     * VISTA: Estadísticas y Reportes Contables (Libro IVA e IIBB)
     */
    private static function render_reports_view() {
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-m-01');
        $end_date   = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-d');
        $analytics  = ReportsHandler::get_analytics($start_date, $end_date);
        ?>
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 20px;">
            <h3 style="margin-top: 0;">Filtro de Período</h3>
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display: flex; gap: 15px; align-items: flex-end;">
                <input type="hidden" name="page" value="wc-settings">
                <input type="hidden" name="tab" value="arca_invoicing">
                <input type="hidden" name="section" value="reports">

                <div>
                    <label style="font-weight: bold; display: block; margin-bottom: 4px;">Desde:</label>
                    <input type="date" name="start_date" value="<?php echo esc_attr($start_date); ?>">
                </div>
                <div>
                    <label style="font-weight: bold; display: block; margin-bottom: 4px;">Hasta:</label>
                    <input type="date" name="end_date" value="<?php echo esc_attr($end_date); ?>">
                </div>
                <div>
                    <button type="submit" class="button button-primary">Filtrar Métricas</button>
                </div>
            </form>
        </div>

        <!-- Tarjetas de Métricas -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 25px;">
            <div style="background: #fff; padding: 15px; border-radius: 4px; border: 1px solid #ccd0d4; border-left: 4px solid #2271b1;">
                <span style="font-size: 12px; color: #666; text-transform: uppercase;">Total Facturado</span>
                <h2 style="margin: 5px 0 0; color: #111;">$<?php echo number_format($analytics['total_invoiced'], 2, ',', '.'); ?></h2>
            </div>
            <div style="background: #fff; padding: 15px; border-radius: 4px; border: 1px solid #ccd0d4; border-left: 4px solid #2e7d32;">
                <span style="font-size: 12px; color: #666; text-transform: uppercase;">Neto Gravado</span>
                <h2 style="margin: 5px 0 0; color: #2e7d32;">$<?php echo number_format($analytics['total_neto'], 2, ',', '.'); ?></h2>
            </div>
            <div style="background: #fff; padding: 15px; border-radius: 4px; border: 1px solid #ccd0d4; border-left: 4px solid #ed6c02;">
                <span style="font-size: 12px; color: #666; text-transform: uppercase;">IVA Débito Fiscal</span>
                <h2 style="margin: 5px 0 0; color: #ed6c02;">$<?php echo number_format($analytics['total_iva'], 2, ',', '.'); ?></h2>
            </div>
            <div style="background: #fff; padding: 15px; border-radius: 4px; border: 1px solid #ccd0d4; border-left: 4px solid #7b1fa2;">
                <span style="font-size: 12px; color: #666; text-transform: uppercase;">Comprobantes Emitidos</span>
                <h2 style="margin: 5px 0 0; color: #7b1fa2;"><?php echo $analytics['orders_count']; ?></h2>
            </div>
        </div>

        <!-- Botones de Exportación para el Contador -->
        <div style="background: #f6f7f7; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 25px;">
            <h3 style="margin-top: 0; color: #1d2327;">📥 Archivos Auxiliares para el Contador / Estudio Contable</h3>
            <p style="color: #50575e; font-size: 13px;">Descargue los libros y planillas en formato CSV listos para abrir en Excel o importar en aplicativos de liquidación de impuestos.</p>
            
            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wc_arca_export_libro_iva">
                    <input type="hidden" name="start_date" value="<?php echo esc_attr($start_date); ?>">
                    <input type="hidden" name="end_date" value="<?php echo esc_attr($end_date); ?>">
                    <?php wp_nonce_field('wc_arca_export_reports_nonce'); ?>
                    <button type="submit" class="button button-primary">
                        📑 Descargar Libro IVA Ventas (CSV)
                    </button>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wc_arca_export_iibb_provincias">
                    <input type="hidden" name="start_date" value="<?php echo esc_attr($start_date); ?>">
                    <input type="hidden" name="end_date" value="<?php echo esc_attr($end_date); ?>">
                    <?php wp_nonce_field('wc_arca_export_reports_nonce'); ?>
                    <button type="submit" class="button">
                        🗺️ Descargar Coeficientes IIBB / Provincias (CSV)
                    </button>
                </form>
            </div>
        </div>

        <!-- Tabla de Facturación por Jurisdicción / Provincia -->
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
            <h3 style="margin-top: 0;">Desglose por Jurisdicción (Convenio Multilateral / Ingresos Brutos)</h3>
            <p style="font-size: 13px; color: #666;">Utilice esta planilla para el cálculo de coeficientes unificados en la declaración jurada anual (CM05) o mensual.</p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Provincia / Jurisdicción</th>
                        <th style="text-align: center;">Operaciones</th>
                        <th style="text-align: right;">Total Facturado</th>
                        <th style="text-align: right;">% Coeficiente de Ingresos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $gran_total = $analytics['total_invoiced'] > 0 ? $analytics['total_invoiced'] : 1;
                    if (empty($analytics['by_province'])): ?>
                        <tr><td colspan="5">No hay comprobantes emitidos en el rango seleccionado.</td></tr>
                    <?php else: 
                        foreach ($analytics['by_province'] as $code => $prov): 
                            $coef = ($prov['total'] / $gran_total) * 100;
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($code); ?></strong></td>
                            <td><?php echo esc_html($prov['name']); ?></td>
                            <td style="text-align: center;"><?php echo esc_html($prov['count']); ?></td>
                            <td style="text-align: right;">$<?php echo number_format($prov['total'], 2, ',', '.'); ?></td>
                            <td style="text-align: right; font-weight: bold;"><?php echo number_format($coef, 2, ',', '.'); ?>%</td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * VISTA: Gestión de Empresas (Multi-empresa)
     */
    private static function render_companies_view() {
        $companies = CompanyManager::get_companies();
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
        $edit_id = isset($_GET['company_id']) ? sanitize_text_field($_GET['company_id']) : '';
        $company_to_edit = $edit_id ? CompanyManager::get_company($edit_id) : null;

        if ($action === 'new' || $company_to_edit):
            ?>
            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 800px;">
                <h3><?php echo $company_to_edit ? 'Editar Empresa Emisora' : 'Dar de Alta Nueva Empresa'; ?></h3>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wc_arca_save_company">
                    <input type="hidden" name="company_id" value="<?php echo esc_attr($edit_id); ?>">
                    <?php wp_nonce_field('wc_arca_save_company_nonce'); ?>

                    <table class="form-table">
                        <tr>
                            <th><label>Nombre / Razón Social</label></th>
                            <td><input type="text" name="name" value="<?php echo esc_attr($company_to_edit['name'] ?? ''); ?>" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label>CUIT</label></th>
                            <td><input type="text" name="cuit" value="<?php echo esc_attr($company_to_edit['cuit'] ?? ''); ?>" class="regular-text" placeholder="20XXXXXXXXX (sin guiones)" required></td>
                        </tr>
                        <tr>
                            <th><label>Condición Fiscal</label></th>
                            <td>
                                <select name="tax_condition">
                                    <option value="monotributo" <?php selected($company_to_edit['tax_condition'] ?? '', 'monotributo'); ?>>Monotributo (Factura C)</option>
                                    <option value="responsable_inscripto" <?php selected($company_to_edit['tax_condition'] ?? '', 'responsable_inscripto'); ?>>Responsable Inscripto (Facturas A y B)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Número de Ingresos Brutos (IIBB)</label></th>
                            <td><input type="text" name="iibb" value="<?php echo esc_attr($company_to_edit['iibb'] ?? ''); ?>" class="regular-text" placeholder="Ej: 20-12345678-9 o Convenio Multilateral"></td>
                        </tr>
                        <tr>
                            <th><label>Fecha de Inicio de Actividades</label></th>
                            <td><input type="date" name="activity_init" value="<?php echo esc_attr($company_to_edit['activity_init'] ?? ''); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label>Punto de Venta Web Services</label></th>
                            <td><input type="number" name="pto_vta" value="<?php echo esc_attr($company_to_edit['pto_vta'] ?? '1'); ?>" class="small-text" required></td>
                        </tr>
                        <tr>
                            <th><label>Entorno ARCA</label></th>
                            <td>
                                <select name="environment">
                                    <option value="testing" <?php selected($company_to_edit['environment'] ?? '', 'testing'); ?>>Homologación (Testing / Pruebas)</option>
                                    <option value="production" <?php selected($company_to_edit['environment'] ?? '', 'production'); ?>>Producción</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Ruta Certificado (.crt)</label></th>
                            <td>
                                <input type="text" name="cert_path" value="<?php echo esc_attr($company_to_edit['cert_path'] ?? ''); ?>" class="large-text">
                                <p class="description">Ruta absoluta del archivo descargado de ARCA (ej: <code><?php echo WC_ARCA_PLUGIN_DIR . 'certs/' . ($company_to_edit['cuit'] ?? 'empresa') . '.crt'; ?></code>).</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Ruta Clave Privada (.key)</label></th>
                            <td>
                                <input type="text" name="key_path" value="<?php echo esc_attr($company_to_edit['key_path'] ?? ''); ?>" class="large-text">
                                <p class="description">Ruta absoluta de la clave privada generada.</p>
                            </td>
                        </tr>
                    </table>

                    <p>
                        <button type="submit" class="button button-primary">Guardar Empresa</button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=arca_invoicing&section=companies')); ?>" class="button">Cancelar</a>
                    </p>
                </form>
            </div>
            <?php
            return;
        endif;

        // Listado de empresas
        ?>
        <p style="margin-bottom: 15px;">
            <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=arca_invoicing&section=companies&action=new')); ?>" class="button button-primary">
                ➕ Añadir Nueva Empresa
            </a>
        </p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Empresa / Razón Social</th>
                    <th>CUIT</th>
                    <th>Condición</th>
                    <th>Pto. Venta</th>
                    <th>Entorno</th>
                    <th>Estado Certificados</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($companies as $id => $c): 
                    $cert_ok = !empty($c['cert_path']) && file_exists($c['cert_path']);
                    $key_ok  = !empty($c['key_path']) && file_exists($c['key_path']);
                ?>
                <tr>
                    <td><strong><?php echo esc_html($c['name']); ?></strong></td>
                    <td><?php echo esc_html($c['cuit'] ?: '-'); ?></td>
                    <td><?php echo $c['tax_condition'] === 'monotributo' ? 'Monotributo' : 'Resp. Inscripto'; ?></td>
                    <td><?php echo esc_html(sprintf('%04d', $c['pto_vta'])); ?></td>
                    <td>
                        <span class="badge" style="background: <?php echo $c['environment'] === 'production' ? '#2e7d32' : '#ed6c02'; ?>; color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 11px;">
                            <?php echo strtoupper($c['environment']); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($cert_ok && $key_ok): ?>
                            <span style="color: #2e7d32; font-weight: bold;">✔ Configurados</span>
                        <?php else: ?>
                            <span style="color: #c62828;">⚠ Faltan certificados</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=arca_invoicing&section=companies&company_id=' . $id)); ?>" class="button button-small">Editar</a>
                        <?php if (count($companies) > 1): 
                            $del_url = wp_nonce_url(admin_url('admin-post.php?action=wc_arca_delete_company&company_id=' . $id), 'wc_arca_del_company_' . $id);
                        ?>
                            <a href="<?php echo esc_url($del_url); ?>" class="button button-small" style="color: #c62828;" onclick="return confirm('¿Eliminar esta empresa emisora?');">Eliminar</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * VISTA: Generador nativo de Clave (.key) y Solicitud (.csr)
     */
    private static function render_cert_tool_view() {
        ?>
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 800px;">
            <h3>🔑 Generador Automático de Clave Privada y CSR</h3>
            <p>Este asistente genera directamente la clave criptográfica y la solicitud de certificado (CSR) para subir al portal de ARCA/AFIP sin usar comandos de terminal.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wc_arca_generate_cert_request">
                <?php wp_nonce_field('wc_arca_gen_cert_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th><label>Razón Social o Nombre</label></th>
                        <td><input type="text" name="company_name" value="<?php echo esc_attr(get_bloginfo('name')); ?>" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label>CUIT del Emisor</label></th>
                        <td><input type="text" name="cuit" class="regular-text" placeholder="20XXXXXXXXX (11 dígitos sin guiones)" required></td>
                    </tr>
                </table>

                <p>
                    <button type="submit" class="button button-primary">⚡ Generar Clave y CSR Ahora</button>
                </p>
            </form>

            <hr style="margin: 25px 0;">

            <h3>📤 Subir Certificado (.crt) obtenido de ARCA</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="wc_arca_upload_certificate">
                <?php wp_nonce_field('wc_arca_upload_cert_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th><label>Seleccionar Archivo .crt</label></th>
                        <td><input type="file" name="cert_file" accept=".crt,.cer,.pem" required></td>
                    </tr>
                    <tr>
                        <th><label>Asignar a Empresa</label></th>
                        <td>
                            <select name="company_id">
                                <?php foreach (CompanyManager::get_companies() as $id => $c): ?>
                                    <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($c['name']); ?> (<?php echo esc_html($c['cuit']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="submit" class="button">Subir y Vincular Certificado</button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * VISTA: Tutorial detallado
     */
    private static function render_tutorial_view() {
        ?>
        <div style="background: #fff; padding: 25px; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 900px; font-size: 14px; line-height: 1.6;">
            <h2 style="margin-top: 0;">📖 Proceso Completo de Generación de Certificados en ARCA / AFIP</h2>
            
            <p>Para emitir facturas legales en Argentina, ARCA exige que cada sistema esté autenticado mediante un <strong>Certificado Digital X.509</strong>. El procedimiento consta de 4 pasos:</p>

            <ol style="padding-left: 20px;">
                <li style="margin-bottom: 15px;">
                    <strong>Paso 1: Generar la Clave Privada y el CSR desde el Plugin</strong><br>
                    Ve a la pestaña <strong>"Generador de Clave y CSR"</strong> arriba. Ingresa el CUIT y nombre de tu empresa y presiona "Generar". 
                    El plugin creará y guardará la <code>.key</code> de forma segura y te descargará el archivo <code>pedido.csr</code> a tu computadora.
                </li>
                <li style="margin-bottom: 15px;">
                    <strong>Paso 2: Subir el CSR al Portal de ARCA</strong><br>
                    Ingresa a <a href="https://auth.afip.gob.ar" target="_blank">afip.gob.ar</a> con Clave Fiscal (Nivel 3).
                    <ul>
                        <li>Para <strong>Producción:</strong> Entra al servicio <em>"Administración de Certificados Digitales"</em>. Agrega un nuevo alias (ej. "MiTienda"), sube el archivo <code>.csr</code> y descarga el certificado resultante <code>.crt</code>.</li>
                        <li>Para <strong>Homologación (Testing):</strong> Entra al servicio <em>"WSASS - Autogestión Certificados Homologación"</em>, sube el CSR y descarga el certificado de pruebas.</li>
                    </ul>
                </li>
                <li style="margin-bottom: 15px;">
                    <strong>Paso 3: Autorizar el Servicio de Facturación Electrónica (wsfe)</strong><br>
                    En el portal de AFIP, entra en <em>"Administrador de Relaciones de Clave Fiscal"</em>:
                    <br>Selecciona <strong>"Nueva Relación" > Buscar Servicio > ARCA/AFIP > WebServices > Facturación Electrónica</strong>.
                    <br>En el campo "Representante", selecciona el Alias/Certificado que diste de alta en el Paso 2.
                </li>
                <li style="margin-bottom: 15px;">
                    <strong>Paso 4: Crear el Punto de Venta</strong><br>
                    Entra al servicio <em>"Regímenes de Facturación y Registración (REAR/RECE/RFI)"</em> en AFIP.
                    <br>Ve a <strong>"A/B/M de Puntos de Venta"</strong> y da de alta un punto (ej: Punto de Venta 2) eligiendo el sistema <strong>"Factura Electrónica - Web Services"</strong>.
                </li>
            </ol>

            <div style="background: #e7f3fe; border-left: 4px solid #2271b1; padding: 12px; margin-top: 20px;">
                <strong>Una vez completados estos pasos:</strong> Vuelve a la pestaña <em>"Generador de Clave y CSR"</em>, sube el archivo <code>.crt</code> descargado y ¡tu tienda estará lista para facturar!
            </div>
        </div>
        <?php
    }

    /**
     * Handlers POST
     */
    public static function handle_save_company() {
        if (!current_user_can('manage_woocommerce')) wp_die('No autorizado');
        check_admin_referer('wc_arca_save_company_nonce');

        $data = [
            'id'            => sanitize_key($_POST['company_id'] ?? ''),
            'name'          => sanitize_text_field($_POST['name'] ?? ''),
            'cuit'          => sanitize_text_field($_POST['cuit'] ?? ''),
            'tax_condition' => sanitize_text_field($_POST['tax_condition'] ?? 'monotributo'),
            'iibb'          => sanitize_text_field($_POST['iibb'] ?? ''),
            'activity_init' => sanitize_text_field($_POST['activity_init'] ?? ''),
            'pto_vta'       => (int) ($_POST['pto_vta'] ?? 1),
            'environment'   => sanitize_text_field($_POST['environment'] ?? 'testing'),
            'cert_path'     => sanitize_text_field($_POST['cert_path'] ?? ''),
            'key_path'      => sanitize_text_field($_POST['key_path'] ?? ''),
        ];

        CompanyManager::save_company($data);
        wp_redirect(admin_url('admin.php?page=wc-settings&tab=arca_invoicing&section=companies'));
        exit;
    }

    public static function handle_delete_company() {
        $id = sanitize_key($_GET['company_id'] ?? '');
        check_admin_referer('wc_arca_del_company_' . $id);
        if (!current_user_can('manage_woocommerce')) wp_die('No autorizado');

        CompanyManager::delete_company($id);
        wp_redirect(admin_url('admin.php?page=wc-settings&tab=arca_invoicing&section=companies'));
        exit;
    }

    public static function handle_generate_csr() {
        if (!current_user_can('manage_woocommerce')) wp_die('No autorizado');
        check_admin_referer('wc_arca_gen_cert_nonce');

        $company_name = sanitize_text_field($_POST['company_name'] ?? '');
        $cuit         = preg_replace('/[^0-9]/', '', sanitize_text_field($_POST['cuit'] ?? ''));

        if (strlen($cuit) !== 11) {
            wp_die('El CUIT debe tener 11 dígitos.');
        }

        try {
            $pair = CertGenerator::generate_key_and_csr($company_name, $cuit);

            // Guardar clave privada en directorio protegido certs/
            $key_file = WC_ARCA_PLUGIN_DIR . "certs/{$cuit}.key";
            file_put_contents($key_file, $pair['private_key']);

            // Entregar el CSR para descarga en el navegador
            header('Content-Type: application/pkcs10');
            header('Content-Disposition: attachment; filename="pedido_' . $cuit . '.csr"');
            echo $pair['csr'];
            exit;

        } catch (\Exception $e) {
            wp_die('Error: ' . $e->getMessage());
        }
    }

    public static function handle_upload_certificate() {
        if (!current_user_can('manage_woocommerce')) wp_die('No autorizado');
        check_admin_referer('wc_arca_upload_cert_nonce');

        $company_id = sanitize_key($_POST['company_id'] ?? '');
        $company = CompanyManager::get_company($company_id);
        if (!$company) wp_die('Empresa no encontrada.');

        if (!empty($_FILES['cert_file']['tmp_name'])) {
            $uploaded_name = sanitize_file_name($_FILES['cert_file']['name']);
            $ext = strtolower(pathinfo($uploaded_name, PATHINFO_EXTENSION));

            // Validar estrictamente la extensión permitida
            if (!in_array($ext, ['crt', 'cer', 'pem'], true)) {
                wp_die(__('Tipo de archivo no permitido. Solo se aceptan certificados .crt, .cer o .pem.', 'wc-factura-arca'), 400);
            }

            // Validar que el archivo contenga realmente un certificado X.509 legible por OpenSSL
            $content = file_get_contents($_FILES['cert_file']['tmp_name']);
            $parsed_cert = @openssl_x509_read($content);
            if ($parsed_cert === false) {
                wp_die(__('El archivo subido no es un certificado X.509 válido.', 'wc-factura-arca'), 400);
            }

            $target_file = WC_ARCA_PLUGIN_DIR . "certs/{$company['cuit']}.crt";
            move_uploaded_file($_FILES['cert_file']['tmp_name'], $target_file);

            $company['cert_path'] = $target_file;
            $company['key_path']  = WC_ARCA_PLUGIN_DIR . "certs/{$company['cuit']}.key";
            CompanyManager::save_company($company);
        }

        wp_redirect(admin_url('admin.php?page=wc-settings&tab=arca_invoicing&section=companies'));
        exit;
    }
}
