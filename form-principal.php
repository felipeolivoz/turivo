<?php 
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="tt-booking-form-modern">
    <!-- Encabezado moderno -->
    <div class="tt-form-header">
        <h2><?php _e('Reserva de Traslado', 'tur-transportes'); ?></h2>
        <p><?php _e('Complete los datos para su reserva de traslado', 'tur-transportes'); ?></p>
    </div>
    
    <!-- Indicador de pasos moderno (3 pasos) -->
    <input type="hidden" id="tt-nonce" name="tt_nonce" value="<?php echo wp_create_nonce('tt_booking_nonce'); ?>">
    <div class="tt-step-indicator">
        <div class="tt-step active" data-step="1">
            <div class="tt-step-circle">1</div>
            <div class="tt-step-label"><?php _e('Información del Viaje', 'tur-transportes'); ?></div>
        </div>
        <div class="tt-step" data-step="2">
            <div class="tt-step-circle">2</div>
            <div class="tt-step-label"><?php _e('Datos del Cliente', 'tur-transportes'); ?></div>
        </div>
        <div class="tt-step" data-step="3">
            <div class="tt-step-circle">3</div>
            <div class="tt-step-label"><?php _e('Resumen y Pago', 'tur-transportes'); ?></div>
        </div>
    </div>
    
    <!-- Contenido del formulario -->
    <div class="tt-form-content">
        <form id="tt-main-form" method="post">
            <?php wp_nonce_field('tt_booking_nonce', 'tt_nonce'); ?>
            
            <!-- Paso 1: Información del Viaje -->
            <div class="tt-form-step active" id="step-1">
                <div class="tt-form-grid">
                    <div class="tt-form-group">
                        <input type="text" id="tt-origin" name="origin" class="tt-form-control tt-address-input" placeholder=" " required>
                        <label for="tt-origin" class="tt-floating-label"><?php _e('Origen', 'tur-transportes'); ?></label>
                        <span class="tt-error-msg"></span>
                    </div>
                    
                    <div class="tt-form-group">
                        <input type="text" id="tt-destination" name="destination" class="tt-form-control tt-address-input" placeholder=" " required>
                        <label for="tt-destination" class="tt-floating-label"><?php _e('Destino', 'tur-transportes'); ?></label>
                        <span class="tt-error-msg"></span>
                    </div>
                    
                    <div class="tt-form-row">
                        <div class="tt-form-group">
                            <input type="date" id="tt-date" name="transfer_date" class="tt-form-control" required min="<?php echo date('Y-m-d'); ?>">
                            <label for="tt-date" class="tt-floating-label"><?php _e('Fecha', 'tur-transportes'); ?></label>
                            <span class="tt-error-msg"></span>
                        </div>
                        
                        <div class="tt-form-group">
                            <input type="time" id="tt-time" name="transfer_time" class="tt-form-control" required>
                            <label for="tt-time" class="tt-floating-label"><?php _e('Hora', 'tur-transportes'); ?></label>
                            <span class="tt-error-msg"></span>
                        </div>
                    </div>
                    
                    <div class="tt-form-row">
                        <div class="tt-form-group">
                            <input type="number" id="tt-passengers" name="passengers" class="tt-form-control" required min="1" max="20" value="1">
                            <label for="tt-passengers" class="tt-floating-label"><?php _e('Número de Pasajeros', 'tur-transportes'); ?></label>
                            <span class="tt-error-msg"></span>
                        </div>
                        
                        <div class="tt-form-group">
                            <select id="tt-transfer-type" name="transfer_type_id" class="tt-form-control" required>
                                <option value=""><?php _e('Seleccionar tipo de traslado', 'tur-transportes'); ?></option>
                                <!-- Los options se cargarán via JavaScript -->
                            </select>
                            <label for="tt-transfer-type" class="tt-floating-label"><?php _e('Tipo de Traslado', 'tur-transportes'); ?></label>
                            <span class="tt-error-msg"></span>
                        </div>
                    </div>
                </div>
                
                <div class="tt-form-actions">
                    <div></div>
                    <button type="button" class="tt-next-btn" data-next="2">
                        <?php _e('Siguiente', 'tur-transportes'); ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 12h14M12 5l7 7-7 7"/>
                        </svg>
                    </button>
                </div>
            </div>
            
            <!-- Paso 2: Datos del Cliente -->
            <div class="tt-form-step" id="step-2">
                <div class="tt-form-grid">
                    <div class="tt-form-group">
                        <input type="text" id="tt-customer-rut" name="customer_rut" class="tt-form-control" placeholder=" ">
                        <label for="tt-customer-rut" class="tt-floating-label"><?php _e('RUT', 'tur-transportes'); ?></label>
                        <span class="tt-error-msg"></span>
                    </div>

                    <div class="tt-form-group full-width">
                        <input type="text" id="tt-customer-name" name="customer_name" class="tt-form-control" placeholder=" " required>
                        <label for="tt-customer-name" class="tt-floating-label"><?php _e('Nombre Completo', 'tur-transportes'); ?></label>
                        <span class="tt-error-msg"></span>
                    </div>
                    
                    <div class="tt-form-group">
                        <input type="email" id="tt-customer-email" name="customer_email" class="tt-form-control" placeholder=" " required>
                        <label for="tt-customer-email" class="tt-floating-label"><?php _e('Email', 'tur-transportes'); ?></label>
                        <span class="tt-error-msg"></span>
                    </div>
                    
                    <div class="tt-form-group">
                        <input type="tel" id="tt-customer-phone" name="customer_phone" class="tt-form-control" placeholder=" " required>
                        <label for="tt-customer-phone" class="tt-floating-label"><?php _e('Teléfono', 'tur-transportes'); ?></label>
                        <span class="tt-error-msg"></span>
                    </div>
                    
                    <div class="tt-form-group full-width">
                        <select id="tt-vehicle-type" name="vehicle_type_id" class="tt-form-control" required>
                            <option value=""><?php _e('Seleccionar tipo de vehículo', 'tur-transportes'); ?></option>
                            <!-- Los options se cargarán via JavaScript -->
                        </select>
                        <label for="tt-vehicle-type" class="tt-floating-label"><?php _e('Tipo de Vehículo', 'tur-transportes'); ?></label>
                        <span class="tt-error-msg"></span>
                    </div>
                </div>
                
                <!-- Paradas intermedias -->
                <div id="tt-intermediate-stops" style="display:none;">
                    <div class="tt-form-group full-width">
                        <label class="tt-section-label"><?php _e('Paradas Intermedias', 'tur-transportes'); ?></label>
                        <div class="tt-stops-container" id="tt-stops-container">
                            <div class="tt-stop-item">
                                <input type="text" class="tt-form-control tt-stop-input tt-address-input" placeholder="Dirección de parada">
                                <button type="button" class="tt-remove-stop">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M18 6L6 18M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <button type="button" id="tt-add-stop" class="tt-add-stop-btn">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 5v14M5 12h14"/>
                            </svg>
                            <?php _e('Agregar Otra Parada', 'tur-transportes'); ?>
                        </button>
                        <span class="tt-error-msg" id="tt-stops-error"></span>
                    </div>
                </div>
                
                <div class="tt-form-actions">
                    <button type="button" class="tt-prev-btn" data-prev="1">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 12H5M12 19l-7-7 7-7"/>
                        </svg>
                        <?php _e('Anterior', 'tur-transportes'); ?>
                    </button>
                    <button type="button" class="tt-next-btn" data-next="3">
                        <?php _e('Ver Resumen', 'tur-transportes'); ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 12h14M12 5l7 7-7 7"/>
                        </svg>
                    </button>
                </div>
            </div>
            
            <!-- Paso 3: Resumen y Pago -->
            <div class="tt-form-step" id="step-3">
                <div id="tt-summary-container">
                    <!-- El resumen se cargará en el modal -->
                    <div class="tt-step3-placeholder">
                        <div class="tt-placeholder-content">
                            <div class="tt-placeholder-icon">🚗</div>
                            <h3>Resumen Listo</h3>
                            <p>Tu reserva está casi lista. Haz clic en el botón para revisar todos los detalles y proceder al pago.</p>
                            <button type="button" class="tt-show-summary-btn">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M9 19V5l11 7-11 7z"/>
                                </svg>
                                Ver Resumen Completo
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="tt-form-actions">
                    <button type="button" class="tt-prev-btn" data-prev="2">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 12H5M12 19l-7-7 7-7"/>
                        </svg>
                        Editar Datos
                    </button>
                    <div class="tt-form-actions-right">
                        <span class="tt-error-msg tt-error-global" id="tt-step3-error"></span>
                        <button type="button" class="tt-submit-btn tt-pay-btn" disabled>
                            <?php _e('Pagar con Transbank', 'tur-transportes'); ?>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 12H4M12 4l8 8-8 8"/>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

