<?php
namespace WcArca\Services;

defined('ABSPATH') || exit;

class WsfeClient {
    private $company;
    private $auth_service;

    public function __construct($company = null) {
        $this->company      = $company ?: CompanyManager::get_default_company();
        $this->auth_service = new WsAuth($this->company);
    }

    private function get_wsfe_wsdl() {
        return ($this->company['environment'] === 'production')
            ? 'https://servicios1.afip.gov.ar/wsfev1/service.asmx?WSDL'
            : 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL';
    }

    private function get_soap_client() {
        return new \SoapClient($this->get_wsfe_wsdl(), [
            'soap_version' => SOAP_1_2,
            'trace'        => 1,
            'exceptions'   => 1,
            'cache_wsdl'   => WSDL_CACHE_NONE,
        ]);
    }

    public function get_last_cbte_nro($cbte_tipo) {
        $auth = $this->auth_service->get_access_ticket('wsfe');
        $client = $this->get_soap_client();

        $params = [
            'Auth' => [
                'Token'    => $auth['token'],
                'Sign'     => $auth['sign'],
                'Cuit'     => $this->company['cuit'],
            ],
            'PtoVta'   => (int) $this->company['pto_vta'],
            'CbteTipo' => $cbte_tipo,
        ];

        $res = $client->FECompUltimoAutorizado($params);

        if (isset($res->FECompUltimoAutorizadoResult->CbteNro)) {
            return (int) $res->FECompUltimoAutorizadoResult->CbteNro;
        }

        return 0;
    }

    public function authorize_invoice($cbte_tipo, $doc_tipo, $doc_nro, $total, $neto = 0, $iva_array = [], $cbtes_asoc = [], $condicion_iva_receptor_id = 5) {
        $auth = $this->auth_service->get_access_ticket('wsfe');
        $client = $this->get_soap_client();

        $last_nro = $this->get_last_cbte_nro($cbte_tipo);
        $next_nro = $last_nro + 1;
        $cbte_fch = date('Ymd');
        $pto_vta  = (int) $this->company['pto_vta'];

        $req = [
            'Concepto'               => 1,
            'DocTipo'                => (int)$doc_tipo,
            'DocNro'                 => (float)$doc_nro,
            'CbteDesde'              => $next_nro,
            'CbteHasta'              => $next_nro,
            'CbteFch'                => $cbte_fch,
            'ImpTotal'               => round($total, 2),
            'ImpTotConc'             => 0,
            'ImpOpEx'                => 0,
            'ImpTrib'                => 0,
            'MonId'                  => 'PES',
            'MonCotiz'               => 1,
            'CondicionIVAReceptorId' => (int)$condicion_iva_receptor_id,
        ];

        if ($cbte_tipo === TaxResolver::CBTE_FACTURA_C || $cbte_tipo === TaxResolver::CBTE_NOTA_CREDITO_C) {
            $req['ImpNeto'] = round($total, 2);
            $req['ImpIVA']  = 0;
        } else {
            $req['ImpNeto'] = round($neto, 2);
            $req['ImpIVA']  = round($total - $neto, 2);

            if (!empty($iva_array)) {
                $req['Iva'] = [
                    'AlicIva' => $iva_array
                ];
            }
        }

        if (!empty($cbtes_asoc)) {
            $req['CbtesAsoc'] = [
                'CbteAsoc' => $cbtes_asoc
            ];
        }

        $params = [
            'Auth' => [
                'Token' => $auth['token'],
                'Sign'  => $auth['sign'],
                'Cuit'  => $this->company['cuit'],
            ],
            'FeCAEReq' => [
                'FeCabReq' => [
                    'CantReg'  => 1,
                    'PtoVta'   => $pto_vta,
                    'CbteTipo' => $cbte_tipo,
                ],
                'FeDetReq' => [
                    'FECAEDetRequest' => $req,
                ],
            ],
        ];

        $res = $client->FECAESolicitar($params);
        $result = $res->FECAESolicitarResult;

        if ($result->FeCabResp->Resultado !== 'A') {
            $err_msg = 'Rechazado por ARCA: ';
            if (!empty($result->FeDetResp->FECAEDetResponse->Observaciones)) {
                $obs = $result->FeDetResp->FECAEDetResponse->Observaciones->Obs;
                if (!is_array($obs)) $obs = [$obs];
                foreach ($obs as $o) {
                    $err_msg .= "[{$o->Code}] {$o->Msg} ";
                }
            }
            if (!empty($result->Errors)) {
                $errors = $result->Errors->Err;
                if (!is_array($errors)) $errors = [$errors];
                foreach ($errors as $e) {
                    $err_msg .= "[{$e->Code}] {$e->Msg} ";
                }
            }
            throw new \Exception($err_msg);
        }

        $det = $result->FeDetResp->FECAEDetResponse;

        return [
            'cae'            => (string) $det->CAE,
            'cae_vto'        => (string) $det->CAEFchVto,
            'cbte_nro'       => $next_nro,
            'pto_vta'        => $pto_vta,
            'cbte_tipo'      => $cbte_tipo,
            'cbte_fch'       => $cbte_fch,
            'importe'        => $total,
            'company_id'     => $this->company['id'],
            'company_name'   => $this->company['name'],
            'company_cuit'   => $this->company['cuit'],
        ];
    }
}
