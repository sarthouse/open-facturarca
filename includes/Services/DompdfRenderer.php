<?php
namespace WcArca\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

defined('ABSPATH') || exit;

class DompdfRenderer {
    /**
     * Carga el autoloader de Dompdf empaquetado en el plugin
     */
    public static function load() {
        $autoload = OPEN_FACTURARCA_DIR . 'includes/Libraries/dompdf/autoload.inc.php';
        if (file_exists($autoload)) {
            require_once $autoload;
            return true;
        }

        $vendor_autoload = OPEN_FACTURARCA_DIR . 'includes/Libraries/dompdf/vendor/autoload.php';
        if (file_exists($vendor_autoload)) {
            require_once $vendor_autoload;
            return true;
        }

        return false;
    }

    /**
     * Devuelve el estilo base compartido y robusto para todos los comprobantes Dompdf
     */
    public static function get_base_css() {
        return '
        @page {
            size: 210mm 297mm;
            margin: 10mm 12mm;
        }
        * {
            box-sizing: border-box;
            font-family: Helvetica, Arial, sans-serif;
        }
        body {
            margin: 0;
            padding: 0;
            color: #111;
            font-size: 11px;
            line-height: 1.35;
            background: #fff;
        }
        .voucher-page {
            width: 100%;
            height: 270mm;
            page-break-after: always;
        }
        .voucher-page:last-child {
            page-break-after: avoid;
        }
        .border-box-table {
            width: 100%;
            height: 100%;
            border: 1.5px solid #000;
            border-collapse: collapse;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            border-bottom: 1.5px solid #000;
        }
        .header-col-left {
            width: 45%;
            vertical-align: top;
            padding: 10px 12px;
            border-right: 1px solid #000;
        }
        .header-col-center {
            width: 10%;
            vertical-align: top;
            text-align: center;
            padding: 0;
            background-color: #f8f8f8;
            border-right: 1px solid #000;
        }
        .header-col-right {
            width: 45%;
            vertical-align: top;
            padding: 10px 12px;
        }
        .letter-badge {
            font-size: 26px;
            font-weight: bold;
            line-height: 30px;
            display: block;
            padding-top: 4px;
        }
        .letter-code {
            font-size: 8px;
            font-weight: bold;
            display: block;
            border-top: 1px solid #000;
            padding: 2px 0;
            background: #fff;
        }
        .non-fiscal-warning {
            font-size: 6.5px;
            font-weight: bold;
            text-transform: uppercase;
            border-top: 1px solid #000;
            padding: 3px 1px;
            background: #eee;
            line-height: 1.1;
        }
        .copy-type {
            float: right;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .logo {
            max-height: 38px;
            max-width: 130px;
            display: block;
            margin-bottom: 4px;
        }
        .brand-title {
            font-size: 15px;
            font-weight: bold;
            margin-bottom: 3px;
        }
        .voucher-title {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 4px;
            letter-spacing: 0.3px;
        }
        .meta-line {
            margin: 2px 0;
            font-size: 10px;
        }
        .meta-line strong {
            font-weight: bold;
        }
        .client-table {
            width: 100%;
            border-collapse: collapse;
            border-bottom: 1.5px solid #000;
            background-color: #fafafa;
        }
        .client-col {
            width: 50%;
            vertical-align: top;
            padding: 8px 12px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
        }
        .items-table th {
            background-color: #f0f0f0;
            border-bottom: 1.5px solid #000;
            padding: 6px 10px;
            font-size: 9.5px;
            text-transform: uppercase;
            font-weight: bold;
            text-align: left;
        }
        .items-table td {
            padding: 7px 10px;
            border-bottom: 1px solid #e5e5e5;
            font-size: 10px;
            vertical-align: middle;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .section-notes {
            padding: 8px 12px;
            border-top: 1px solid #ccc;
            font-size: 9.5px;
            color: #333;
            background: #fff;
        }
        .content-row {
            height: 100%;
        }
        .footer-row {
            height: 1px;
        }
        .signatures-table {
            width: 100%;
            border-collapse: collapse;
            border-top: 1.5px solid #000;
            background-color: #fafafa;
        }
        .signature-cell {
            width: 50%;
            padding: 30px 25px 12px 25px;
            text-align: center;
            vertical-align: bottom;
        }
        .signature-line {
            border-top: 1px solid #000;
            margin-bottom: 4px;
        }
        .signature-label {
            font-size: 9.5px;
            font-weight: bold;
        }
        .signature-sub {
            font-size: 8px;
            color: #666;
        }
        .totals-table {
            width: 100%;
            border-collapse: collapse;
            border-top: 1.5px solid #000;
        }
        .totals-col-left {
            width: 55%;
            vertical-align: top;
            padding: 8px 12px;
            font-size: 9px;
            color: #444;
            border-right: 1px solid #ddd;
        }
        .totals-col-right {
            width: 45%;
            vertical-align: top;
            padding: 0;
        }
        .subtotals-table {
            width: 100%;
            border-collapse: collapse;
        }
        .subtotals-table td {
            padding: 4px 10px;
            font-size: 10px;
        }
        .total-highlight {
            background-color: #f0f0f0;
            border-top: 1.5px solid #000;
            font-size: 11.5px !important;
            font-weight: bold;
        }
        .arca-footer {
            border-top: 1.5px solid #000;
            padding: 8px 12px;
            background: #fafafa;
        }
        .arca-footer table {
            width: 100%;
            border-collapse: collapse;
        }
        .arca-qr-cell {
            width: 75px;
            vertical-align: middle;
        }
        .arca-qr {
            width: 70px;
            height: 70px;
            display: block;
        }
        .arca-data-cell {
            vertical-align: middle;
            padding-left: 10px;
            font-size: 10.5px;
        }
        ';
    }

    /**
     * Convierte un string HTML con estilos CSS en un archivo binario PDF
     */
    public static function render_html_to_pdf($html, $paper = 'a4', $orientation = 'portrait') {
        if (!self::load()) {
            throw new \Exception(__('La librería Dompdf no se encuentra en includes/Libraries/dompdf.', 'open-facturarca'));
        }

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Helvetica');
        $options->set('dpi', 96);

        $css = self::get_base_css();
        $full_document = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>' . $html . '</body></html>';

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($full_document, 'UTF-8');
        $dompdf->setPaper($paper, $orientation);
        $dompdf->render();

        return $dompdf->output();
    }
}
