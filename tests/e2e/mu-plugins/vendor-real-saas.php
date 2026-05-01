<?php
/**
 * Plugin Name: TrustedLogin e2e — Vendor → Real SaaS rewrite.
 *
 * Loaded only inside the e2e docker stack. Companion to vendor-fake-saas.php
 * but points at the actual SaaS Laravel container running on the host's
 * docker, reachable through `host.docker.internal:8090`.
 *
 * Activation rules:
 *   - When the file `/var/www/html/wp-content/.use-real-saas` exists,
 *     this filter wins (later-registered → registered last → highest
 *     priority among same-priority filters).
 *   - When that file is absent, this mu-plugin is a no-op and the
 *     fake-saas filter (priority 10) keeps controlling the URL.
 *
 * Tests that exercise the real SaaS create / remove that toggle file.
 *
 * Never ship.
 */

if (! defined('ABSPATH')) {
    exit;
}

add_filter(
    'trustedlogin/api-url/saas',
    function ($url) {
        if (! file_exists('/var/www/html/wp-content/.use-real-saas')) {
            return $url;
        }

        return 'http://host.docker.internal:8090/api/v1/';
    },
    20
);
