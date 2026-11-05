<?php
namespace WcArca;

defined('ABSPATH') || exit;

class Plugin {
    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function init() {
        // Inicializar Configuración en Admin
        Admin\Settings::init();

        // Inicializar Campos de Checkout (DNI/CUIT y Condición Fiscal)
        Checkout\Fields::init();

        // Inicializar Metabox y Botón de Emisión Manual en Pedidos
        Admin\OrderMetabox::init();

        // Inicializar Listener para Emisión Automática
        Orders\InvoiceTrigger::init();

        // Inicializar Endpoints de descarga de PDF / QR
        Invoices\PdfHandler::init();

        // Inicializar Remitos de Entrega (Letra X)
        Invoices\PackingSlipHandler::init();

        // Inicializar Notas de Pedido / Venta (Letra X)
        Invoices\SalesOrderHandler::init();

        // Inicializar Acciones en Lote (Bulk) y Exportación a ZIP
        Admin\BulkActions::init();

        // Inicializar Reportes Estadísticos y Exportación Libro IVA / IIBB
        Admin\ReportsHandler::init();
    }
}

