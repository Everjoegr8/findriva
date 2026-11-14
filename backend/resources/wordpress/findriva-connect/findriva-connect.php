<?php

/**
 * Plugin Name:       Findriva Connect
 * Plugin URI:        https://findriva.eratechnologiz.com.ng/
 * Description:       Shows the business details you approved in Findriva on your homepage: structured business data (JSON-LD), description and share preview tags. Nothing is published without an owner or admin approving it in Findriva.
 * Version:           1.0.0
 * Requires at least: 5.5
 * Requires PHP:      7.4
 * Author:            Findriva
 * License:           GPL-2.0-or-later
 * Text Domain:       findriva-connect
 */
if (! defined('ABSPATH')) {
    exit;
}

define('FINDRIVA_CONNECT_VERSION', '1.0.0');
define('FINDRIVA_CONNECT_DEFAULT_APP', 'https://findriva.eratechnologiz.com.ng');

/** Settings are stored in one option; the site token is never shown again after pairing. */
function findriva_connect_settings()
{
    $s = get_option('findriva_connect', []);

    return wp_parse_args(is_array($s) ? $s : [], [
        'app_url' => FINDRIVA_CONNECT_DEFAULT_APP,
        'token' => '',
        'business' => '',
        'manifest' => [],
        'checked_at' => 0,
        'last_error' => '',
    ]);
}

function findriva_connect_save($changes)
{
    update_option('findriva_connect', array_merge(findriva_connect_settings(), $changes), false);
}

/** Pulls what an owner or admin published in Findriva. */
function findriva_connect_refresh()
{
    $s = findriva_connect_settings();
    if ($s['token'] === '') {
        return;
    }
    $r = wp_remote_get(untrailingslashit($s['app_url']).'/api/v1/connect/wordpress/manifest', [
        'timeout' => 15,
        'headers' => ['Authorization' => 'Bearer '.$s['token'], 'Accept' => 'application/json', 'X-Findriva-Plugin' => FINDRIVA_CONNECT_VERSION],
    ]);
    if (is_wp_error($r)) {
        findriva_connect_save(['last_error' => $r->get_error_message(), 'checked_at' => time()]);

        return;
    }
    $code = wp_remote_retrieve_response_code($r);
    if ($code === 401) {
        // Disconnected in Findriva: stop printing anything.
        findriva_connect_save(['token' => '', 'manifest' => [], 'business' => '', 'last_error' => __('This site was disconnected in Findriva.', 'findriva-connect'), 'checked_at' => time()]);

        return;
    }
    $body = json_decode(wp_remote_retrieve_body($r), true);
    if ($code !== 200 || ! is_array($body) || ! isset($body['data']) || ! is_array($body['data'])) {
        findriva_connect_save(['last_error' => sprintf(__('Findriva answered with HTTP %d.', 'findriva-connect'), $code), 'checked_at' => time()]);

        return;
    }
    findriva_connect_save(['manifest' => $body['data'], 'last_error' => '', 'checked_at' => time()]);
}

add_action('findriva_connect_refresh', 'findriva_connect_refresh');

register_activation_hook(__FILE__, function () {
    if (! wp_next_scheduled('findriva_connect_refresh')) {
        wp_schedule_event(time() + 60, 'hourly', 'findriva_connect_refresh');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('findriva_connect_refresh');
});

/** SEO plugins already print a description and Open Graph tags; avoid printing them twice. */
function findriva_connect_seo_plugin_active()
{
    return defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || defined('AIOSEO_VERSION') || defined('SEOPRESS_VERSION') || class_exists('The_SEO_Framework\\Load');
}

add_action('wp_head', function () {
    if (! (is_front_page() || is_home()) || is_paged()) {
        return;
    }
    $s = findriva_connect_settings();
    $home = isset($s['manifest']['homepage']) && is_array($s['manifest']['homepage']) ? $s['manifest']['homepage'] : [];
    if (! $home) {
        return;
    }
    echo "\n<!-- Findriva Connect ".esc_html(FINDRIVA_CONNECT_VERSION).": approved in Findriva -->\n";
    if (! empty($home['meta']) && is_array($home['meta']) && ! findriva_connect_seo_plugin_active()) {
        foreach ($home['meta'] as $key => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }
            $attr = (strpos($key, 'og:') === 0) ? 'property' : 'name';
            printf('<meta %s="%s" content="%s" />'."\n", $attr, esc_attr($key), esc_attr($value));
        }
    }
    if (! empty($home['jsonld']) && is_string($home['jsonld'])) {
        $decoded = json_decode($home['jsonld'], true);
        if (is_array($decoded)) {
            // Re-encode so only valid JSON is printed; slashes in "</script>" are escaped.
            echo '<script type="application/ld+json">'.wp_json_encode($decoded, JSON_UNESCAPED_UNICODE)."</script>\n";
        }
    }
    echo "<!-- /Findriva Connect -->\n";
}, 5);

add_action('admin_menu', function () {
    add_options_page(__('Findriva Connect', 'findriva-connect'), __('Findriva Connect', 'findriva-connect'), 'manage_options', 'findriva-connect', 'findriva_connect_page');
});

