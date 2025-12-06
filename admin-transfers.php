<?php
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$table_name = $wpdb->prefix . 'tt_transfer_types';

// Procesar acciones
if (isset($_POST['add_transfer'])) {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'tt_add_transfer')) {
        wp_die('Error de seguridad');
    }

    $stop_price = isset($_POST['stop_price']) ? floatval($_POST['stop_price']) : 0;

    $wpdb->insert(
        $table_name,
        array(
            'name'         => sanitize_text_field($_POST['name']),
            'description'  => sanitize_textarea_field($_POST['description']),
            'allows_stops' => isset($_POST['allows_stops']) ? 1 : 0,
            'stop_price'   => $stop_price,
            'active'       => 1,
        )
    );

    if ($wpdb->insert_id) {
        add_settings_error('tt_transfers', 'transfer_added', 'Tipo de traslado agregado correctamente.', 'success');
    } else {
        add_settings_error('tt_transfers', 'transfer_error', 'Error al agregar el tipo de traslado.', 'error');
    }
}

// Actualizar transfer existente
if (isset($_POST['update_transfer'])) {
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'tt_update_transfer')) {
        wp_die('Error de seguridad');
    }

    $transfer_id = intval($_POST['transfer_id']);
    $stop_price  = isset($_POST['stop_price']) ? floatval($_POST['stop_price']) : 0;

    $result = $wpdb->update(
        $table_name,
        array(
            'name'         => sanitize_text_field($_POST['name']),
            'description'  => sanitize_textarea_field($_POST['description']),
            'allows_stops' => isset($_POST['allows_stops']) ? 1 : 0,
            'stop_price'   => $stop_price,
        ),
        array('id' => $transfer_id),
        array('%s', '%s', '%d', '%f'),
        array('%d')
    );

    if ($result !== false) {
        add_settings_error('tt_transfers', 'transfer_updated', 'Tipo de traslado actualizado correctamente.', 'success');
    } else {
        add_settings_error('tt_transfers', 'transfer_update_error', 'Error al actualizar el tipo de traslado.', 'error');
    }
}

// Eliminar transfer (borrado físico, coherente con tu implementación actual)
if (isset($_GET['delete_transfer'])) {
    $transfer_id = intval($_GET['delete_transfer']);

    $deleted = $wpdb->delete($table_name, array('id' => $transfer_id), array('%d'));

    if ($deleted !== false) {
        add_settings_error('tt_transfers', 'transfer_deleted', 'Tipo de traslado eliminado correctamente.', 'success');
    } else {
        add_settings_error('tt_transfers', 'transfer_delete_error', 'Error al eliminar el tipo de traslado.', 'error');
    }
}

// Solo mostramos activos (la lógica actual del plugin)
$transfers = $wpdb->get_results("SELECT * FROM $table_name WHERE active = 1 ORDER BY name");
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Tipos de Traslado</h1>
    <hr class="wp-header-end">

    <?php settings_errors('tt_transfers'); ?>

    <div id="poststuff">

        <!-- ===========================
             FORMULARIO ESTILO WORDPRESS
        ============================ -->
        <div class="postbox">
            <h2 class="hndle"><span>Agregar Nuevo Tipo de Traslado</span></h2>

            <div class="inside">
                <form method="post" class="tt-form-grid">
                    
                    <?php wp_nonce_field('tt_add_transfer'); ?>

                    <!-- NOMBRE -->
                    <div class="tt-field">
                        <label for="name">Nombre del Traslado</label>
                        <input type="text" id="name" name="name" required>
                    </div>

                    <!-- PARADAS -->
<div class="tt-field">
    <label for="allows_stops">Paradas intermedias</label>

    <div class="tt-checkbox-wp">
        <input type="checkbox" id="allows_stops" name="allows_stops" value="1"
               onchange="toggleStopPrice(this)">
        <span>Permitir paradas</span>
    </div>
