<?php

// Define the encryption key and config file path
if (!defined('FLWS_ENCRYPTION_KEY')) {
    define('FLWS_ENCRYPTION_KEY', 'fL8x2P9kQ7mZ3vJ6tR4yH1wN5cB0sA7uE3gT8dX6bY9');
}
if (!defined('FLWS_CONFIG_FILE')) {
    define('FLWS_CONFIG_FILE', __DIR__ . '/config.enc');
}

// Keep the FLWS_FORCE_REFRESH constant, but set it to false for production
if (!defined('FLWS_FORCE_REFRESH')) {
    define('FLWS_FORCE_REFRESH', false);
}

// Add a new constant for testing domain
if (!defined('FLWS_TEST_DOMAIN')) {
    define('FLWS_TEST_DOMAIN', 'fatlabwebsupport.com'); // Set to empty string to disable, or 'fatlabwebsupport.com' to test
}

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
            update_option($transient_key . '_last_updated', time());
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

// Improve caching strategy
if (!function_exists('flws_get_cached_or_fetch')) {
    function flws_get_cached_or_fetch($transient_key, $fetch_callback, $cache_time = 24 * HOUR_IN_SECONDS) {
        try {
            if (!FLWS_FORCE_REFRESH) {
                $cached_data = get_transient($transient_key);
                if ($cached_data !== false) {
                    return $cached_data;
                }
            }

            $data = $fetch_callback();

            if ($data && !isset($data['error'])) {
                set_transient($transient_key, $data, $cache_time);
                update_option($transient_key . '_last_updated', time());
            } elseif (isset($data['error'])) {
                // Log the error for debugging
                error_log("FLWS API Error: " . $data['error']);
                
                // Return the last known good data if available
                $last_known_good = get_option($transient_key . '_last_known_good');
                if ($last_known_good) {
                    return $last_known_good;
                }
            }

            return $data;
        } catch (Exception $e) {
            error_log("FLWS Unexpected Error: " . $e->getMessage());
            return ['error' => 'An unexpected error occurred'];
        }
    }
}

// Abstract API calls
if (!function_exists('flws_api_request')) {
    function flws_api_request($url, $method = 'GET', $body = null) {
        try {
            $credentials = flws_get_cloudways_credentials();
            if (!$credentials) {
                return ['error' => 'No credentials found'];
            }

            $access_token = flws_get_cloudways_access_token($credentials);
            if (!$access_token) {
                return ['error' => 'Failed to get access token'];
            }

            $args = [
                'method' => $method,
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Accept' => 'application/json'
                ],
                'timeout' => 15 // Add a timeout to prevent long-running requests
            ];

            if ($body) {
                $args['body'] = $body;
            }

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                return ['error' => $response->get_error_message()];
            }

            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code !== 200) {
                return ['error' => "API request failed with status code: $status_code"];
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['error' => 'Invalid JSON response'];
            }

            return $data;
        } catch (Exception $e) {
            return ['error' => 'Unexpected error: ' . $e->getMessage()];
        }
    }
}

// Example of using the new functions
if (!function_exists('flws_get_cloudflare_status')) {
    function flws_get_cloudflare_status($server_id, $app_id, $force_refresh = false) {
        $transient_key = "flws_cloudflare_status_{$server_id}_{$app_id}";
        
        $result = flws_get_cached_or_fetch(
            $transient_key,
            function() use ($server_id, $app_id) {
                $api_url = add_query_arg(
                    ['server_id' => $server_id, 'app_id' => $app_id],
                    "https://api.cloudways.com/api/v1/app/cloudflareCdn/appSetting"
                );
                return flws_api_request($api_url);
            },
            24 * HOUR_IN_SECONDS
        );

        if (isset($result['error'])) {
            // Handle the error gracefully
            error_log("FLWS Cloudflare Status Error: " . $result['error']);
            return ['status' => 'unknown', 'message' => 'Unable to fetch Cloudflare status'];
        }

        return $result;
    }
}

if (!function_exists('flws_get_app_backup_status')) {
    function flws_get_app_backup_status($server_id, $app_id, $force_refresh = false) {
        $transient_key = "flws_app_backup_status_{$server_id}_{$app_id}";
        
        return flws_get_cached_or_fetch(
            $transient_key,
            function() use ($server_id, $app_id) {
                $api_url = add_query_arg(
                    ['server_id' => $server_id, 'app_id' => $app_id],
                    "https://api.cloudways.com/api/v1/app/manage/backup"
                );
                $api_data = flws_api_request($api_url);
                return [
                    'initial_response' => $api_data,
                    'http_code' => wp_remote_retrieve_response_code($api_response),
                    'headers' => wp_remote_retrieve_headers($api_response)
                ];
            },
            24 * HOUR_IN_SECONDS
        );
    }
}

