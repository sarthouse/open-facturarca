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
            'logo_url'      => esc_url_raw($data['logo_url'] ?? ''),
            'cuit'          => preg_replace('/[^0-9]/', '', $data['cuit'] ?? ''),
            'tax_condition' => sanitize_text_field($data['tax_condition'] ?? 'monotributo'),
            'iibb'          => sanitize_text_field($data['iibb'] ?? ''),
            'activity_init' => sanitize_text_field($data['activity_init'] ?? ''),
            'pto_vta'       => (int) ($data['pto_vta'] ?? 1),
            'environment'   => sanitize_text_field($data['environment'] ?? 'testing'),
            'cert_path'     => sanitize_text_field($data['cert_path'] ?? ''),
            'key_path'      => sanitize_text_field($data['key_path'] ?? ''),
        ];

        update_option(self::OPTION_COMPANIES, $companies);
        return $id;
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

