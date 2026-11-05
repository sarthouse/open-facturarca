<?php
namespace WcArca\Services;

defined('ABSPATH') || exit;

/**
 * Generador nativo y ultraliviano de archivos PDF (100% PHP puro, sin librerías externas)
 * Genera PDFs válidos compatibles con Adobe Reader, Chrome, navegadores y celulares.
 * Soporta texto, rectángulos con color de fondo/borde, líneas e incrustación de imágenes JPEG.
 */
class SimplePdf {
    private $objects = [];
    private $pages = [];
    private $images = [];
    private $current_page_content = '';
    private $page_width = 595.28;  // A4 Ancho en puntos (pt)
    private $page_height = 841.89; // A4 Alto en puntos (pt)

    public function __construct() {}

    public function add_page() {
        if (!empty($this->current_page_content)) {
            $this->pages[] = $this->current_page_content;
            $this->current_page_content = '';
        }
    }

    public function text($x, $y, $text, $size = 10, $bold = false, $color = [0, 0, 0]) {
        $font = $bold ? '/F2' : '/F1';
        $escaped = $this->escape_text($text);
        // En PDF el eje Y comienza desde abajo
        $pdf_y = $this->page_height - $y;
        $r = $color[0] / 255;
        $g = $color[1] / 255;
        $b = $color[2] / 255;
        $this->current_page_content .= sprintf("q %.3f %.3f %.3f rg BT %s %d Tf %.2f %.2f Td (%s) Tj ET Q\n", $r, $g, $b, $font, $size, $x, $pdf_y, $escaped);
    }

    public function rect($x, $y, $w, $h, $fill = false, $stroke = true, $fillColor = [240, 240, 240], $strokeColor = [0, 0, 0]) {
        $pdf_y = $this->page_height - $y - $h;
        $op = ($fill && $stroke) ? 'B' : ($fill ? 'f' : 's');
        $r_fill = $fillColor[0] / 255;
        $g_fill = $fillColor[1] / 255;
        $b_fill = $fillColor[2] / 255;
        $r_str = $strokeColor[0] / 255;
        $g_str = $strokeColor[1] / 255;
        $b_str = $strokeColor[2] / 255;
        $this->current_page_content .= sprintf("q %.3f %.3f %.3f rg %.3f %.3f RG %.2f %.2f %.2f %.2f re %s Q\n", 
            $r_fill, $g_fill, $b_fill, $r_str, $g_str, $b_str, $x, $pdf_y, $w, $h, $op);
    }

    public function line($x1, $y1, $x2, $y2, $width = 1) {
        $pdf_y1 = $this->page_height - $y1;
        $pdf_y2 = $this->page_height - $y2;
        $this->current_page_content .= sprintf("%.2f w %.2f %.2f m %.2f %.2f l S\n", $width, $x1, $pdf_y1, $x2, $pdf_y2);
    }

