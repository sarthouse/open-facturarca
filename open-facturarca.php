<?php
/**
 * Plugin Name: Open FacturARCA – Facturación Electrónica ARCA/AFIP para WooCommerce
 * Plugin URI:  https://github.com/sarthouse/open-facturarca
 * Description: Solución de código abierto para emitir Facturas A, B, C y Notas de Crédito automáticas o manuales en ARCA (AFIP) con soporte Multi-empresa.
 * Version:     1.0.0
 * Author:      Tiago Gabriel Sarthou
 * Author URI:  https://github.com/sarthouse/
 * License:     GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: open-facturarca
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

defined('ABSPATH') || exit;

define('OPEN_FACTURARCA_VERSION', '1.0.0');
define('OPEN_FACTURARCA_FILE', __FILE__);
define('OPEN_FACTURARCA_DIR', plugin_dir_path(__FILE__));
define('OPEN_FACTURARCA_URL', plugin_dir_url(__FILE__));

// Comprobar requerimientos antes de inicializar
add_action('plugins_loaded', 'open_facturarca_init_plugin');

// Estilos nativos de Dashicons para los botones de acción en la tabla de pedidos
add_action('admin_head', 'open_facturarca_admin_order_buttons_css');

function open_facturarca_admin_order_buttons_css() {
    $screen = get_current_screen();
    if (!$screen || ($screen->id !== 'edit-shop_order' && $screen->id !== 'woocommerce_page_wc-orders')) {
        return;
    }
    ?>
    <style type="text/css">
        .wc-action-button-arca-invoice::after {
            font-family: dashicons !important;
            content: "\f498" !important; /* Dashicon Documento / Archivo */
            color: #2271b1 !important;
        }
        .wc-action-button-arca-slip::after {
            font-family: dashicons !important;
            content: "\f480" !important; /* Dashicon Archivo / Caja despacho */
            color: #6c757d !important;
        }
        .wc-action-button-arca-sales::after {
            font-family: dashicons !important;
            content: "\f175" !important; /* Dashicon Formulario / Lista de venta */
            color: #135e96 !important;
        }
        .wc-action-button-arca-invoice:hover::after,
        .wc-action-button-arca-slip:hover::after,
        .wc-action-button-arca-sales:hover::after {
            color: #135e96 !important;
        }
    </style>
    <?php
}

// Añadir enlaces útiles en la lista de plugins (Ajustes y Ver Documentación)
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'open_facturarca_action_links');
add_filter('plugin_row_meta', 'open_facturarca_row_meta', 10, 2);

function open_facturarca_action_links($links) {
    $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=open-facturarca')) . '">' . __('Ajustes', 'open-facturarca') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

function open_facturarca_row_meta($links, $file) {
    if ($file === plugin_basename(__FILE__)) {
        $links[] = '<a href="https://github.com/sarthouse/open-facturarca" target="_blank">' . __('Ver en GitHub', 'open-facturarca') . '</a>';
    }
    return $links;
}

function open_facturarca_init_plugin() {
    $missing_requirements = [];

    if (!class_exists('WooCommerce')) {
        $missing_requirements[] = 'WooCommerce debe estar instalado y activo.';
    }

    if (!extension_loaded('openssl')) {
        $missing_requirements[] = 'La extensión PHP <strong>OpenSSL</strong> no está habilitada en su servidor (requerida para firmar comprobantes con ARCA).';
    }

    if (!extension_loaded('soap')) {
        $missing_requirements[] = 'La extensión PHP <strong>SOAP</strong> no está habilitada en su servidor (requerida para comunicarse con los Web Services de ARCA).';
    }

    if (!empty($missing_requirements)) {
        add_action('admin_notices', function() use ($missing_requirements) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Open FacturARCA:</strong> No se puede iniciar debido a los siguientes requisitos faltantes:</p><ul>';
            foreach ($missing_requirements as $req) {
                echo '<li>• ' . $req . '</li>';
            }
            echo '</ul></div>';
        });
        return;
    }

    // Autoloader PSR-4 para el namespace WcArca
    spl_autoload_register('open_facturarca_autoloader');

    // Inicializar plugin
    WcArca\Plugin::instance()->init();
}

function open_facturarca_autoloader($class) {
    $prefix = 'WcArca\\';
    $base_dir = OPEN_FACTURARCA_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
}
