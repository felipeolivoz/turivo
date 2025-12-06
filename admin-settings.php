<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php _e('Configuración - Tur Transportes', 'tur-transportes'); ?></h1>
    
    <form method="post" action="options.php">
        <?php settings_fields('tt_settings'); ?>
        <?php do_settings_sections('tt_settings'); ?>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="tt_google_maps_api_key"><?php _e('Google Maps API Key', 'tur-transportes'); ?></label>
                </th>
                <td>
                    <input type="password" id="tt_google_maps_api_key" name="tt_google_maps_api_key" 
                           value="<?php echo esc_attr(get_option('tt_google_maps_api_key')); ?>" 
                           class="regular-text" />
                    <p class="description">
                        <?php _e('API key de Google Maps para calcular distancias y rutas', 'tur-transportes'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="tt_currency"><?php _e('Moneda', 'tur-transportes'); ?></label>
                </th>
                <td>
                    <select id="tt_currency" name="tt_currency" class="regular-text">
                        <option value="CLP" <?php selected(get_option('tt_currency'), 'CLP'); ?>>CLP ($)</option>
                        <option value="USD" <?php selected(get_option('tt_currency'), 'USD'); ?>>USD ($)</option>
                        <option value="EUR" <?php selected(get_option('tt_currency'), 'EUR'); ?>>EUR (€)</option>
                    </select>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="tt_company_name"><?php _e('Nombre de la Empresa', 'tur-transportes'); ?></label>
                </th>
                <td>
                    <input type="text" id="tt_company_name" name="tt_company_name" 
                           value="<?php echo esc_attr(get_option('tt_company_name', 'Tur Transportes')); ?>" 
                           class="regular-text" />
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="tt_contact_email"><?php _e('Email de Contacto', 'tur-transportes'); ?></label>
                </th>
                <td>
                    <input type="email" id="tt_contact_email" name="tt_contact_email" 
                           value="<?php echo esc_attr(get_option('tt_contact_email')); ?>" 
                           class="regular-text" />
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="tt_contact_phone"><?php _e('Teléfono de Contacto', 'tur-transportes'); ?></label>
                </th>
                <td>
                    <input type="text" id="tt_contact_phone" name="tt_contact_phone" 
                           value="<?php echo esc_attr(get_option('tt_contact_phone')); ?>" 
                           class="regular-text" />
                </td>
            </tr>
        </table>
        
        <!-- CONFIGURACIÓN TRANSBANK -->
        <h2><?php _e('Configuración Transbank', 'tur-transportes'); ?></h2>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="tt_transbank_environment"><?php _e('Ambiente', 'tur-transportes'); ?></label>
                </th>
                <td>
                    <select id="tt_transbank_environment" name="tt_transbank_environment">
                        <option value="integration" <?php selected(get_option('tt_transbank_environment'), 'integration'); ?>>
                            <?php _e('Integración (Pruebas)', 'tur-transportes'); ?>
                        </option>
                        <option value="production" <?php selected(get_option('tt_transbank_environment'), 'production'); ?>>
                            <?php _e('Producción', 'tur-transportes'); ?>
                        </option>
                    </select>
                    <p class="description">
                        <?php _e('Usa "Integración" para pruebas y "Producción" para el entorno real', 'tur-transportes'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="tt_transbank_commerce_code"><?php _e('Código de Comercio', 'tur-transportes'); ?></label>
                </th>
                <td>
                    <input type="text" id="tt_transbank_commerce_code" name="tt_transbank_commerce_code" 
                           value="<?php echo esc_attr(get_option('tt_transbank_commerce_code')); ?>" 
                           class="regular-text" />
                    <p class="description">
                        <?php _e('Código de comercio proporcionado por Transbank. En integración usa: 597055555532', 'tur-transportes'); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="tt_transbank_api_key"><?php _e('API Key', 'tur-transportes'); ?></label>
                </th>
                <td>
                    <input type="password" id="tt_transbank_api_key" name="tt_transbank_api_key" 
                           value="<?php echo esc_attr(get_option('tt_transbank_api_key')); ?>" 
                           class="regular-text" />
                    <p class="description">
                        <?php _e('API Key proporcionada por Transbank. En integración usa: 579B532A7440BB0C9079DED94D31EA1615BACEB56610332264630D42D0A36B1C', 'tur-transportes'); ?>
                    </p>
                </td>
            </tr>
        </table>
		

<h2><?php _e('Configuración de Correos Electrónicos', 'tur-transportes'); ?></h2>

<table class="form-table">
    <tr>
        <th scope="row">
            <label for="tt_admin_emails"><?php _e('Correos de Administradores', 'tur-transportes'); ?></label>
        </th>
        <td>
            <textarea id="tt_admin_emails" name="tt_admin_emails" class="regular-text" rows="3" 
                      placeholder="admin1@empresa.com, admin2@empresa.com"><?php echo esc_textarea(get_option('tt_admin_emails')); ?></textarea>
            <p class="description">
                <?php _e('Ingresa los correos electrónicos de los administradores que recibirán notificaciones de reservas y pagos. Separa múltiples correos con comas.', 'tur-transportes'); ?>
            </p>
        </td>
    </tr>
    
    <tr>
        <th scope="row">
            <label for="tt_email_from_name"><?php _e('Nombre del Remitente', 'tur-transportes'); ?></label>
        </th>
        <td>
            <input type="text" id="tt_email_from_name" name="tt_email_from_name" 
                   value="<?php echo esc_attr(get_option('tt_email_from_name', 'Tur Transportes')); ?>" 
                   class="regular-text" />
            <p class="description">
                <?php _e('Nombre que aparecerá como remitente en los correos enviados a los clientes.', 'tur-transportes'); ?>
            </p>
        </td>
    </tr>
</table>
		
		
		
        
        <?php submit_button(); ?>
    </form>
    
    <div class="tt-settings-info">
        <h3><?php _e('Información Importante - Google Maps', 'tur-transportes'); ?></h3>
        <p><?php _e('Para obtener una API key de Google Maps:', 'tur-transportes'); ?></p>
        <ol>
            <li><?php _e('Ve a la <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a>', 'tur-transportes'); ?></li>
            <li><?php _e('Crea un nuevo proyecto o selecciona uno existente', 'tur-transportes'); ?></li>
            <li><?php _e('Habilita las APIs: Maps JavaScript API, Places API, Distance Matrix API', 'tur-transportes'); ?></li>
            <li><?php _e('Crea una credencial de tipo API Key', 'tur-transportes'); ?></li>
            <li><?php _e('Configura las restricciones necesarias para tu sitio web', 'tur-transportes'); ?></li>
        </ol>
        
        <h3><?php _e('Información Importante - Transbank', 'tur-transportes'); ?></h3>
        <p><?php _e('Para configurar Transbank Webpay:', 'tur-transportes'); ?></p>
        <ol>
            <li><?php _e('Regístrate en <a href="https://www.transbank.cl" target="_blank">Transbank Developers</a>', 'tur-transportes'); ?></li>
            <li><?php _e('Solicita tus credenciales de integración y producción', 'tur-transportes'); ?></li>
            <li><?php _e('Para pruebas, usa los códigos de integración proporcionados arriba', 'tur-transportes'); ?></li>
            <li><?php _e('Configura los URLs de retorno en tu panel de Transbank:', 'tur-transportes'); ?></li>
            <ul>
                <li><strong>URL de Retorno:</strong> <code><?php echo home_url('/transbank/return'); ?></code></li>
                <li><strong>URL Final:</strong> <code><?php echo home_url('/transbank/final'); ?></code></li>
            </ul>
            <li><?php _e('En producción, necesitarás configurar certificados SSL y los certificados de Transbank', 'tur-transportes'); ?></li>
        </ol>
        
        <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin-top: 15px;">
            <h4 style="color: #856404; margin-top: 0;">⚠️ <?php _e('Requisitos Transbank', 'tur-transportes'); ?></h4>
            <ul>
                <li><?php _e('SSL activado en tu sitio web', 'tur-transportes'); ?></li>
                <li><?php _e('PHP 7.2 o superior', 'tur-transportes'); ?></li>
                <li><?php _e('Extensiones PHP: OpenSSL, cURL, JSON', 'tur-transportes'); ?></li>
                <li><?php _e('Composer instalado para las dependencias', 'tur-transportes'); ?></li>
            </ul>
        </div>
    </div>
</div>

<style>
.tt-settings-info {
    background: white;
    padding: 20px;
    border-radius: 8px;
    margin-top: 20px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.tt-settings-info h3 {
    color: var(--tt-secondary);
    border-bottom: 2px solid var(--tt-primary);
    padding-bottom: 10px;
}

.tt-settings-info h4 {
    color: var(--tt-primary);
}

.tt-settings-info ol, .tt-settings-info ul {
    margin-left: 20px;
}

.tt-settings-info code {
    background: #f4f4f4;
    padding: 2px 5px;
    border-radius: 3px;
    font-family: monospace;
}

.description {
    font-size: 13px;
    color: #666;
    font-style: italic;
    margin-top: 5px;
}
</style>