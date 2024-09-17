<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Include the dashboard functions
require_once __DIR__ . '/dashboard-functions.php';

// Check for configuration file
$config_file = __DIR__ . '/white-label-config.php';
$config = file_exists($config_file) ? include $config_file : [];

// Set default values or use config values if present
$widget_title = $config['WIDGET_TITLE'] ?? 'FatLab Web Support Dashboard <span class="version">BETA V.1.0</span>';
$show_intro_text = $config['SHOW_INTRO_TEXT'] ?? true;
$intro_text = $config['INTRO_TEXT'] ?? 'At FatLab Web Support, we pride ourselves on being more than just a hosting provider—we are your dedicated support team. This dashboard provides a snapshot of your site\'s critical metrics, allowing you to stay informed about performance, security, and uptime. We offer ongoing monitoring, proactive maintenance, and updates on site health, all to ensure that your website remains secure, fast, and fully optimized.';
$show_support_button = $config['SHOW_SUPPORT_BUTTON'] ?? true;
$support_url = $config['SUPPORT_URL'] ?? 'https://fatlabwebsupport.com/get-support/?utm_source=dashboard&utm_medium=widget&utm_campaign=get-support';
$show_uptime_info = $config['SHOW_UPTIME_INFO'] ?? true;

// Add the dashboard widget
function flws_add_dashboard_widget() {
    global $widget_title;
    wp_add_dashboard_widget(
        'flws_dashboard_widget',  // Widget slug
        $widget_title,            // Widget title
        'flws_dashboard_widget_content' // Display function
    );
}
add_action('wp_dashboard_setup', 'flws_add_dashboard_widget');

// Ensure the widget is as high as possible
function flws_move_widget_to_high_priority() {
    global $wp_meta_boxes;

    // Check if Site Health exists and place your widget in 'high' priority
    if (isset($wp_meta_boxes['dashboard']['normal']['high'])) {
        $widget = $wp_meta_boxes['dashboard']['normal']['core']['flws_dashboard_widget'];
        unset($wp_meta_boxes['dashboard']['normal']['core']['flws_dashboard_widget']);
        $wp_meta_boxes['dashboard']['normal']['high']['flws_dashboard_widget'] = $widget;
    }
}
add_action('wp_dashboard_setup', 'flws_move_widget_to_high_priority');

// Add this function to enqueue the stylesheet
function flws_enqueue_dashboard_styles() {
    wp_enqueue_style('flws-dashboard-styles', plugin_dir_url(__FILE__) . 'dashboard.css');
    wp_enqueue_script('font-awesome', 'https://kit.fontawesome.com/9540cb6d9c.js', array(), null, true);
    wp_script_add_data('font-awesome', 'crossorigin', 'anonymous');
}
add_action('admin_enqueue_scripts', 'flws_enqueue_dashboard_styles');

// Dashboard widget content
function flws_dashboard_widget_content() {
    global $show_intro_text, $show_support_button, $show_uptime_info, $intro_text, $support_url;
    
    $cloudways_data = flws_get_cloudways_data();
    if (!$cloudways_data) {
        echo '<p><i class="fas fa-exclamation-triangle"></i> Unable to retrieve Cloudways data. Please check your configuration.</p>';
        return;
    }

    flws_render_support_button($show_support_button, $support_url);
    flws_render_intro_text($show_intro_text, $intro_text);
    flws_render_page_views($cloudways_data);
    flws_render_uptime_info($show_uptime_info, $cloudways_data);
    flws_render_site_info($cloudways_data);
    flws_render_safe_updates_info($cloudways_data);
    flws_render_security_info($cloudways_data);
    flws_render_backup_info($cloudways_data);
    flws_render_refresh_button();
}

function flws_render_support_button($show_support_button, $support_url) {
    if (!$show_support_button) return;
    
    echo '<div class="support-button">
        <a href="' . esc_url($support_url) . '" target="_blank">
            <i class="fas fa-life-ring"></i> Get Support
        </a>
    </div>';
}

function flws_render_intro_text($show_intro_text, $intro_text) {
    if (!$show_intro_text) return;
    
    echo '<div class="intro-text"><img src="/wp-content/mu-plugins/flws/images/fatlab-logo-100x100.png">' . esc_html($intro_text) . '</div>';
}

