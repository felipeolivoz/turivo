<?php
if (!defined('ABSPATH')) exit;

global $wpdb;
$table_name = $wpdb->prefix . 'tt_vehicle_types';

/* ==========================================
   PROCESAR ACCIONES
========================================== */
if (isset($_POST['add_vehicle'])) {
    check_admin_referer('tt_add_vehicle');

    $wpdb->insert($table_name, [
        'name' => sanitize_text_field($_POST['name']),
        'description' => sanitize_textarea_field($_POST['description']),
        'capacity' => intval($_POST['capacity']),
        'price_per_km' => floatval($_POST['price_per_km']),
        'base_price' => floatval($_POST['base_price']),
        'price_per_passenger' => floatval($_POST['price_per_passenger']),
        'factor_vehiculo' => floatval($_POST['factor_vehiculo'])
    ]);

    $message = 'added';
}

if (isset($_POST['update_vehicle'])) {
    check_admin_referer('tt_update_vehicle');

    $vehicle_id = intval($_POST['vehicle_id']);

    $wpdb->update(
        $table_name,
        [
            'name' => sanitize_text_field($_POST['name']),
            'description' => sanitize_textarea_field($_POST['description']),
            'capacity' => intval($_POST['capacity']),
            'price_per_km' => floatval($_POST['price_per_km']),
            'base_price' => floatval($_POST['base_price']),
            'price_per_passenger' => floatval($_POST['price_per_passenger']),
            'factor_vehiculo' => floatval($_POST['factor_vehiculo'])
        ],
        ['id' => $vehicle_id]
    );

    $message = 'updated';
}

if (isset($_GET['delete_vehicle'])) {
    $vehicle_id = intval($_GET['delete_vehicle']);
    $wpdb->update($table_name, ['active' => 0], ['id' => $vehicle_id]);
    $message = 'deleted';
}

/* ==========================================
   EDITAR VEHÍCULO
========================================== */
$editing_vehicle = null;
if (isset($_GET['edit_vehicle'])) {
    $editing_vehicle = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", intval($_GET['edit_vehicle']))
    );
}

/* ==========================================
   LISTA DE VEHÍCULOS ACTIVOS
========================================== */
$vehicles = $wpdb->get_results("SELECT * FROM $table_name WHERE active = 1");

function format_price_chilean($price) {
    return '$' . number_format(floatval($price), 0, ',', '.');
}
?>

<div class="wrap">
    <h1 class="wp-heading-inline">Tipos de Vehículos</h1>
    <hr class="wp-header-end">

    <?php if (!empty($message)) : ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php echo $message === 'added' ? 'Vehículo agregado correctamente.' :
                            ($message === 'updated' ? 'Vehículo actualizado correctamente.' :
                            'Vehículo eliminado correctamente.'); ?>
            </p>
        </div>
    <?php endif; ?>

    <div id="poststuff">

        <!-- ===========================
             FORMULARIO ESTILO WORDPRESS
        ============================ -->
   <div class="postbox">
    <h2 class="hndle"><span><?php echo $editing_vehicle ? 'Editar Vehículo' : 'Agregar Nuevo Vehículo'; ?></span></h2>

    <div class="inside">
        <form method="post" class="tt-form-grid">

            <?php 
                if ($editing_vehicle) wp_nonce_field('tt_update_vehicle');
                else wp_nonce_field('tt_add_vehicle');
            ?>

            <?php if ($editing_vehicle): ?>
                <input type="hidden" name="vehicle_id" value="<?php echo esc_attr($editing_vehicle->id); ?>">
            <?php endif; ?>

            <!-- NOMBRE -->
            <div class="tt-field">
                <label for="name">Nombre</label>
                <input type="text" id="name" name="name"
                       value="<?php echo esc_attr($editing_vehicle->name ?? ''); ?>" required>
            </div>

            <!-- CAPACIDAD -->
            <div class="tt-field">
                <label for="capacity">Capacidad</label>
                <input type="number" min="1" max="50" id="capacity" name="capacity"
                       value="<?php echo esc_attr($editing_vehicle->capacity ?? ''); ?>" required>
            </div>

            <!-- DESCRIPCIÓN (FILA COMPLETA) -->
            <div class="tt-field tt-full">
                <label for="description">Descripción</label>
                <textarea id="description" name="description" rows="3"><?php 
                    echo esc_textarea($editing_vehicle->description ?? ''); 
                ?></textarea>
            </div>

            <!-- BASE PRICE -->
            <div class="tt-field">
                <label for="base_price">Precio Base</label>
                <input type="number" min="0" step="1" id="base_price" name="base_price"
                       value="<?php echo esc_attr($editing_vehicle->base_price ?? ''); ?>" required>
            </div>

            <!-- PRICE PER KM -->
            <div class="tt-field">
                <label for="price_per_km">Precio por KM</label>
                <input type="number" min="0" step="1" id="price_per_km" name="price_per_km"
                       value="<?php echo esc_attr($editing_vehicle->price_per_km ?? ''); ?>" required>
            </div>

            <!-- PRICE PER PASSENGER -->
            <div class="tt-field">
                <label for="price_per_passenger">Precio por Pasajero</label>
                <input type="number" min="0" step="1" id="price_per_passenger" name="price_per_passenger"
                       value="<?php echo esc_attr($editing_vehicle->price_per_passenger ?? '0'); ?>">
            </div>

            <!-- FACTOR -->
            <div class="tt-field">
                <label for="factor_vehiculo">Factor Vehículo</label>
                <input type="number" min="0" step="0.01" id="factor_vehiculo" name="factor_vehiculo"
                       value="<?php echo esc_attr($editing_vehicle->factor_vehiculo ?? '1.00'); ?>" required>
            </div>

            <!-- ACCIONES -->
            <div class="tt-full tt-actions">
                <?php if ($editing_vehicle): ?>
                    <?php submit_button('Actualizar Vehículo', 'primary', 'update_vehicle'); ?>
                    <a href="<?php echo admin_url('admin.php?page=tt-vehicles'); ?>" class="button">Cancelar</a>
                <?php else: ?>
                    <?php submit_button('Agregar Vehículo', 'primary', 'add_vehicle'); ?>
                <?php endif; ?>
            </div>

        </form>
    </div>
