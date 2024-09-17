<?php

// Keep the FLWS_FORCE_REFRESH constant, but set it to false for production
define('FLWS_FORCE_REFRESH', false);

// Add a new constant for testing domain
define('FLWS_TEST_DOMAIN', ''); // Set to empty string to disable, or 'fatlabwebsupport.com' to test

// Wrap all functions in a check to ensure they're only defined once
if (!function_exists('flws_get_cloudways_credentials')) {
    function flws_get_cloudways_credentials() {
        if (!file_exists(FLWS_CONFIG_FILE)) {
            return false;
        }

        $encrypted_data = file_get_contents(FLWS_CONFIG_FILE);
        $decrypted_data = flws_decrypt_data($encrypted_data, FLWS_ENCRYPTION_KEY);

        if ($decrypted_data === false) {
            return false;
        }

        $config = json_decode($decrypted_data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        return $config;
    }
}

if (!function_exists('flws_encrypt_data')) {
    function flws_encrypt_data($data, $key) {
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
        return base64_encode($encrypted . '::' . $iv);
    }
}

if (!function_exists('flws_decrypt_data')) {
    function flws_decrypt_data($data, $key) {
        list($encrypted_data, $iv) = explode('::', base64_decode($data), 2);
        return openssl_decrypt($encrypted_data, 'aes-256-cbc', $key, 0, $iv);
    }
}

if (!function_exists('flws_get_cloudways_data')) {
    function flws_get_cloudways_data($force_refresh = false) {
        $transient_key = 'flws_current_site_cloudways_data';
        
        if (!$force_refresh && !FLWS_FORCE_REFRESH) {
            $cached_data = get_transient($transient_key);
            if ($cached_data !== false) {
                return $cached_data;
            }
        }

        $data = flws_fetch_data_from_cloudways_api();

        if ($data) {
            set_transient($transient_key, $data, 24 * HOUR_IN_SECONDS);
        }

        return $data;
    }
}

if (!function_exists('flws_fetch_data_from_cloudways_api')) {
    function flws_fetch_data_from_cloudways_api() {
        $credentials = flws_get_cloudways_credentials();
        if (!$credentials) {
            return false;
        }

        $access_token = flws_get_cloudways_access_token($credentials);
        if (!$access_token) {
            return false;
        }

        // Use the test domain if set, otherwise use the actual domain
        $current_domain = FLWS_TEST_DOMAIN ? FLWS_TEST_DOMAIN : $_SERVER['HTTP_HOST'];

        // Get server and app data
        $api_url = 'https://api.cloudways.com/api/v1/server';
        $api_response = wp_remote_get($api_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Accept' => 'application/json'
            ]
        ]);

        if (is_wp_error($api_response)) {
            return false;
        }

        $api_body = wp_remote_retrieve_body($api_response);
        $api_data = json_decode($api_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        // Extract only the relevant data for the current site
        $current_site_data = null;
        foreach ($api_data['servers'] as $server) {
            foreach ($server['apps'] as $app) {
                if ($app['cname'] === $current_domain || $app['app_fqdn'] === $current_domain) {
                    $current_site_data = [
                        'server_id' => $server['id'],
                        'app_id' => $app['id'],
                        'project_id' => $app['project_id'],
                        'app_name' => $app['label'],
                        'server_name' => $server['label'],
                        'cname' => $app['cname'],
                        'app_fqdn' => $app['app_fqdn']
                    ];
                    break 2; // Exit both loops
                }
            }
        }

        // If we found the current site's data, fetch the project info
        if ($current_site_data && !empty($current_site_data['project_id'])) {
            $project_info = flws_get_cloudways_project_info($current_site_data['project_id']);
            if ($project_info && isset($project_info['name'])) {
                $current_site_data['project_name'] = $project_info['name'];
            }
        }

        return $current_site_data;
    }
}

// Add this function to manually refresh the data
if (!function_exists('flws_refresh_cloudways_data')) {
    function flws_refresh_cloudways_data() {
        $transient_key = 'flws_current_site_cloudways_data';
        delete_transient($transient_key);
        return flws_get_cloudways_data();
    }
}

// Schedule daily refresh of Cloudways data
if (!wp_next_scheduled('flws_daily_refresh_cloudways_data')) {
    wp_schedule_event(time(), 'daily', 'flws_daily_refresh_cloudways_data');
}

add_action('flws_daily_refresh_cloudways_data', 'flws_refresh_cloudways_data');

if (!function_exists('flws_add_tracking_script')) {
    function flws_add_tracking_script() {
        $site_info = flws_get_cloudways_data();

        if (!$site_info) {
            return; // Exit if no matching Cloudways info found
        }

        $data = [
            'url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . (FLWS_TEST_DOMAIN ? FLWS_TEST_DOMAIN : "$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]"),
            'app_id' => $site_info['app_id'] ?? '',
            'server_id' => $site_info['server_id'] ?? '',
            'cname' => $site_info['cname'] ?? '',
            'app_fqdn' => '', //'app_fqdn' => $site_info['app_fqdn'] ?? '', if we want to use the fqdn in the future
            'server_name' => $site_info['server_name'] ?? '',
            'project_id' => $site_info['project_id'] ?? '',
            'project_name' => $site_info['project_name'] ?? ''
        ];

        // Remove any empty values
        $data = array_filter($data, function($value) {
            return $value !== '' && $value !== null;
        });

        $json_data = wp_json_encode($data);

        echo "<script>
        (function() {
          var data = " . $json_data . ";
          var script = document.createElement('script');
          script.src = 'https://apps.fatlabwebsupport.com/it/analytics-new.php?callback=handleAnalyticsResponse&data=' + encodeURIComponent(JSON.stringify(data));
          document.body.appendChild(script);
        })();

        function handleAnalyticsResponse(response) {
          // Remove console.log for production
        }
        </script>";
    }
}

add_action('wp_footer', 'flws_add_tracking_script');

if (!function_exists('flws_get_cloudways_project_info')) {
    function flws_get_cloudways_project_info($project_id) {
        $credentials = flws_get_cloudways_credentials();
        if (!$credentials) {
            return null;
        }

        $access_token = flws_get_cloudways_access_token($credentials);
        if (!$access_token) {
            return null;
        }

        $url = "https://api.cloudways.com/api/v1/project";
        
        $args = array(
            'headers' => array(
                'Authorization' => "Bearer {$access_token}",
                'Accept' => 'application/json'
            )
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['projects']) && is_array($data['projects'])) {
            foreach ($data['projects'] as $project) {
                if ($project['id'] == $project_id) {
                    return array(
                        'id' => $project['id'],
                        'name' => $project['name']
                    );
                }
            }
        }

        return null;
    }
}

if (!function_exists('flws_get_cloudways_access_token')) {
    function flws_get_cloudways_access_token($credentials) {
        $email = $credentials['email'];
        $api_key = $credentials['api_key'];

        $token_url = 'https://api.cloudways.com/api/v1/oauth/access_token';
        $token_data = [
            'email' => $email,
            'api_key' => $api_key
        ];

        $token_response = wp_remote_post($token_url, [
            'body' => $token_data,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded']
        ]);

        if (is_wp_error($token_response)) {
            return false;
        }

        $token_body = json_decode(wp_remote_retrieve_body($token_response), true);
        return $token_body['access_token'] ?? null;
    }
}
