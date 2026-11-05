<?php
namespace WcArca\Services;

defined('ABSPATH') || exit;

class WsAuth {
    private $company;
    private $token_dir;

    public function __construct($company = null) {
        $this->company   = $company ?: CompanyManager::get_default_company();
        $this->token_dir = WC_ARCA_PLUGIN_DIR . 'storage/tokens/';

        if (!is_dir($this->token_dir)) {
            wp_mkdir_p($this->token_dir);
        }
    }

    private function get_wsaa_url() {
        return ($this->company['environment'] === 'production')
            ? 'https://wsaa.afip.gov.ar/ws/services/LoginCms'
            : 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms';
    }

    public function get_access_ticket($service = 'wsfe') {
        $cuit = $this->company['cuit'];
        $env  = $this->company['environment'];
        $cache_file = $this->token_dir . "TA_{$cuit}_{$service}_{$env}.json";

        if (file_exists($cache_file)) {
            $data = json_decode(file_get_contents($cache_file), true);
            if (!empty($data['expirationTime']) && strtotime($data['expirationTime']) > (time() + 600)) {
                return $data;
            }
        }

        return $this->request_new_ticket($service, $cache_file);
    }

    private function request_new_ticket($service, $cache_file) {
        $cert_path = $this->company['cert_path'];
        $key_path  = $this->company['key_path'];

        if (!file_exists($cert_path) || !file_exists($key_path)) {
            throw new \Exception(__('No se encontraron los certificados o claves privadas configurados para esta empresa.', 'wc-factura-arca'));
        }

        $tra_xml = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>' .
            '<loginTicketRequest version="1.0">' .
            '</loginTicketRequest>'
        );
        $header = $tra_xml->addChild('header');
        $header->addChild('uniqueId', date('U'));
        $header->addChild('generationTime', date('c', date('U') - 120));
        $header->addChild('expirationTime', date('c', date('U') + 1200));
        $tra_xml->addChild('service', $service);

        $tra_tmp_file = tempnam(sys_get_temp_dir(), 'tra_');
        $tra_xml->asXML($tra_tmp_file);

        $cms_tmp_file = tempnam(sys_get_temp_dir(), 'cms_');
        $cert_content = file_get_contents($cert_path);
        $key_content  = file_get_contents($key_path);

        $status = openssl_pkcs7_sign(
            $tra_tmp_file,
            $cms_tmp_file,
            $cert_content,
            $key_content,
            [],
            !PKCS7_DETACHED
        );

        unlink($tra_tmp_file);

        if (!$status) {
            if (file_exists($cms_tmp_file)) unlink($cms_tmp_file);
            throw new \Exception(__('Fallo al firmar el Ticket de Acceso con OpenSSL. Verifique la clave privada y certificado.', 'wc-factura-arca'));
        }

        $cms_content = file_get_contents($cms_tmp_file);
        unlink($cms_tmp_file);

        $parts = explode("\n\n", $cms_content, 2);
        $signed_cms = isset($parts[1]) ? $parts[1] : $cms_content;

        $client = new \SoapClient($this->get_wsaa_url() . '?wsdl', [
            'soap_version' => SOAP_1_2,
            'trace'        => 1,
            'exceptions'   => 1,
        ]);

        $results = $client->loginCms(['in0' => $signed_cms]);
        $response_xml = new \SimpleXMLElement($results->loginCmsReturn);

        $token = (string)$response_xml->credentials->token;
        $sign  = (string)$response_xml->credentials->sign;
        $expiration = (string)$response_xml->header->expirationTime;

        $ticket = [
            'token'          => $token,
            'sign'           => $sign,
            'expirationTime' => $expiration,
        ];

        file_put_contents($cache_file, json_encode($ticket));

        return $ticket;
    }
}
