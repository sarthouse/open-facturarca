<?php
/**
 * Plugin Name: Open FacturARCA – Facturación Electrónica ARCA/AFIP para WooCommerce
 * Plugin URI:  https://github.com/sarthouse/open-facturarca
 * Description: Solución de código abierto para emitir Facturas A, B, C y Notas de Crédito automáticas o manuales en ARCA (AFIP) con soporte Multi-empresa.
 * Version:     1.0.0
 * Author:      Tiago Gabriel Sarthou
 * Author URI:  https://github.com/sarthouse/open-facturarca
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
