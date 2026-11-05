<?php
namespace WcArca\Services;

defined('ABSPATH') || exit;

class CompanyManager {
    const OPTION_COMPANIES = 'wc_arca_companies_list';
    const OPTION_DEFAULT_COMPANY = 'wc_arca_default_company_id';

    /**
     * Obtiene la lista de todas las empresas configuradas
     */
    public static function get_companies() {
        $companies = get_option(self::OPTION_COMPANIES, []);
        if (empty($companies)) {
            // Migración / Default inicial con valores previos si existían
            $default_id = 'default';
            $companies[$default_id] = [
                'id'            => $default_id,
                'name'          => get_bloginfo('name') ?: 'Empresa Principal',
                'cuit'          => get_option('wc_arca_cuit', ''),
                'tax_condition' => get_option('wc_arca_issuer_tax_condition', 'monotributo'),
                'pto_vta'       => (int) get_option('wc_arca_pto_vta', 1),
                'environment'   => get_option('wc_arca_environment', 'testing'),
                'cert_path'     => get_option('wc_arca_cert_path', OPEN_FACTURARCA_DIR . 'certs/default.crt'),
                'key_path'      => get_option('wc_arca_key_path', OPEN_FACTURARCA_DIR . 'certs/default.key'),
            ];
            update_option(self::OPTION_COMPANIES, $companies);
            update_option(self::OPTION_DEFAULT_COMPANY, $default_id);
        }
        return $companies;
    }

    /**
     * Obtiene la empresa activa por defecto
     */
    public static function get_default_company() {
        $companies = self::get_companies();
        $default_id = get_option(self::OPTION_DEFAULT_COMPANY, 'default');
        return $companies[$default_id] ?? reset($companies);
    }

    /**
     * Obtiene una empresa por su ID
     */
    public static function get_company($id) {
        $companies = self::get_companies();
        return $companies[$id] ?? null;
    }

    /**
     * Guarda o actualiza una empresa
     */
    public static function save_company($data) {
        $companies = self::get_companies();
        $id = !empty($data['id']) ? sanitize_key($data['id']) : uniqid('comp_');

        $companies[$id] = [
            'id'            => $id,
            'name'          => sanitize_text_field($data['name'] ?? ''),
            'trade_name'    => sanitize_text_field($data['trade_name'] ?? ''),
            'logo_url'      => esc_url_raw($data['logo_url'] ?? ''),
            'cuit'          => preg_replace('/[^0-9]/', '', $data['cuit'] ?? ''),
            'tax_condition' => sanitize_text_field($data['tax_condition'] ?? 'monotributo'),
            'iibb'          => sanitize_text_field($data['iibb'] ?? ''),
            'activity_init' => sanitize_text_field($data['activity_init'] ?? ''),
            'address'       => sanitize_text_field($data['address'] ?? ''),
            'phone'         => sanitize_text_field($data['phone'] ?? ''),
            'province'      => sanitize_text_field($data['province'] ?? ''),
            'postcode'      => sanitize_text_field($data['postcode'] ?? ''),
            'pto_vta'       => (int) ($data['pto_vta'] ?? 1),
            'environment'   => sanitize_text_field($data['environment'] ?? 'testing'),
            'cert_path'     => sanitize_text_field($data['cert_path'] ?? ''),
            'key_path'      => sanitize_text_field($data['key_path'] ?? ''),
        ];

        update_option(self::OPTION_COMPANIES, $companies);
        return $id;
    }

    /**
     * Obtiene los días restantes de vigencia de un certificado X.509
     *
     * @param string $cert_path Ruta absoluta al archivo .crt
     * @return array [ 'valid' => bool, 'days_left' => int|null, 'expiration_date' => string|null, 'error' => string|null ]
     */
    public static function get_cert_expiration_info($cert_path) {
        if (empty($cert_path) || !file_exists($cert_path)) {
            return [
                'valid'           => false,
                'days_left'       => null,
                'expiration_date' => null,
                'error'           => 'Certificado no encontrado',
            ];
        }

        $content = file_get_contents($cert_path);
        if (!$content) {
            return [
                'valid'           => false,
                'days_left'       => null,
                'expiration_date' => null,
                'error'           => 'No se pudo leer el archivo',
            ];
        }

        $parsed = @openssl_x509_parse($content);
        if (!$parsed || !isset($parsed['validTo_time_t'])) {
            return [
                'valid'           => false,
                'days_left'       => null,
                'expiration_date' => null,
                'error'           => 'Certificado X.509 inválido',
            ];
        }

        $valid_to = (int) $parsed['validTo_time_t'];
        $now = time();
        $diff_seconds = $valid_to - $now;
        $days_left = (int) floor($diff_seconds / 86400);

        return [
            'valid'           => $days_left >= 0,
            'days_left'       => $days_left,
            'expiration_date' => gmdate('d/m/Y H:i', $valid_to),
            'error'           => null,
        ];
    }

    /**
     * Elimina una empresa
     */
    public static function delete_company($id) {
        $companies = self::get_companies();
        if (isset($companies[$id])) {
            unset($companies[$id]);
            update_option(self::OPTION_COMPANIES, $companies);
        }
    }
}

