<?php
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$vehicles = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}tt_vehicle_types WHERE active = 1");
$layout = isset($atts['layout']) ? $atts['layout'] : 'grid';
$show_prices = isset($atts['show_prices']) ? $atts['show_prices'] : 'yes';
?>

<div class="tt-vehicles-list tt-layout-<?php echo esc_attr($layout); ?>">
    <?php foreach ($vehicles as $vehicle): ?>
        <div class="tt-vehicle-card">
            <div class="tt-vehicle-image">
                <?php if ($vehicle->image_url): ?>
                    <img src="<?php echo esc_url($vehicle->image_url); ?>" alt="<?php echo esc_attr($vehicle->name); ?>">
                <?php else: ?>
                    <div class="tt-vehicle-placeholder">
                        <span class="dashicons dashicons-car"></span>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="tt-vehicle-info">
                <h4><?php echo esc_html($vehicle->name); ?></h4>
                <p class="tt-vehicle-description"><?php echo esc_html($vehicle->description); ?></p>
                <p class="tt-vehicle-capacity">
                    <strong><?php _e('Capacidad:', 'tur-transportes'); ?></strong> 
                    <?php echo esc_html($vehicle->capacity); ?> <?php _e('pasajeros', 'tur-transportes'); ?>
                </p>
                
                <?php if ($show_prices === 'yes'): ?>
                <div class="tt-vehicle-prices">
                    <p><strong><?php _e('Precio base:', 'tur-transportes'); ?></strong> $<?php echo number_format($vehicle->base_price, 2); ?></p>
                    <p><strong><?php _e('Precio por km:', 'tur-transportes'); ?></strong> $<?php echo number_format($vehicle->price_per_km, 2); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>