<style>
/* Estilos para el placeholder del paso 3 */
.tt-step3-placeholder {
    text-align: center;
    padding: 60px 30px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-radius: 16px;
    border: 2px dashed #e2e8f0;
}

.tt-placeholder-content {
    max-width: 400px;
    margin: 0 auto;
}

.tt-placeholder-icon {
    font-size: 4rem;
    margin-bottom: 20px;
    opacity: 0.7;
}

.tt-step3-placeholder h3 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--tt-secondary);
    margin-bottom: 10px;
}

.tt-step3-placeholder p {
    color: var(--tt-gray);
    margin-bottom: 25px;
    line-height: 1.5;
}

.tt-show-summary-btn {
    background: linear-gradient(135deg, var(--tt-primary) 0%, var(--tt-primary-light) 100%);
    color: white;
    border: none;
    padding: 15px 30px;
    border-radius: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: var(--tt-transition);
    display: inline-flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 8px 25px rgba(252, 113, 29, 0.3);
}

.tt-show-summary-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 35px rgba(252, 113, 29, 0.4);
}

/* Mensajes de error inline */
.tt-error-msg {
    display: block;
    margin-top: 6px;
    font-size: 0.8rem;
    color: var(--tt-error);
    font-weight: 600;
    padding-left: 4px;
}

.tt-error-global {
    text-align: right;
    min-height: 18px;
    margin-right: 10px;
}
</style>

        </form>
    </div>
</div>