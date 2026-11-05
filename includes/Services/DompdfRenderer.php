<?php
namespace WcArca\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

defined('ABSPATH') || exit;

class DompdfRenderer {
    /**
     * Carga el autoloader de Dompdf empaquetado en el plugin
     */
    public static function load(): bool {
        if (class_exists('Dompdf\Dompdf')) {
            return true;
        }

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
     * Devuelve el estilo base adaptado a las limitaciones de Dompdf
     */
    public static function get_base_css(): string {
        return '
        @page {
            size: a4 portrait;
            margin: 0;
        }
        * {
            box-sizing: border-box;
            font-family: Helvetica, Arial, sans-serif;
        }
        html {
            margin: 0;
            padding: 0
        }
        body {
            margin: 10mm 12mm;
            padding: 0;
            color: #111;
            font-size: 10px;
            line-height: 1.3;
            background: #fff;
        }
        .voucher-page {
            position: relative;
        }
        .voucher-page + .voucher-page {
            page-break-before: always;
        }
        .border-box {
            border: 1.5px solid #000;
            position: relative;
            background: #fff;
            height: 250mm
        }
        .header-copy-bar {
            width: 100%;
            border-bottom: 1.5px solid #000;
            text-align: center;
            font-size: 11px;
            font-weight: bold;
            padding: 4px 0;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            background: #fff;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            border-bottom: 1.5px solid #000;
        }
        .header-col-left {
            width: 50%;
            vertical-align: top;
            padding: 8px 24px 8px 12px;
            border-right: 1px solid #000;
        }
        .header-col-right {
            width: 50%;
            vertical-align: top;
            padding: 8px 12px 8px 32px;
        }
        .header-badge-box {
            position: absolute;
            top: 23px;
            left: 50%;
            margin-left: -19px;
            width: 38px;
            border: 1.5px solid #000;
            background: #fff;
            text-align: center;
            z-index: 99;
        }
        .footer-pinned {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            width: 100%;
        }
        .letter-badge {
            font-size: 24px;
            font-weight: bold;
            line-height: 26px;
            display: block;
            padding-top: 1px;
        }
        .letter-code {
            font-size: 7.5px;
            font-weight: bold;
            display: block;
            border-top: 1px solid #000;
            padding: 1px 0;
            background: #fff;
        }
        .non-fiscal-warning {
            font-size: 6px;
            font-weight: bold;
            text-transform: uppercase;
            border-top: 1px solid #000;
            padding: 1px;
            background: #f0f0f0;
            line-height: 1;
        }
        .logo {
            max-height: 38px;
            max-width: 130px;
            display: block;
            margin-bottom: 4px;
        }
        .brand-title {
            font-size: 13.5px;
            font-weight: bold;
            margin-bottom: 4px;
        }
        .voucher-title {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 6px;
            letter-spacing: 0.5px;
        }
        .meta-line {
            margin: 2px 0;
            font-size: 9.5px;
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
            padding: 6px 10px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
        }
        .items-table th {
            background-color: #f0f0f0;
            border-bottom: 1.5px solid #000;
            padding: 5px 8px;
            font-size: 9px;
            text-transform: uppercase;
            font-weight: bold;
            text-align: left;
        }
        .items-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #e5e5e5;
            font-size: 9.5px;
            vertical-align: middle;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .section-notes {
            padding: 6px 10px;
            border-top: 1px solid #ccc;
            font-size: 9px;
            color: #333;
            background: #fff;
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
            font-size: 9px;
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
            padding: 6px 10px;
            font-size: 8.5px;
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
            padding: 4px 8px;
            font-size: 9.5px;
        }
        .total-highlight {
            background-color: #f0f0f0;
            border-top: 1.5px solid #000;
            font-size: 11px !important;
            font-weight: bold;
        }
        .arca-footer {
            border-top: 1.5px solid #000;
            padding: 6px 10px;
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
            font-size: 10px;
        }
        ';
    }

    /**
     * Convierte HTML en binario PDF
     */
    public static function render_html_to_pdf(string $html, string $paper = 'a4', string $orientation = 'portrait'): string {
        if (!self::load()) {
            throw new \Exception(__('La librería Dompdf no se encuentra en includes/Libraries/dompdf.', 'open-facturarca'));
        }

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Helvetica');
        $options->set('dpi', 96);
        $options->set('chroot', OPEN_FACTURARCA_DIR);

        $css = self::get_base_css();
        $full_document = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"/><style>' . $css . '</style></head><body>' . $html . '</body></html>';

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($full_document, 'UTF-8');
        $dompdf->setPaper($paper, $orientation);
        $dompdf->render();

        return $dompdf->output();
    }
}
