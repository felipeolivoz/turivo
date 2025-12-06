<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="tt-pricing-rules">
    <div class="tt-admin-container">
        <div class="tt-form-section">
            <h3><?php esc_html_e('Agregar Regla de Precio', 'tur-transportes'); ?></h3>

            <form method="post" action="">
                <?php
                // Nonce de seguridad para guardar reglas de precio
                wp_nonce_field('tt_save_pricing_rule', 'tt_pricing_rule_nonce');
                ?>

                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Tipo de Vehículo', 'tur-transportes'); ?></th>
                        <td>
                            <select name="vehicle_type_id">
                                <option value="0"><?php esc_html_e('Todos los vehículos', 'tur-transportes'); ?></option>
                                <?php if (!empty($vehicles)) : ?>
                                    <?php foreach ($vehicles as $v) : ?>
                                        <option value="<?php echo esc_attr($v->id); ?>">
                                            <?php echo esc_html($v->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th><?php esc_html_e('Tipo de Regla', 'tur-transportes'); ?></th>
                        <td>
                            <select name="rule_type" id="rule_type">
                                <option value="festivo"><?php esc_html_e('Festivo', 'tur-transportes'); ?></option>
                                <option value="hora_pico"><?php esc_html_e('Horario', 'tur-transportes'); ?></option>
                                <option value="temporada"><?php esc_html_e('Temporada', 'tur-transportes'); ?></option>
                                <option value="dia_semana"><?php esc_html_e('Día de la Semana', 'tur-transportes'); ?></option>
                            </select>
                        </td>
                    </tr>

                    <!-- Festivo -->
                    <tr class="festivo-fields">
                        <th><?php esc_html_e('Festivo', 'tur-transportes'); ?></th>
                        <td>
                            <select name="festivo_date">
                                <?php if (!empty($festivos)) : ?>
                                    <?php foreach ($festivos as $fecha => $nombre) : ?>
                                        <option value="<?php echo esc_attr($fecha); ?>">
                                            <?php echo esc_html($nombre . ' (' . $fecha . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </td>
                    </tr>

                    <!-- Horario (hora pico) -->
                    <tr class="hora-pico-fields" style="display:none;">
                        <th><?php esc_html_e('Horario', 'tur-transportes'); ?></th>
                        <td>
                            <input type="time" name="start_time" value="07:00">
                            <?php esc_html_e('a', 'tur-transportes'); ?>
                            <input type="time" name="end_time" value="09:00">
                        </td>
                    </tr>

                    <!-- Temporada -->
                    <tr class="temporada-fields" style="display:none;">
                        <th><?php esc_html_e('Rango de Temporada', 'tur-transportes'); ?></th>
                        <td>
                            <input type="date" name="season_start">
                            <?php esc_html_e('a', 'tur-transportes'); ?>
                            <input type="date" name="season_end">
                        </td>
                    </tr>

                    <!-- Días de la semana -->
                    <tr class="dia-semana-fields" style="display:none;">
                        <th><?php esc_html_e('Días de la Semana', 'tur-transportes'); ?></th>
                        <td>
                            <label><input type="checkbox" name="dias_semana[]" value="1"> <?php esc_html_e('Lunes', 'tur-transportes'); ?></label><br>
                            <label><input type="checkbox" name="dias_semana[]" value="2"> <?php esc_html_e('Martes', 'tur-transportes'); ?></label><br>
                            <label><input type="checkbox" name="dias_semana[]" value="3"> <?php esc_html_e('Miércoles', 'tur-transportes'); ?></label><br>
                            <label><input type="checkbox" name="dias_semana[]" value="4"> <?php esc_html_e('Jueves', 'tur-transportes'); ?></label><br>
                            <label><input type="checkbox" name="dias_semana[]" value="5"> <?php esc_html_e('Viernes', 'tur-transportes'); ?></label><br>
                            <label><input type="checkbox" name="dias_semana[]" value="6"> <?php esc_html_e('Sábado', 'tur-transportes'); ?></label><br>
                            <label><input type="checkbox" name="dias_semana[]" value="7"> <?php esc_html_e('Domingo', 'tur-transportes'); ?></label>
                        </td>
                    </tr>

                    <tr>
                        <th><?php esc_html_e('Recargo', 'tur-transportes'); ?></th>
                        <td>
                            <select name="surcharge_type">
                                <option value="percentage"><?php esc_html_e('Porcentaje %', 'tur-transportes'); ?></option>
                                <option value="fixed"><?php esc_html_e('Monto Fijo $', 'tur-transportes'); ?></option>
                            </select>
                            <input type="number" name="surcharge_value" step="0.01" min="0" required>
                        </td>
                    </tr>
                </table>

                <input type="hidden" name="tt_pricing_rules_action" value="add_rule">

                <button type="submit" class="button button-primary">
                    <?php esc_html_e('Guardar Regla', 'tur-transportes'); ?>
                </button>
            </form>
        </div>

        <div class="tt-list-section">
            <h3><?php esc_html_e('Reglas Activas', 'tur-transportes'); ?></h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Vehículo', 'tur-transportes'); ?></th>
                        <th><?php esc_html_e('Tipo', 'tur-transportes'); ?></th>
                        <th><?php esc_html_e('Condición', 'tur-transportes'); ?></th>
                        <th><?php esc_html_e('Recargo', 'tur-transportes'); ?></th>
                        <th><?php esc_html_e('Acciones', 'tur-transportes'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($pricing_rules)) : ?>
                        <?php foreach ($pricing_rules as $rule) : ?>
                            <tr>
                                <td>
                                    <?php
                                    // Asumiendo que tienes vehicle_name, o fallback a "Todos"
                                    echo isset($rule->vehicle_name)
                                        ? esc_html($rule->vehicle_name)
                                        : esc_html__('Todos los vehículos', 'tur-transportes');
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    // Asumiendo rule_type con valores festivo, hora_pico, temporada, dia_semana
                                    $type_label = '';
                                    switch ($rule->rule_type ?? '') {
                                        case 'festivo':
                                            $type_label = __('Festivo', 'tur-transportes');
                                            break;
                                        case 'hora_pico':
                                            $type_label = __('Horario', 'tur-transportes');
                                            break;
                                        case 'temporada':
                                            $type_label = __('Temporada', 'tur-transportes');
                                            break;
                                        case 'dia_semana':
                                            $type_label = __('Día de la Semana', 'tur-transportes');
                                            break;
                                        default:
                                            $type_label = __('Desconocido', 'tur-transportes');
                                            break;
                                    }
                                    echo esc_html($type_label);
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    // Muestra una descripción humana de la condición
                                    if (!empty($rule->condition_label)) {
                                        echo esc_html($rule->condition_label);
                                    } else {
                                        echo esc_html__('(Sin descripción)', 'tur-transportes');
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    if (!empty($rule->surcharge_type) && isset($rule->surcharge_value)) {
                                        if ($rule->surcharge_type === 'percentage') {
                                            echo esc_html($rule->surcharge_value . '%');
                                        } else {
                                            echo esc_html('$' . number_format((float)$rule->surcharge_value, 0, ',', '.'));
                                        }
                                    } else {
                                        echo esc_html__('N/D', 'tur-transportes');
                                    }
                                    ?>
                                </td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('tt_delete_pricing_rule', 'tt_delete_pricing_rule_nonce'); ?>
                                        <input type="hidden" name="tt_pricing_rules_action" value="delete_rule">
                                        <input type="hidden" name="rule_id" value="<?php echo esc_attr($rule->id); ?>">
                                        <button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js(__('¿Eliminar esta regla?', 'tur-transportes')); ?>');">
                                            <?php esc_html_e('Eliminar', 'tur-transportes'); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="5">
                                <?php esc_html_e('No hay reglas de precio configuradas.', 'tur-transportes'); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(function($){
    function ttUpdateRuleFields() {
        var type = $('#rule_type').val();

        $('.festivo-fields').toggle(type === 'festivo');
        $('.hora-pico-fields').toggle(type === 'hora_pico');
        $('.temporada-fields').toggle(type === 'temporada');
        $('.dia-semana-fields').toggle(type === 'dia_semana');
    }

    $('#rule_type').on('change', ttUpdateRuleFields);
    ttUpdateRuleFields();
});
</script>
