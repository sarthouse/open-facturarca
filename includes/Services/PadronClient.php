<?php
namespace WcArca\Services;

defined('ABSPATH') || exit;

class PadronClient {
    private $company;
    private $auth_service;

    public function __construct($company = null) {
        $this->company      = $company ?: CompanyManager::get_default_company();
        // El servicio en ARCA para consultar padrón de constancia de inscripción es ws_sr_constancia_inscripcion (o ws_sr_padron_a5)
        $this->auth_service = new WsAuth($this->company);
    }

    private function get_padron_wsdl() {
        return ($this->company['environment'] === 'production')
            ? 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA5?WSDL'
            : 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA5?WSDL';
    }

    /**
     * Consulta la condición fiscal oficial de un CUIT en el Padrón de ARCA
     *
     * @param string $cuit 11 dígitos
     * @return array [ 'tax_condition' => string, 'name' => string, 'is_valid' => bool ]
     */
    public function get_tax_condition_by_cuit($cuit) {
        $clean_cuit = preg_replace('/[^0-9]/', '', $cuit);
        if (strlen($clean_cuit) !== 11) {
            return [
                'tax_condition' => 'consumidor_final',
                'name'          => '',
                'is_valid'      => false,
            ];
        }

        // Cache local de consultas de padrón (24 horas) para no saturar ARCA ni demorar el checkout
        $cache_key = 'wc_arca_padron_' . $clean_cuit;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        try {
            $auth = $this->auth_service->get_access_ticket('ws_sr_constancia_inscripcion');
            $client = new \SoapClient($this->get_padron_wsdl(), [
                'soap_version' => SOAP_1_2,
                'trace'        => 1,
                'exceptions'   => 1,
                'connection_timeout' => 3, // Timeout estricto para no colgar el checkout
            ]);

            $params = [
                'token'          => $auth['token'],
                'sign'           => $auth['sign'],
                'cuitRepresentada' => (float)$this->company['cuit'],
                'idPersona'      => (float)$clean_cuit,
            ];

            $res = $client->getPersona($params);

            if (empty($res->personaReturn->datosRegimenGeneral) && empty($res->personaReturn->datosMonotributo)) {
                $result = [
                    'tax_condition' => 'consumidor_final',
                    'name'          => $res->personaReturn->datosGenerales->razonSocial ?? '',
                    'is_valid'      => true,
                ];
                set_transient($cache_key, $result, DAY_IN_SECONDS);
                return $result;
            }

            // Analizar si es Monotributo o Responsable Inscripto
            $tax_condition = 'consumidor_final';

            if (!empty($res->personaReturn->datosMonotributo)) {
                $tax_condition = 'monotributo';
            } elseif (!empty($res->personaReturn->datosRegimenGeneral)) {
                // Verificar si tiene el impuesto IVA activo (Impuesto código 30 en AFIP)
                $impuestos = $res->personaReturn->datosRegimenGeneral->impuesto ?? [];
                if (!is_array($impuestos)) $impuestos = [$impuestos];

                foreach ($impuestos as $imp) {
                    if (isset($imp->idImpuesto) && (int)$imp->idImpuesto === 30) {
                        $tax_condition = 'responsable_inscripto';
                        break;
                    } elseif (isset($imp->idImpuesto) && (int)$imp->idImpuesto === 32) { // IVA Exento
                        $tax_condition = 'exento';
                        break;
                    }
                }
            }

            $name = $res->personaReturn->datosGenerales->razonSocial 
                ?? (($res->personaReturn->datosGenerales->apellido ?? '') . ' ' . ($res->personaReturn->datosGenerales->nombre ?? ''));

            $result = [
                'tax_condition' => $tax_condition,
                'name'          => trim($name),
                'is_valid'      => true,
            ];

            set_transient($cache_key, $result, DAY_IN_SECONDS);
            return $result;

        } catch (\Exception $e) {
            // Si el servicio de padrón falla o no está autorizado, fallback seguro a consumidor_final
            return [
                'tax_condition' => 'consumidor_final',
                'name'          => '',
                'is_valid'      => false,
                'error'         => $e->getMessage(),
            ];
        }
    }
}