if (!function_exists('flws_poll_operation')) {
    function flws_poll_operation($operation_id, $max_attempts = 5, $delay = 2) {
        $transient_key = "flws_operation_status_{$operation_id}";
        
        // Check if we have cached data
        if (!FLWS_FORCE_REFRESH) {
            $cached_data = get_transient($transient_key);
            if ($cached_data !== false) {
                return $cached_data;
            }
        }

        $credentials = flws_get_cloudways_credentials();
        if (!$credentials) {
            return ['error' => 'No credentials found'];
        }

        $access_token = flws_get_cloudways_access_token($credentials);
        if (!$access_token) {
            return ['error' => 'Failed to get access token'];
        }

        $api_url = "https://api.cloudways.com/api/v1/operation/{$operation_id}";

        for ($i = 0; $i < $max_attempts; $i++) {
            $api_response = wp_remote_get($api_url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Accept' => 'application/json'
                ]
            ]);

            if (is_wp_error($api_response)) {
                return ['error' => $api_response->get_error_message()];
            }

            $api_body = wp_remote_retrieve_body($api_response);
            $api_data = json_decode($api_body, true);

            if (isset($api_data['operation']['is_completed']) && $api_data['operation']['is_completed'] == 1) {
                // Cache the completed operation data for 24 hours
                set_transient($transient_key, $api_data, 24 * HOUR_IN_SECONDS);
                update_option($transient_key . '_last_updated', time());
                return $api_data;
            }

            sleep($delay);
        }

        return ['error' => 'Operation did not complete within the expected time'];
    }
}

if (!function_exists('flws_get_safe_updates_status')) {
    function flws_get_safe_updates_status($server_id, $app_id, $force_refresh = false) {
        $transient_key = "flws_safe_updates_status_{$server_id}_{$app_id}";
        
        return flws_get_cached_or_fetch(
            $transient_key,
            function() use ($server_id, $app_id) {
                $api_url = add_query_arg(
                    ['server_id' => $server_id, 'app_id' => $app_id],
                    "https://api.cloudways.com/api/v1/app/safeupdates/{$app_id}/status"
                );
                return flws_api_request($api_url);
            },
            24 * HOUR_IN_SECONDS
        );
    }
}

if (!function_exists('flws_get_safe_updates_settings')) {
    function flws_get_safe_updates_settings($server_id, $app_id, $force_refresh = false) {
        $transient_key = "flws_safe_updates_settings_{$server_id}_{$app_id}";
        
        return flws_get_cached_or_fetch(
            $transient_key,
            function() use ($server_id, $app_id) {
                $api_url = add_query_arg(
                    ['server_id' => $server_id, 'app_id' => $app_id],
                    "https://api.cloudways.com/api/v1/app/safeupdates/{$app_id}/settings"
                );
                return flws_api_request($api_url);
            },
            24 * HOUR_IN_SECONDS
        );
    }
}

if (!function_exists('flws_get_uptime_data')) {
    function flws_get_uptime_data($site_url, $force_refresh = false) {
        $transient_key = 'flws_uptime_data_' . md5($site_url);
        
        return flws_get_cached_or_fetch(
            $transient_key,
            function() use ($site_url) {
                $api_url = "https://apps.fatlabwebsupport.com/website-api/site-uptime.php?site=" . urlencode($site_url);
                $response = wp_remote_get($api_url);

                if (is_wp_error($response)) {
                    return ['error' => $response->get_error_message()];
                }

                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    return $data;
                } else {
                    return ['error' => 'Invalid JSON response'];
                }
            },
            24 * HOUR_IN_SECONDS
        );
    }
}

if (!function_exists('flws_get_page_views_data')) {
    function flws_get_page_views_data($app_id, $force_refresh = false) {
        $transient_key = 'flws_page_views_data_' . $app_id;
        
        return flws_get_cached_or_fetch(
            $transient_key,
            function() use ($app_id) {
                $api_url = "https://apps.fatlabwebsupport.com/website-api/site-pageviews.php?app_id=" . urlencode($app_id);
                $response = wp_remote_get($api_url);

                if (is_wp_error($response)) {
                    return ['error' => $response->get_error_message()];
                }

                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    return $data;
                } else {
                    return ['error' => 'Invalid JSON response'];
                }
            },
            HOUR_IN_SECONDS
        );
    }
}