</div>

                    <!-- DESCRIPCIÓN -->
                    <div class="tt-field tt-full">
                        <label for="description">Descripción</label>
                        <textarea id="description" name="description" rows="3"></textarea>
                    </div>

                    <!-- PRECIO PARADA -->
                    <div class="tt-field" id="stop_price_group" style="display:none;">
                        <label for="stop_price">Precio por Parada</label>
                        <input type="number" id="stop_price" name="stop_price" min="0" step="50" value="0">
                    </div>

                    <!-- ACCIONES -->
                    <div class="tt-full tt-actions">
                        <?php submit_button('Agregar Traslado', 'primary', 'add_transfer'); ?>
                    </div>

                </form>
            </div>
        </div>



        <!-- ===========================
             LISTA DE TRASLADOS
        ============================ -->
        <div class="postbox">
            <h2 class="hndle"><span>Tipos de Traslado Existentes</span></h2>

            <div class="inside">

                <?php if ($transfers): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Descripción</th>
                            <th>Paradas</th>
                            <th>Precio por Parada</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($transfers as $t): ?>
                            <tr>
                                <td><?php echo esc_html($t->name); ?></td>
                                <td><?php echo esc_html($t->description); ?></td>

                                <td>
                                    <?php echo $t->allows_stops ? 'Permitidas' : 'No Permitidas'; ?>
                                </td>

                                <td>
                                    <?php 
                                        echo $t->allows_stops && $t->stop_price > 0 
                                            ? '$' . number_format($t->stop_price, 0, ',', '.') 
                                            : '-';
                                    ?>
                                </td>

                                <td>
                                    <div class="tt-actions-col">
                                        <button type="button" class="button button-primary"
                                                onclick="toggleEditForm(<?php echo $t->id; ?>)">
                                            Editar
                                        </button>

                                        <a class="button button-secondary"
                                           onclick="return confirm('¿Eliminar este tipo de traslado?');"
                                           href="<?php echo admin_url('admin.php?page=tt-transfers&delete_transfer=' . $t->id); ?>">
                                            Eliminar
                                        </a>
                                    </div>
                                </td>
                            </tr>

                            <!-- FORMULARIO DE EDICIÓN INLINE (ESTILO WP) -->
                            <tr id="edit-form-<?php echo $t->id; ?>" class="tt-edit-row" style="display:none;">
                                <td colspan="5">
                                    <form method="post" class="tt-form-grid">
                                        <?php wp_nonce_field('tt_update_transfer'); ?>

                                        <input type="hidden" name="transfer_id" value="<?php echo $t->id; ?>">

                                        <div class="tt-field">
                                            <label>Nombre</label>
                                            <input type="text" name="name" value="<?php echo esc_attr($t->name); ?>" required>
                                        </div>

                                        <div class="tt-field">
                                            <label>
                                                <input type="checkbox" name="allows_stops" value="1"
                                                       id="allows_stops_<?php echo $t->id; ?>"
                                                       <?php echo $t->allows_stops ? 'checked' : ''; ?>
                                                       onchange="toggleEditStopPrice(<?php echo $t->id; ?>)">
                                                Permitir paradas
                                            </label>
                                        </div>

                                        <div class="tt-field tt-full">
                                            <label>Descripción</label>
                                            <textarea name="description" rows="2"><?php echo esc_textarea($t->description); ?></textarea>
                                        </div>

                                        <div class="tt-field" 
                                             id="edit_stop_price_<?php echo $t->id; ?>"
                                             style="<?php echo $t->allows_stops ? '' : 'display:none'; ?>">
                                            <label>Precio por parada</label>
                                            <input type="number" name="stop_price" 
                                                   value="<?php echo esc_attr($t->stop_price); ?>" min="0" step="50">
                                        </div>

                                        <div class="tt-full tt-actions">
                                            <?php submit_button('Guardar Cambios', 'primary', 'update_transfer'); ?>
                                            <button type="button" class="button" onclick="toggleEditForm(<?php echo $t->id; ?>)">Cancelar</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>

                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php else: ?>
                    <p>No hay tipos de traslado registrados.</p>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>



<style>
/* GRID STYLE (mismo del módulo vehículos) */
.tt-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px 25px;
    margin-top: 10px;
}

.tt-full {
    grid-column: span 2;
}

.tt-field label {
    font-size: 13px;
    font-weight: 600;
    color: #23282d;
    margin-bottom: 4px;
    display: block;
}

/* Aplicar estilos solo a inputs de texto, número, email, etc. */
.tt-field input:not([type="checkbox"]):not([type="radio"]):not([type="submit"]),
.tt-field textarea {
    width: 100%;
    padding: 7px 10px;
    border-radius: 4px;
    border: 1px solid #ccd0d4;
    background: #fff;
    box-sizing: border-box;
}


.tt-field input:focus,
.tt-field textarea:focus {
    border-color: #007cba;
    box-shadow: 0 0 0 1px #007cba;
    outline: none;
}

.tt-checkbox-wp {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 4px;
}

.tt-checkbox-wp input[type="checkbox"] {
    margin: 0;
}


.tt-actions {
    margin-top: 10px;
}

/* Tabla */
.wp-list-table td {
    vertical-align: middle;
}

.tt-actions-col {
    display: flex;
    gap: 6px;
}

.tt-edit-row {
    background: #f1f1f1;
}

</style>


<script>
function toggleStopPrice(checkbox) {
    var stopPriceGroup = document.getElementById('stop_price_group');
    if (checkbox.checked) {
        stopPriceGroup.style.display = 'block';
    } else {
        stopPriceGroup.style.display = 'none';
    }
}

function toggleEditForm(transferId) {
    var editForm = document.getElementById('edit-form-' + transferId);
    if (editForm.style.display === 'none') {
        editForm.style.display = 'block';
    } else {
        editForm.style.display = 'none';
    }
}

function toggleEditStopPrice(transferId) {
    var checkbox = document.getElementById('allows_stops_' + transferId);
    var stopPriceGroup = document.getElementById('edit_stop_price_' + transferId);
    if (checkbox.checked) {
        stopPriceGroup.style.display = 'block';
    } else {
        stopPriceGroup.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    var allowsStopsCheckbox = document.getElementById('allows_stops');
    if (allowsStopsCheckbox) {
        toggleStopPrice(allowsStopsCheckbox);
    }
});
</script>
