<?php
namespace WcArca\Services;

defined('ABSPATH') || exit;

class TaxResolver {
    // Códigos de comprobante según WSFE ARCA / AFIP:
    const CBTE_FACTURA_A = 1;
    const CBTE_NOTA_DEBITO_A = 2;
    const CBTE_NOTA_CREDITO_A = 3;
    const CBTE_FACTURA_B = 6;
    const CBTE_NOTA_DEBITO_B = 7;
    const CBTE_NOTA_CREDITO_B = 8;
    const CBTE_FACTURA_C = 11;
    const CBTE_NOTA_DEBITO_C = 12;
    const CBTE_NOTA_CREDITO_C = 13;

    /**
     * Determina el código de tipo de comprobante según emisor y receptor
     *
     * @param string $issuer_condition  'monotributo' | 'responsable_inscripto'
     * @param string $receiver_condition 'consumidor_final' | 'responsable_inscripto' | 'monotributo' | 'exento'
     * @param string $action 'invoice' | 'credit_note'
     * @return int
     */
    public static function resolve_cbte_tipo($issuer_condition, $receiver_condition, $action = 'invoice') {
        // Si el emisor es Monotributista -> SIEMPRE emite Factura C (o Nota de Crédito C)
        if ($issuer_condition === 'monotributo') {
            return ($action === 'credit_note') ? self::CBTE_NOTA_CREDITO_C : self::CBTE_FACTURA_C;
        }

        // Si el emisor es Responsable Inscripto:
        // A otros Responsables Inscriptos -> Factura A
        if ($receiver_condition === 'responsable_inscripto') {
            return ($action === 'credit_note') ? self::CBTE_NOTA_CREDITO_A : self::CBTE_FACTURA_A;
        }

        // A Consumidores Finales, Monotributistas o Exentos -> Factura B
        return ($action === 'credit_note') ? self::CBTE_NOTA_CREDITO_B : self::CBTE_FACTURA_B;
    }

    /**
     * Devuelve el nombre legible de la letra y comprobante
     */
    public static function get_cbte_label($cbte_tipo) {
        $labels = [
            self::CBTE_FACTURA_A => 'Factura A',
            self::CBTE_FACTURA_B => 'Factura B',
            self::CBTE_FACTURA_C => 'Factura C',
            self::CBTE_NOTA_CREDITO_A => 'Nota de Crédito A',
            self::CBTE_NOTA_CREDITO_B => 'Nota de Crédito B',
            self::CBTE_NOTA_CREDITO_C => 'Nota de Crédito C',
        ];

        return $labels[$cbte_tipo] ?? 'Comprobante ' . $cbte_tipo;
    }

    /**
     * Devuelve el nombre legible de la condición frente al IVA
     */
    public static function get_tax_condition_label($condition_slug) {
        $labels = [
            'consumidor_final'      => 'Consumidor Final',
            'responsable_inscripto' => 'IVA Responsable Inscripto',
            'monotributo'           => 'Responsable Monotributo',
            'exento'                => 'IVA Exento',
            'no_alcanzado'          => 'No Alcanzado',
        ];

        return $labels[$condition_slug] ?? ucfirst(str_replace('_', ' ', (string)$condition_slug));
    }

    /**
     * Devuelve el ID numérico oficial de ARCA / AFIP para CondicionIvaReceptorId (RG 5616)
     *
     * Tabla oficial FEParamGetCondicionIvaReceptor:
     * 1  = IVA Responsable Inscripto
     * 4  = IVA Sujeto Exento
     * 5  = Consumidor Final
     * 6  = Responsable Monotributo
     * 7  = Sujeto No Categorizado
     * 8  = Proveedor del Exterior
     * 9  = Cliente del Exterior
     * 10 = IVA Liberado - Ley Nº 19.640
     * 13 = Monotributista Social
     * 15 = IVA No Alcanzado
     * 16 = Monotributo Trabajador Independiente Promovido
     */
    public static function get_receiver_iva_id($condition_slug) {
        $map = [
            'responsable_inscripto' => 1,
            'exento'                => 4,
            'consumidor_final'      => 5,
            'monotributo'           => 6,
            'no_categorizado'       => 7,
            'no_alcanzado'          => 15,
        ];

        return $map[$condition_slug] ?? 5; // Por defecto 5 (Consumidor Final)
    }
}