function flws_render_page_views($cloudways_data) {
    if (!isset($cloudways_data['app_id'])) {
        echo '<p>Unable to retrieve app ID for page views data.</p>';
        return;
    }

    $page_views_data = flws_get_page_views_data($cloudways_data['app_id']);
    $page_views_transient_key = 'flws_page_views_data_' . $cloudways_data['app_id'];
    $page_views_last_updated = get_option($page_views_transient_key . '_last_updated', 0);

    if (isset($page_views_data['error'])) {
        echo '<p>Error retrieving page views data: ' . esc_html($page_views_data['error']) . '</p>';
    } elseif (isset($page_views_data['page_views_last_30_days'])) {
        echo '<h3><span class="icon-container"><i class="fas fa-chart-line"></i></span> Page Views (Last 30 Days)</h3>';
        echo '<canvas id="pageViewsChart" width="400" height="200"></canvas>';
        
        $dates = array_keys($page_views_data['page_views_last_30_days']);
        $views = array_values($page_views_data['page_views_last_30_days']);
        
        $formatted_dates = array_map(function($date) {
            return date('M j', strtotime($date));
        }, $dates);
        
        ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var ctx = document.getElementById('pageViewsChart').getContext('2d');
            var chart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($formatted_dates); ?>,
                    datasets: [{
                        label: 'Page Views',
                        data: <?php echo json_encode($views); ?>,
                        backgroundColor: 'rgba(242, 59, 51, 0.2)',
                        borderColor: 'rgba(242, 59, 51, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        });
        </script>
        <?php
    } else {
        echo '<p>No page views data available.</p>';
    }
}

function flws_render_uptime_info($show_uptime_info, $cloudways_data) {
    if (!$show_uptime_info) return;
    
    echo '<h3><span class="icon-container"><i class="fas fa-server"></i></span> Site Uptime Information</h3>';
    $site_url = $cloudways_data['cname'] ?? $cloudways_data['app_fqdn'] ?? '';
    if ($site_url) {
        $uptime_data = flws_get_uptime_data($site_url);
        $uptime_transient_key = 'flws_uptime_data_' . md5($site_url);
        $uptime_last_updated = get_option($uptime_transient_key . '_last_updated', 0);

        if (isset($uptime_data['error'])) {
            echo '<p>Error retrieving uptime data: ' . esc_html($uptime_data['error']) . '</p>';
        } else {
            $uptime_percentage = $uptime_data['uptime_percentage_last_30_days'];
            $formatted_uptime = (floor($uptime_percentage) == $uptime_percentage) 
                ? number_format($uptime_percentage) 
                : number_format($uptime_percentage, 4);
            echo '<div><span class="icon-container"><i class="fas fa-percent"></i></span><span class="label">Uptime (Last 30 Days):</span> ' . esc_html($formatted_uptime) . '%</div>';
        }
    } else {
        echo '<p>Unable to retrieve site URL for uptime data.</p>';
    }
}

function flws_render_site_info($cloudways_data) {
    echo '<h3><span class="icon-container"><i class="fas fa-info-circle"></i></span> Site Information</h3>';
    echo '<div><span class="icon-container"><i class="fas fa-server"></i></span><span class="label">Server Name:</span> ' . esc_html($cloudways_data['server_name']) . ' (' . esc_html($cloudways_data['server_id']) . ')</div>';
    echo '<div><span class="icon-container"><i class="fas fa-globe"></i></span><span class="label">Site Name:</span> ' . esc_html($cloudways_data['app_name']) . ' (' . esc_html($cloudways_data['app_id']) . ')</div>';
    echo '<div><span class="icon-container"><i class="fas fa-link"></i></span><span class="label">Domain:</span> ' . esc_html($cloudways_data['cname']) . '</div>';
}

function flws_render_safe_updates_info($cloudways_data) {
    echo '<h3><span class="icon-container"><i class="fas fa-sync"></i></span> Software Updates</h3>';
    $safe_updates_status_key = "flws_safe_updates_status_{$cloudways_data['server_id']}_{$cloudways_data['app_id']}";
    $safe_updates_status_last_updated = get_option($safe_updates_status_key . '_last_updated', 0);
    

    $safe_updates_status = flws_get_safe_updates_status($cloudways_data['server_id'], $cloudways_data['app_id']);

    if (isset($safe_updates_status['error'])) {
        echo '<p>Error retrieving SafeUpdates status: ' . esc_html($safe_updates_status['error']) . '</p>';
    } elseif (isset($safe_updates_status['data']['is_active'])) {
        echo '<div><span class="icon-container"><i class="fas fa-toggle-on"></i></span><span class="label">Automatic Safe Updates:</span> ' . ($safe_updates_status['data']['is_active'] ? 'Enabled' : 'Disabled') . '</div>';

        if ($safe_updates_status['data']['is_active']) {
            $safe_updates_settings = flws_get_safe_updates_settings($cloudways_data['server_id'], $cloudways_data['app_id']);
            
            if (isset($safe_updates_settings['error'])) {
                echo '<p>Error retrieving SafeUpdates settings: ' . esc_html($safe_updates_settings['error']) . '</p>';
            } elseif (isset($safe_updates_settings['settings'])) {
                $settings = $safe_updates_settings['settings'];
                echo '<div><span class="icon-container"><i class="far fa-clock"></i></span><span class="label">Update Window:</span> ' . esc_html($settings['update_day']) . ', ' . esc_html($settings['update_slot']) . ' UTC</div>';
            }
        }
    } else {
        echo '<p>Unable to retrieve SafeUpdates status information.</p>';
    }
}

function flws_render_security_info($cloudways_data) {
    echo '<h3><span class="icon-container"><i class="fas fa-shield-alt"></i></span> Security</h3>';

    $cloudflare_transient_key = "flws_cloudflare_status_{$cloudways_data['server_id']}_{$cloudways_data['app_id']}";
    $cloudflare_status = get_transient($cloudflare_transient_key);
    if ($cloudflare_status === false) {
        $cloudflare_status = flws_get_cloudflare_status($cloudways_data['server_id'], $cloudways_data['app_id']);
    }

    if (isset($cloudflare_status['error'])) {
        echo '<p>Error retrieving Cloudflare status: ' . esc_html($cloudflare_status['error']) . '</p>';
    } elseif ($cloudflare_status['status'] == 1 && isset($cloudflare_status['data'])) {
        echo '<div><span class="icon-container"><i class="fas fa-fire"></i></span><span class="label">Firewall/WAF:</span> CloudFlare Enterprise</div>';
        echo '<div><span class="icon-container"><i class="fas fa-shield-alt"></i></span><span class="label">WAF Status:</span> Enabled</div>';
        echo '<div><span class="icon-container"><i class="fas fa-lock"></i></span><span class="label">SSL Certificates:</span> Installed (TLS 1.2-1.3)</div>';
        echo '<div><span class="icon-container"><i class="fas fa-robot"></i></span><span class="label">Bot Protection:</span> ' . (($cloudflare_status['data']['custom_metadata']['scrapeshield'] == 1) ? 'Enabled' : 'Disabled') . '</div>';
        echo '<div><span class="icon-container"><i class="fas fa-spider"></i></span><span class="label">Scraping Protection:</span> ' . (($cloudflare_status['data']['custom_metadata']['scrapeshield'] == 1) ? 'Enabled' : 'Disabled') . '</div>';

        echo '<h3><span class="icon-container"><i class="fas fa-tachometer-alt"></i></span> CDN Caching</h3>';
        echo '<div><span class="icon-container"><i class="fas fa-cloud"></i></span><span class="label">CDN:</span> Cloudflare Enterprise</div>';
        echo '<div><span class="icon-container"><i class="fas fa-file-archive"></i></span><span class="label">Static Assets Caching:</span> ' . (($cloudflare_status['data']['custom_metadata']['caching'] == 1) ? 'Enabled' : 'Disabled') . '</div>';
        echo '<div><span class="icon-container"><i class="fas fa-globe"></i></span><span class="label">Full Page Edge Caching:</span> ' . (($cloudflare_status['data']['custom_metadata']['edgecaching'] == 1) ? 'Enabled' : 'Disabled') . '</div>';
        
        echo '<h3><span class="icon-container"><i class="fas fa-bolt"></i></span> Performance Optimizations</h3>';
        echo '<div><span class="icon-container"><i class="fas fa-image"></i></span><span class="label">Image Optimization:</span> ' . (($cloudflare_status['data']['custom_metadata']['mirage'] == 1) ? 'Enabled' : 'Disabled') . '</div>';
        echo '<div><span class="icon-container"><i class="fas fa-compress-arrows-alt"></i></span><span class="label">Image Compression:</span> ' . ucfirst(esc_html($cloudflare_status['data']['custom_metadata']['polish'])) . '</div>';
        echo '<div><span class="icon-container"><i class="fas fa-file-image"></i></span><span class="label">WebP Conversion:</span> ' . (($cloudflare_status['data']['custom_metadata']['webp'] == 1) ? 'Enabled' : 'Disabled') . '</div>';
        echo '<div><span class="icon-container"><i class="fas fa-forward"></i></span><span class="label">Early Hints:</span> ' . (($cloudflare_status['data']['setting']['early_hints'] == 'on') ? 'Enabled' : 'Disabled') . '</div>';
    } else {
        echo '<p>Unable to retrieve Cloudflare status information.</p>';
    }
}

function flws_render_backup_info($cloudways_data) {
    echo '<h3><span class="icon-container"><i class="fas fa-database"></i></span> Available Backup Dates</h3>';
    $backup_transient_key = "flws_app_backup_status_{$cloudways_data['server_id']}_{$cloudways_data['app_id']}";
    $backup_last_updated = get_option($backup_transient_key . '_last_updated', 0);

    $backup_status = get_transient($backup_transient_key);
    if ($backup_status === false) {
        $backup_status = flws_get_app_backup_status($cloudways_data['server_id'], $cloudways_data['app_id']);
    }

    if (isset($backup_status['initial_response']['operation_id'])) {
        $operation_id = $backup_status['initial_response']['operation_id'];
        $operation_transient_key = "flws_operation_status_{$operation_id}";
        $operation_last_updated = get_option($operation_transient_key . '_last_updated', 0);
        
        $operation_status = get_transient($operation_transient_key);
        if ($operation_status === false) {
            $operation_status = flws_poll_operation($operation_id);
        }
        
        if (isset($operation_status['operation']['parameters'])) {
            $parameters = json_decode($operation_status['operation']['parameters'], true);
            
            if (isset($parameters['backup_dates']) && is_array($parameters['backup_dates'])) {
                echo '<div class="backup-dates-container">';
                echo '<ul>';
                $reversed_dates = array_reverse($parameters['backup_dates']);
                foreach ($reversed_dates as $date) {
                    $date_obj = new DateTime($date, new DateTimeZone('UTC'));
                    $date_obj->setTimezone(new DateTimeZone('America/New_York'));
                    $et_date = $date_obj->format('D M j Y g:i A T');
                    echo '<li>' . esc_html($et_date) . '</li>';
                }
                echo '</ul>';
                echo '</div>';
            }
        } else {
            echo '<p>No backup information found in the operation status.</p>';
        }
    } else {
        echo '<p>No backup operation ID found. Unable to retrieve backup information.</p>';
    }
}

function flws_render_refresh_button() {
    echo '<p><button id="flws-refresh-data" class="button button-secondary"><i class="fas fa-sync-alt"></i> Refresh All Data</button></p>';
    
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $('#flws-refresh-data').on('click', function() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'flws_refresh_data'
                },
                beforeSend: function() {
                    $('#flws-refresh-data').prop('disabled', true).text('Refreshing...');
                },
                success: function(response) {
                    location.reload();
                },
                error: function() {
                    alert('Error refreshing data. Please try again.');
                    $('#flws-refresh-data').prop('disabled', false).text('Refresh All Data');
                }
            });
        });
    });
    </script>
    <?php
}

// Add this function to handle the AJAX request
function flws_refresh_data_ajax() {
    // Force refresh for all data
    flws_get_cloudways_data(true);
    $cloudways_data = flws_get_cloudways_data();
    if ($cloudways_data) {
        flws_get_cloudflare_status($cloudways_data['server_id'], $cloudways_data['app_id'], true);
        flws_get_app_backup_status($cloudways_data['server_id'], $cloudways_data['app_id'], true);
        flws_get_safe_updates_status($cloudways_data['server_id'], $cloudways_data['app_id'], true);
        flws_get_safe_updates_settings($cloudways_data['server_id'], $cloudways_data['app_id'], true);
        
        // Refresh uptime data
        $site_url = $cloudways_data['cname'] ?? $cloudways_data['app_fqdn'] ?? '';
        if ($site_url) {
            flws_get_uptime_data($site_url, true);
        }
        
        // Refresh page views data
        if (isset($cloudways_data['app_id'])) {
            flws_get_page_views_data($cloudways_data['app_id'], true);
        }
    }
    wp_die();
}
add_action('wp_ajax_flws_refresh_data', 'flws_refresh_data_ajax');
