<?php
/**
 * Plugin Name: TrustedLogin e2e — Client → Real SaaS rewrite.
 *
 * Companion to client-fake-saas.php. When the toggle file
 * `/var/www/html/wp-content/.use-real-saas` exists, this filter
 * (priority 20, registered after client-fake-saas's priority-10
 * filter) wins and points the Client SDK at the actual SaaS
 * Laravel container running on the host's docker.
 *
 * Never ship.
 */

if (! defined('ABSPATH')) {
    exit;
}

$ns = 'pro-block-builder';

add_filter(
    "trustedlogin/{$ns}/api_url",
    function ($url) {
        if (! file_exists('/var/www/html/wp-content/.use-real-saas')) {
            return $url;
        }

        return 'http://host.docker.internal:8090/api/v1/';
    },
    20
);