</div>






        <!-- ===========================
             TABLA DE VEHÍCULOS (WP STYLE)
        ============================ -->
        <div class="postbox">
            <h2 class="hndle"><span>Vehículos Existentes</span></h2>

            <div class="inside">

                <?php if ($vehicles): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Descripción</th>
                            <th>Cap.</th>
                            <th>Base</th>
                            <th>x KM</th>
                            <th>x Pasajero</th>
                            <th>Factor</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($vehicles as $v): ?>
                        <tr>
                            <td><?php echo esc_html($v->name); ?></td>
                            <td><?php echo esc_html($v->description); ?></td>
                            <td><?php echo intval($v->capacity); ?></td>
                            <td><?php echo format_price_chilean($v->base_price); ?></td>
                            <td><?php echo format_price_chilean($v->price_per_km); ?></td>
                            <td><?php echo format_price_chilean($v->price_per_passenger); ?></td>
                            <td><?php echo esc_html($v->factor_vehiculo); ?></td>
                           <td>
    <div class="tt-actions-col">
        <a class="button button-primary"
           href="<?php echo admin_url('admin.php?page=tt-vehicles&edit_vehicle=' . $v->id); ?>">
           Editar
        </a>

        <a class="button button-secondary"
           onclick="return confirm('¿Eliminar este vehículo?');"
           href="<?php echo admin_url('admin.php?page=tt-vehicles&delete_vehicle=' . $v->id); ?>">
           Eliminar
        </a>
    </div>
</td>

                        </tr>
                    <?php endforeach; ?>
                    </tbody>

                </table>

                <?php else: ?>
                    <p>No hay vehículos registrados.</p>
                <?php endif; ?>

            </div>
        </div>

    </div>
</div>
<style>
/* GRID PROFESIONAL (2 COLUMNAS) */
.tt-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px 25px;
    margin-top: 10px;
}

/* FILAS DE ANCHO COMPLETO */
.tt-full {
    grid-column: span 2;
}

/* CAMPOS */
.tt-field label {
    font-size: 13px;
    font-weight: 600;
    color: #23282d;
    margin-bottom: 4px;
    display: block;
}

.tt-field input,
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

/* CONTENEDOR DE BOTONES ALINEADO */
.tt-actions-col {
    display: flex;
    gap: 6px;          /* separación horizontal */
    align-items: center;
}

/* BOTÓN EDITAR: estilo WP primary */
.tt-actions-col .button-primary {
    background: #2271b1 !important;
    border-color: #135e96 !important;
    color: #fff !important;
    padding: 4px 10px !important;
    font-size: 12px !important;
}

/* BOTÓN ELIMINAR: estilo WP secondary + rojo suave */
.tt-actions-col .button-secondary {
    padding: 4px 6px !important;
    font-size: 12px !important;
    border-color: #cc0000 !important;
    color: #cc0000 !important;
    background: #fff !important;
}

.tt-actions-col .button-secondary:hover {
    background: #ffecec !important;
    border-color: #a70000 !important;
    color: #a70000 !important;
}
.wp-list-table td {
    vertical-align: middle;
}
.wp-list-table th:last-child,
.wp-list-table td:last-child {
    width: 120px; /* Ajuste perfecto */
}


</style>