add_action('admin_post_findriva_connect_pair', function () {
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('Not allowed.', 'findriva-connect'));
    }
    check_admin_referer('findriva_connect_pair');
    $app = esc_url_raw(untrailingslashit(wp_unslash($_POST['app_url'] ?? FINDRIVA_CONNECT_DEFAULT_APP)));
    $code = sanitize_text_field(wp_unslash($_POST['code'] ?? ''));
    if (strpos($app, 'https://') !== 0) {
        findriva_connect_save(['last_error' => __('The Findriva address must start with https://', 'findriva-connect')]);
    } else {
        $r = wp_remote_post($app.'/api/v1/connect/wordpress/pair', [
            'timeout' => 20,
            'headers' => ['Accept' => 'application/json'],
            'body' => ['code' => $code, 'site_url' => home_url('/'), 'plugin_version' => FINDRIVA_CONNECT_VERSION, 'wp_version' => get_bloginfo('version')],
        ]);
        $body = is_wp_error($r) ? null : json_decode(wp_remote_retrieve_body($r), true);
        if (! is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200 && ! empty($body['data']['token'])) {
            findriva_connect_save(['app_url' => $app, 'token' => sanitize_text_field($body['data']['token']), 'business' => sanitize_text_field($body['data']['business'] ?? ''), 'last_error' => '']);
            findriva_connect_refresh();
        } else {
            $msg = is_wp_error($r) ? $r->get_error_message() : ($body['errors'] ? implode(' ', array_map(function ($m) {
                return implode(' ', (array) $m);
            }, (array) $body['errors'])) : ($body['message'] ?? __('Pairing failed.', 'findriva-connect')));
            findriva_connect_save(['last_error' => sanitize_text_field($msg)]);
        }
    }
    wp_safe_redirect(admin_url('options-general.php?page=findriva-connect'));
    exit;
});

add_action('admin_post_findriva_connect_refresh', function () {
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('Not allowed.', 'findriva-connect'));
    }
    check_admin_referer('findriva_connect_refresh');
    findriva_connect_refresh();
    wp_safe_redirect(admin_url('options-general.php?page=findriva-connect'));
    exit;
});

add_action('admin_post_findriva_connect_forget', function () {
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('Not allowed.', 'findriva-connect'));
    }
    check_admin_referer('findriva_connect_forget');
    findriva_connect_save(['token' => '', 'manifest' => [], 'business' => '', 'last_error' => '']);
    wp_safe_redirect(admin_url('options-general.php?page=findriva-connect'));
    exit;
});

function findriva_connect_page()
{
    $s = findriva_connect_settings();
    $home = isset($s['manifest']['homepage']) && is_array($s['manifest']['homepage']) ? $s['manifest']['homepage'] : [];
    echo '<div class="wrap"><h1>'.esc_html__('Findriva Connect', 'findriva-connect').'</h1>';
    if ($s['last_error'] !== '') {
        echo '<div class="notice notice-warning"><p>'.esc_html($s['last_error']).'</p></div>';
    }
    if ($s['token'] === '') {
        echo '<p>'.esc_html__('In Findriva, open your business, go to Website connection, and press "Create pairing code". Paste the code here. Findriva never asks for your WordPress password.', 'findriva-connect').'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('findriva_connect_pair');
        echo '<input type="hidden" name="action" value="findriva_connect_pair" />';
        echo '<table class="form-table"><tr><th><label for="fc-app">'.esc_html__('Findriva address', 'findriva-connect').'</label></th><td><input id="fc-app" class="regular-text" name="app_url" value="'.esc_attr($s['app_url']).'" /></td></tr>';
        echo '<tr><th><label for="fc-code">'.esc_html__('Pairing code', 'findriva-connect').'</label></th><td><input id="fc-code" class="regular-text" name="code" placeholder="FDV-XXXX-XXXX" required /></td></tr></table>';
        submit_button(__('Connect to Findriva', 'findriva-connect'));
        echo '</form></div>';

        return;
    }
    echo '<p>'.sprintf(esc_html__('Connected to Findriva for %s.', 'findriva-connect'), '<strong>'.esc_html($s['business']).'</strong>').' ';
    echo $s['checked_at'] ? sprintf(esc_html__('Last checked %s ago.', 'findriva-connect'), esc_html(human_time_diff($s['checked_at']))) : '';
    echo '</p>';
    if (! $home) {
        echo '<p>'.esc_html__('Nothing is published yet. An owner or admin chooses what to publish in Findriva.', 'findriva-connect').'</p>';
    } else {
        echo '<p>'.esc_html__('Printed on your homepage:', 'findriva-connect').'</p><ul style="list-style:disc;padding-left:20px">';
        if (! empty($home['jsonld'])) {
            echo '<li>'.esc_html__('Structured business data (JSON-LD)', 'findriva-connect').'</li>';
        }
        if (! empty($home['meta'])) {
            echo '<li>'.(findriva_connect_seo_plugin_active()
                ? esc_html__('Description and share preview tags: skipped, because your SEO plugin already prints them. Copy them there instead.', 'findriva-connect')
                : esc_html__('Description and share preview (Open Graph) tags', 'findriva-connect')).'</li>';
        }
        echo '</ul>';
    }
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-right:8px">';
    wp_nonce_field('findriva_connect_refresh');
    echo '<input type="hidden" name="action" value="findriva_connect_refresh" />';
    submit_button(__('Check Findriva now', 'findriva-connect'), 'secondary', 'submit', false);
    echo '</form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block">';
    wp_nonce_field('findriva_connect_forget');
    echo '<input type="hidden" name="action" value="findriva_connect_forget" />';
    submit_button(__('Disconnect this site', 'findriva-connect'), 'delete', 'submit', false);
    echo '</form></div>';
}
