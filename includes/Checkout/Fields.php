<?php
namespace WcArca\Checkout;

use WcArca\Services\PadronClient;

defined('ABSPATH') || exit;

class Fields {
    public static function init() {
        // Añadir campos en el formulario de Checkout
        add_filter('woocommerce_checkout_fields', [__CLASS__, 'add_checkout_fields']);

        // Validar DNI/CUIT en el checkout
        add_action('woocommerce_checkout_process', [__CLASS__, 'validate_fields']);

        // Guardar campos en los metadatos de la orden
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'save_order_fields'], 10, 2);

        // Mostrar y editar en la vista del pedido en admin (Compatible con Legacy y HPOS)
        add_action('woocommerce_admin_order_data_after_billing_address', [__CLASS__, 'display_admin_order_fields'], 10, 1);
        add_action('woocommerce_process_shop_order_meta', [__CLASS__, 'save_admin_order_fields'], 10, 1);
        add_action('woocommerce_update_order', [__CLASS__, 'save_admin_order_fields'], 10, 1);

        // Endpoint AJAX para chequear CUIT en tiempo real en el checkout
        add_action('wp_ajax_wc_arca_check_cuit', [__CLASS__, 'ajax_check_cuit']);
        add_action('wp_ajax_nopriv_wc_arca_check_cuit', [__CLASS__, 'ajax_check_cuit']);
        add_action('wp_footer', [__CLASS__, 'render_checkout_script']);
    }

    public static function add_checkout_fields($fields) {
        // Lista de posibles slugs de DNI/CUIT creados por otros plugins o temas
        $possible_dni_fields = ['billing_dni', 'billing_cuit', 'billing_doc', 'billing_cedula', 'billing_id'];
        $already_exists = false;

        foreach ($possible_dni_fields as $field_key) {
            if (isset($fields['billing'][$field_key])) {
                $already_exists = true;
                break;
            }
        }

        // Si NO existe ningún campo previo de documento en el checkout, lo creamos
        if (!$already_exists) {
            $fields['billing']['billing_dni'] = [
                'type'        => 'text',
                'label'       => __('DNI o CUIT', 'open-facturarca'),
                'placeholder' => __('Sin puntos ni guiones (ej. 20123456789 o 35123456)', 'open-facturarca'),
                'required'    => true,
                'class'       => ['form-row-wide'],
                'clear'       => true,
                'priority'    => 26,
            ];
        }

        return $fields;
    }

    /**
     * Helper para capturar el valor del documento desde $_POST venga del campo que venga
     */
    private static function get_submitted_doc_number() {
        $candidate_keys = ['billing_dni', 'billing_cuit', 'billing_doc', '_billing_dni', 'billing_cedula'];
        foreach ($candidate_keys as $key) {
            if (!empty($_POST[$key])) {
                return preg_replace('/[^0-9]/', '', sanitize_text_field($_POST[$key]));
            }
        }
        return '';
    }

    public static function validate_fields() {
        $doc_num = self::get_submitted_doc_number();

        if (empty($doc_num)) {
            wc_add_notice(__('Por favor ingrese su DNI o CUIT para la factura.', 'open-facturarca'), 'error');
            return;
        }

        $length = strlen($doc_num);

        if ($length === 11) {
            if (!self::validate_cuit($doc_num)) {
                wc_add_notice(__('El CUIT ingresado no es válido (dígito verificador incorrecto).', 'open-facturarca'), 'error');
            }
        } elseif ($length >= 7 && $length <= 8) {
            $dni_val = (int)$doc_num;
            if ($dni_val < 1000000 || $dni_val > 99999999) {
                wc_add_notice(__('El número de DNI ingresado no es válido.', 'open-facturarca'), 'error');
            }
        } else {
            wc_add_notice(__('Ingrese un DNI válido (7 u 8 dígitos) o un CUIT de 11 dígitos.', 'open-facturarca'), 'error');
        }
    }

    public static function validate_cuit($cuit) {
        if (strlen($cuit) !== 11 || !is_numeric($cuit)) {
            return false;
        }

        $multipliers = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $total = 0;

        for ($i = 0; $i < 10; $i++) {
            $total += (int)$cuit[$i] * $multipliers[$i];
        }

        $remainder = $total % 11;
        $verif = 11 - $remainder;

        if ($verif === 11) $verif = 0;
        if ($verif === 10) $verif = 9;

        return $verif === (int)$cuit[10];
    }

    public static function save_order_fields($order, $data) {
        $doc_number = self::get_submitted_doc_number();

        if (!empty($doc_number)) {
            // Guardamos en ambos meta keys para compatibilidad total con otros plugins
            $order->update_meta_data('_billing_dni', $doc_number);
            $order->update_meta_data('billing_dni', $doc_number);

            $length = strlen($doc_number);

            if ($length === 11) {
                // Es un CUIT: Consultar padrón oficial de ARCA automáticamente
                $padron = new PadronClient();
                $info = $padron->get_tax_condition_by_cuit($doc_number);

                $order->update_meta_data('_billing_doc_type', 80); // 80 = CUIT
                $order->update_meta_data('_billing_tax_condition', $info['tax_condition']);
                if (!empty($info['name'])) {
                    $order->update_meta_data('_billing_tax_name', $info['name']);
                }
            } else {
                // Es DNI: Consumidor Final directamente
                $order->update_meta_data('_billing_doc_type', 96); // 96 = DNI
                $order->update_meta_data('_billing_tax_condition', 'consumidor_final');
            }
        }
    }

    public static function display_admin_order_fields($order) {
        $tax_condition = $order->get_meta('_billing_tax_condition') ?: 'consumidor_final';
        $dni           = $order->get_meta('_billing_dni') ?: ($order->get_meta('billing_dni') ?: $order->get_meta('_billing_doc_number'));
        $doc_type      = $order->get_meta('_billing_doc_type') ?: (strlen($dni) === 11 ? 80 : 96);
        $tax_name      = $order->get_meta('_billing_tax_name');

        $doc_name = ($doc_type == 80) ? 'CUIT' : 'DNI';

        echo '<div class="address">';
        echo '<p><strong>' . __('Datos Fiscales (ARCA):', 'open-facturarca') . '</strong><br>';
        echo esc_html($doc_name) . ': ' . esc_html($dni ?: 'No especificado (Fallback: Consumidor Final sin identificar)') . '<br>';
        echo __('Condición IVA: ', 'open-facturarca') . '<strong>' . esc_html(ucfirst(str_replace('_', ' ', $tax_condition))) . '</strong>';
        if ($tax_name) {
            echo '<br>' . __('Padrón ARCA: ', 'open-facturarca') . esc_html($tax_name);
        }
        echo '</p>';
        echo '</div>';

        // Campos editables cuando se hace clic en el lápiz de edición de facturación
        echo '<div class="edit_address">';
        woocommerce_wp_text_input([
            'id'          => '_billing_dni',
            'label'       => __('DNI o CUIT (ARCA):', 'open-facturarca'),
            'value'       => $dni,
            'description' => __('Número sin puntos ni guiones. Si es CUIT de 11 dígitos, se valida automáticamente.', 'open-facturarca'),
            'wrapper_class' => 'form-field-wide',
        ]);

        woocommerce_wp_select([
            'id'      => '_billing_tax_condition',
            'label'   => __('Condición IVA:', 'open-facturarca'),
            'value'   => $tax_condition,
            'options' => [
                'consumidor_final'      => __('Consumidor Final', 'open-facturarca'),
                'responsable_inscripto' => __('Responsable Inscripto', 'open-facturarca'),
                'monotributo'           => __('Monotributo', 'open-facturarca'),
                'exento'                => __('Exento', 'open-facturarca'),
            ],
            'wrapper_class' => 'form-field-wide',
        ]);
        echo '</div>';
    }

    public static function save_admin_order_fields($order_id) {
        // Evitar loops infinitos al disparar save() dentro de woocommerce_update_order
        static $is_saving = false;
        if ($is_saving) return;

        if (empty($_POST['_billing_dni']) && empty($_POST['_billing_tax_condition'])) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) return;

        $is_saving = true;

        if (isset($_POST['_billing_dni'])) {
            $raw_doc = sanitize_text_field($_POST['_billing_dni']);
            $clean_doc = preg_replace('/[^0-9]/', '', $raw_doc);
            $order->update_meta_data('_billing_dni', $clean_doc);
            $order->update_meta_data('billing_dni', $clean_doc);

            if (strlen($clean_doc) === 11) {
                $order->update_meta_data('_billing_doc_type', 80);

                // Auto-consultar padrón de ARCA si es CUIT para obtener Razón Social
                try {
                    $padron = new PadronClient();
                    $info = $padron->get_tax_condition_by_cuit($clean_doc);
                    if (!empty($info['tax_condition']) && empty($_POST['_billing_tax_condition'])) {
                        $order->update_meta_data('_billing_tax_condition', $info['tax_condition']);
                    }
                    if (!empty($info['name'])) {
                        $order->update_meta_data('_billing_tax_name', $info['name']);
                    }
                } catch (\Exception $e) {
                    // Silenciar si no hay conectividad temporal con padrón
                }
            } elseif (!empty($clean_doc)) {
                $order->update_meta_data('_billing_doc_type', 96);
            }
        }

        if (isset($_POST['_billing_tax_condition'])) {
            $order->update_meta_data('_billing_tax_condition', sanitize_text_field($_POST['_billing_tax_condition']));
        }

        $order->save();
        $is_saving = false;
    }

    /**
     * AJAX para mostrar feedback visual en vivo en el checkout
     */
    public static function ajax_check_cuit() {
        check_ajax_referer('wc_arca_checkout_nonce', 'security');
        $cuit = preg_replace('/[^0-9]/', '', $_POST['cuit'] ?? '');

        if (strlen($cuit) !== 11) {
            wp_send_json_success(['is_cuit' => false]);
        }

        $padron = new PadronClient();
        $info = $padron->get_tax_condition_by_cuit($cuit);

        wp_send_json_success([
            'is_cuit'       => true,
            'tax_condition' => $info['tax_condition'],
            'name'          => $info['name'],
            'label'         => ucfirst(str_replace('_', ' ', $info['tax_condition'])),
        ]);
    }

    public static function render_checkout_script() {
        if (!is_checkout()) return;
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var timeout = null;
            // Detecta tanto billing_dni como cualquier campo de documento existente
            var selector = '#billing_dni, #billing_cuit, #billing_doc, #billing_cedula';

            $(document).on('keyup change', selector, function() {
                var val = $(this).val().replace(/[^0-9]/g, '');
                $('#wc-arca-cuit-badge').remove();

                if (val.length === 11) {
                    clearTimeout(timeout);
                    var $field = $(this).closest('.form-row');
                    $field.append('<span id="wc-arca-cuit-badge" style="font-size:12px; color:#666; margin-top:4px; display:block;">Consultando condición en ARCA...</span>');

                    timeout = setTimeout(function() {
                        $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                            action: 'wc_arca_check_cuit',
                            cuit: val,
                            security: '<?php echo wp_create_nonce('wc_arca_checkout_nonce'); ?>'
                        }, function(res) {
                            if (res.success && res.data.is_cuit) {
                                var badgeText = 'Detectado: ' + res.data.label;
                                if (res.data.name) badgeText += ' (' + res.data.name + ')';
                                $('#wc-arca-cuit-badge').css('color', '#2e7d32').text(badgeText);
                            }
                        });
                    }, 500);
                }
            });
        });
        </script>
        <?php
    }
}