    /**
     * Inserta una imagen (JPEG o PNG) en las coordenadas especificadas
     */
    public function image($file_or_url, $x, $y, $w, $h) {
        if (empty($file_or_url)) return false;

        $raw_data = '';
        if (filter_var($file_or_url, FILTER_VALIDATE_URL)) {
            // Resolver ruta local de WordPress si está en uploads
            if (function_exists('wp_upload_dir')) {
                $upload_dir = wp_upload_dir();
                if (strpos($file_or_url, $upload_dir['baseurl']) === 0) {
                    $local_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file_or_url);
                    if (file_exists($local_path)) {
                        $raw_data = file_get_contents($local_path);
                    }
                }
            }
            if (empty($raw_data) && function_exists('wp_remote_get')) {
                $resp = wp_remote_get($file_or_url, ['timeout' => 5, 'sslverify' => false]);
                if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
                    $raw_data = wp_remote_retrieve_body($resp);
                }
            }
        } elseif (file_exists($file_or_url)) {
            $raw_data = file_get_contents($file_or_url);
        }

        if (empty($raw_data)) return false;

        $jpeg_data = '';
        $img_w = 0;
        $img_h = 0;

        if (function_exists('imagecreatefromstring')) {
            $im = @imagecreatefromstring($raw_data);
            if ($im !== false) {
                $img_w = imagesx($im);
                $img_h = imagesy($im);
                // Convertir a JPEG con fondo blanco puro
                $bg = imagecreatetruecolor($img_w, $img_h);
                $white = imagecolorallocate($bg, 255, 255, 255);
                imagefilledrectangle($bg, 0, 0, $img_w, $img_h, $white);
                imagecopy($bg, $im, 0, 0, 0, 0, $img_w, $img_h);

                ob_start();
                imagejpeg($bg, null, 90);
                $jpeg_data = ob_get_clean();
                imagedestroy($im);
                imagedestroy($bg);
            }
        } else {
            if (substr($raw_data, 0, 2) === "\xFF\xD8") {
                $jpeg_data = $raw_data;
                $size = @getimagesizefromstring($raw_data);
                $img_w = $size[0] ?? 100;
                $img_h = $size[1] ?? 100;
            }
        }

        if (empty($jpeg_data)) return false;

        $img_key = 'I' . (count($this->images) + 1);
        $this->images[$img_key] = [
            'data'   => $jpeg_data,
            'width'  => $img_w,
            'height' => $img_h,
        ];

        // En PDF Y comienza desde abajo
        $pdf_y = $this->page_height - $y - $h;
        $this->current_page_content .= sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q\n", $w, $h, $x, $pdf_y, $img_key);
        return true;
    }

    private function escape_text($text) {
        // Convertir UTF-8 a ISO-8859-1 (WinAnsiEncoding) para PDF estándar nativo
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
        if ($converted === false) $converted = utf8_decode($text);
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
    }

    public function output() {
        if (!empty($this->current_page_content)) {
            $this->pages[] = $this->current_page_content;
            $this->current_page_content = '';
        }

        $page_count = count($this->pages);
        $font1_obj = 3;
        $font2_obj = 4;
        $pages_root_obj = 2;

        $page_obj_ids = [];
        $content_obj_ids = [];
        $next_id = 5;

        for ($i = 0; $i < $page_count; $i++) {
            $page_obj_ids[] = $next_id++;
            $content_obj_ids[] = $next_id++;
        }

        // Asignar IDs para imágenes
        $image_obj_ids = [];
        foreach ($this->images as $key => $img) {
            $image_obj_ids[$key] = $next_id++;
        }

        $out = "%PDF-1.4\n";
        $offsets = [];

        // Obj 1: Catalog
        $offsets[1] = strlen($out);
        $out .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

        // Obj 2: Pages Root
        $offsets[2] = strlen($out);
        $kids = implode(' 0 R ', $page_obj_ids) . ' 0 R';
        $out .= "2 0 obj\n<< /Type /Pages /Kids [ " . $kids . " ] /Count " . $page_count . " >>\nendobj\n";

        // Obj 3: Font Regular (Helvetica)
        $offsets[3] = strlen($out);
        $out .= "3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";

        // Obj 4: Font Bold (Helvetica-Bold)
        $offsets[4] = strlen($out);
        $out .= "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";

        // Construir diccionario de XObject para recursos
        $xobj_dict = '';
        if (!empty($image_obj_ids)) {
            $xobj_entries = [];
            foreach ($image_obj_ids as $key => $obj_id) {
                $xobj_entries[] = "/{$key} {$obj_id} 0 R";
            }
            $xobj_dict = ' /XObject << ' . implode(' ', $xobj_entries) . ' >>';
        }

        // Pages and Contents
        for ($i = 0; $i < $page_count; $i++) {
            $p_id = $page_obj_ids[$i];
            $c_id = $content_obj_ids[$i];
            $stream = $this->pages[$i];
            $len = strlen($stream);

            // Page Obj
            $offsets[$p_id] = strlen($out);
            $out .= sprintf("%d 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Contents %d 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R >>%s >> >>\nendobj\n", $p_id, $this->page_width, $this->page_height, $c_id, $xobj_dict);

            // Content Obj
            $offsets[$c_id] = strlen($out);
            $out .= sprintf("%d 0 obj\n<< /Length %d >>\nstream\n%sendstream\nendobj\n", $c_id, $len, $stream);
        }

        // Image Objs (JPEG stream directo con /DCTDecode)
        foreach ($this->images as $key => $img) {
            $img_obj_id = $image_obj_ids[$key];
            $offsets[$img_obj_id] = strlen($out);
            $len = strlen($img['data']);
            $out .= sprintf("%d 0 obj\n<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%sendstream\nendobj\n",
                $img_obj_id, $img['width'], $img['height'], $len, $img['data']);
        }

        // Xref
        $xref_offset = strlen($out);
        $total_objs = $next_id - 1;
        $out .= sprintf("xref\n0 %d\n0000000000 65535 f \n", $total_objs + 1);

        for ($i = 1; $i <= $total_objs; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $out .= sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF", $total_objs + 1, $xref_offset);

        return $out;
    }
}
