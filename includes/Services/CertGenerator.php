<?php
namespace WcArca\Services;

defined('ABSPATH') || exit;

class CertGenerator {
    /**
     * Genera un par Clave Privada (.key) y Pedido de Certificado (.csr)
     * usando la extensión nativa de OpenSSL en PHP.
     *
     * @param string $company_name Nombre o Razón Social
     * @param string $cuit CUIT del emisor sin guiones
     * @return array [ 'private_key' => string, 'csr' => string ]
     */
    public static function generate_key_and_csr($company_name, $cuit) {
        if (!extension_loaded('openssl')) {
            throw new \Exception(__('La extensión OpenSSL de PHP no está habilitada en este servidor.', 'wc-factura-arca'));
        }

        $dn = [
            'countryName'            => 'AR',
            'stateOrProvinceName'    => 'Buenos Aires',
            'localityName'           => 'Ciudad Autonoma de Buenos Aires',
            'organizationName'       => sanitize_text_field($company_name),
            'commonName'             => sanitize_text_field($company_name),
            'serialNumber'           => 'CUIT ' . preg_replace('/[^0-9]/', '', $cuit),
        ];

        $config = [
            'digest_alg'       => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        // 1. Generar la Clave Privada RSA
        $private_key_resource = openssl_pkey_new($config);
        if (!$private_key_resource) {
            throw new \Exception(__('No se pudo generar la clave privada RSA. Verifique la configuración de OpenSSL.', 'wc-factura-arca'));
        }

        // Exportar clave privada a string
        openssl_pkey_export($private_key_resource, $private_key_pem);

        // 2. Generar el CSR (Certificate Signing Request)
        $csr_resource = openssl_csr_new($dn, $private_key_resource, $config);
        if (!$csr_resource) {
            throw new \Exception(__('No se pudo generar el archivo CSR.', 'wc-factura-arca'));
        }

        // Exportar CSR a string
        openssl_csr_export($csr_resource, $csr_pem);

        return [
            'private_key' => $private_key_pem,
            'csr'         => $csr_pem,
        ];
    }
}

