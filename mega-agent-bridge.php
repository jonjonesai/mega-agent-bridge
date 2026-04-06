<?php
/**
 * Plugin Name:  Mega Agent Bridge
 * Plugin URI:   https://github.com/mega-management/mega-agent-bridge
 * Description:  REST API bridge that lets AI agents (Claude) read and write WordPress sites with precision. Part of the MEGA ecosystem — enabling anyone to one-shot a WordPress site from the terminal.
 * Version:      1.0.0
 * Author:       MEGA
 * Author URI:   https://mega.management
 * License:      GPL-2.0+
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MEGA_BRIDGE_VERSION',    '1.0.0' );
define( 'MEGA_BRIDGE_KEY_OPTION', 'mega_bridge_api_key' );
define( 'MEGA_BRIDGE_NS',         'mega-bridge/v1' );

// ─── Activation ──────────────────────────────────────────────────────────────

register_activation_hook( __FILE__, 'mega_bridge_activate' );
function mega_bridge_activate() {
    if ( ! get_option( MEGA_BRIDGE_KEY_OPTION ) ) {
        update_option( MEGA_BRIDGE_KEY_OPTION, wp_generate_password( 48, false ) );
    }
}

// ─── Auth ─────────────────────────────────────────────────────────────────────

function mega_bridge_auth( WP_REST_Request $request ) {
    $stored   = get_option( MEGA_BRIDGE_KEY_OPTION, '' );
    $provided = $request->get_header( 'X-Mega-Bridge-Key' )
             ?: $request->get_param( '_key' );
    return $stored && hash_equals( $stored, (string) $provided );
}

// ─── Routes ───────────────────────────────────────────────────────────────────

add_action( 'rest_api_init', 'mega_bridge_register_routes' );
function mega_bridge_register_routes() {
    $ns   = MEGA_BRIDGE_NS;
    $auth = 'mega_bridge_auth';

    // Health / status
    register_rest_route( $ns, '/status', [
        'methods'             => 'GET',
        'callback'            => 'mega_bridge_status',
        'permission_callback' => $auth,
    ]);

    // Render a page fresh — bypasses all host-level cache
    register_rest_route( $ns, '/render', [
        'methods'             => 'GET',
        'callback'            => 'mega_bridge_render',
        'permission_callback' => $auth,
    ]);

    // Theme mods — read all
    register_rest_route( $ns, '/theme-mods', [
        'methods'             => 'GET',
        'callback'            => 'mega_bridge_get_all_theme_mods',
        'permission_callback' => $auth,
    ]);

    // Theme mod — read/write single key
    register_rest_route( $ns, '/theme-mods/(?P<key>[a-zA-Z0-9_\-]+)', [
        [
            'methods'             => 'GET',
            'callback'            => 'mega_bridge_get_theme_mod',
            'permission_callback' => $auth,
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'mega_bridge_set_theme_mod',
            'permission_callback' => $auth,
        ],
    ]);

    // Kadence custom CSS
    register_rest_route( $ns, '/kadence/css', [
        [
            'methods'             => 'GET',
            'callback'            => 'mega_bridge_get_kadence_css',
            'permission_callback' => $auth,
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'mega_bridge_set_kadence_css',
            'permission_callback' => $auth,
        ],
    ]);

    // Kadence full settings dump / bulk update
    register_rest_route( $ns, '/kadence/settings', [
        [
            'methods'             => 'GET',
            'callback'            => 'mega_bridge_get_kadence_settings',
            'permission_callback' => $auth,
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'mega_bridge_set_kadence_settings',
            'permission_callback' => $auth,
        ],
    ]);

    // Posts — find by path or slug
    register_rest_route( $ns, '/posts/find', [
        'methods'             => 'GET',
        'callback'            => 'mega_bridge_find_post',
        'permission_callback' => $auth,
    ]);

    // Posts — read/write by ID
    register_rest_route( $ns, '/posts/(?P<id>\d+)', [
        [
            'methods'             => 'GET',
            'callback'            => 'mega_bridge_get_post',
            'permission_callback' => $auth,
        ],
        [
            'methods'             => 'POST',
            'callback'            => 'mega_bridge_update_post',
            'permission_callback' => $auth,
        ],
    ]);

    // Cache flush
    register_rest_route( $ns, '/cache/flush', [
        'methods'             => 'POST',
        'callback'            => 'mega_bridge_flush_cache',
        'permission_callback' => $auth,
    ]);

    // Site info
    register_rest_route( $ns, '/site-info', [
        'methods'             => 'GET',
        'callback'            => 'mega_bridge_site_info',
        'permission_callback' => $auth,
    ]);

    // Retrieve API key — logged-in admin only, no bridge key needed
    register_rest_route( $ns, '/key', [
        'methods'             => 'GET',
        'callback'            => function() {
            return [ 'key' => get_option( MEGA_BRIDGE_KEY_OPTION ) ];
        },
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        },
    ]);
}

// ─── Handlers ─────────────────────────────────────────────────────────────────

function mega_bridge_status() {
    return [
        'status'     => 'ok',
        'version'    => MEGA_BRIDGE_VERSION,
        'site_url'   => get_site_url(),
        'theme'      => get_option( 'stylesheet' ),
        'wp_version' => get_bloginfo( 'version' ),
        'php_version'=> PHP_VERSION,
        'timestamp'  => time(),
    ];
}

/**
 * Render — fetches the page server-side with cache-bypass headers.
 * Returns actual HTML so the agent can verify its own changes.
 */
function mega_bridge_render( WP_REST_Request $request ) {
    $path = $request->get_param( 'path' ) ?: '/';
    $url  = home_url( $path );

    $response = wp_remote_get( $url, [
        'timeout'   => 30,
        'sslverify' => false,
        'headers'   => [
            'Cache-Control'  => 'no-cache, no-store, must-revalidate',
            'Pragma'         => 'no-cache',
            'X-Mega-Nocache' => '1',
        ],
        'cookies' => [ new WP_Http_Cookie([ 'name' => 'mega_bridge_nocache', 'value' => '1' ]) ],
    ]);

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'render_failed', $response->get_error_message(), [ 'status' => 500 ] );
    }

    $html = wp_remote_retrieve_body( $response );

    // Return full HTML + helpful excerpts so agent can spot-check without parsing 100kb
    return [
        'url'           => $url,
        'http_status'   => wp_remote_retrieve_response_code( $response ),
        'html'          => $html,
        'head_classes'  => mega_bridge_extract_body_classes( $html ),
        'header_class'  => mega_bridge_extract_header_class( $html ),
    ];
}

function mega_bridge_extract_body_classes( $html ) {
    preg_match( '/<body[^>]+class="([^"]+)"/', $html, $m );
    return $m[1] ?? '';
}

function mega_bridge_extract_header_class( $html ) {
    preg_match( '/class="([^"]*site-main-header-wrap[^"]*)"/', $html, $m );
    return $m[1] ?? '';
}

function mega_bridge_get_all_theme_mods() {
    return get_theme_mods();
}

function mega_bridge_get_theme_mod( WP_REST_Request $request ) {
    $key   = $request->get_param( 'key' );
    $value = get_theme_mod( $key );
    return [ 'key' => $key, 'value' => $value, 'type' => gettype( $value ) ];
}

/**
 * Set theme mod — stores as a proper PHP value, never as a JSON string.
 * This is the fix for Kadence's sub_option() reading failure.
 */
function mega_bridge_set_theme_mod( WP_REST_Request $request ) {
    $key   = $request->get_param( 'key' );
    $body  = $request->get_json_params();
    $value = $body['value'] ?? null;

    if ( is_null( $value ) ) {
        return new WP_Error( 'missing_value', 'Provide {"value": ...} in the JSON body.', [ 'status' => 400 ] );
    }

    $stylesheet = get_option( 'stylesheet' );
    $mods       = get_option( 'theme_mods_' . $stylesheet, [] );
    $mods[ $key ] = $value;
    update_option( 'theme_mods_' . $stylesheet, $mods );

    // Verify it stuck
    $check = get_theme_mod( $key );
    return [ 'key' => $key, 'value' => $value, 'verified' => ( $check === $value ) ];
}

function mega_bridge_get_kadence_css() {
    return [ 'css' => wp_get_custom_css() ];
}

function mega_bridge_set_kadence_css( WP_REST_Request $request ) {
    $body = $request->get_json_params();
    $css  = $body['css'] ?? null;

    if ( is_null( $css ) ) {
        return new WP_Error( 'missing_css', 'Provide {"css": "..."} in the JSON body.', [ 'status' => 400 ] );
    }

    // wp_update_custom_css_post is the correct WP core function — this is what Kadence outputs to the page.
    // update_option('kadence_custom_css') is a dead option that Kadence no longer reads.
    $result = wp_update_custom_css_post( $css );
    if ( is_wp_error( $result ) ) {
        return new WP_Error( 'css_save_failed', $result->get_error_message(), [ 'status' => 500 ] );
    }

    return [ 'saved' => true, 'length' => strlen( $css ), 'post_id' => $result->ID ];
}

function mega_bridge_get_kadence_settings() {
    return [
        'theme_mods'     => get_theme_mods(),
        'kadence_options' => get_option( 'kadence_theme_settings' ),
        'header_layout'  => get_option( 'kadence_header_layout' ),
        'pro_header'     => get_option( 'kadence_pro_header_builder' ),
        'custom_css'     => get_option( 'kadence_custom_css' ),
    ];
}

function mega_bridge_set_kadence_settings( WP_REST_Request $request ) {
    $body  = $request->get_json_params();
    $saved = [];

    // Direct WP options
    $option_map = [
        'kadence_options' => 'kadence_theme_settings',
        'header_layout'   => 'kadence_header_layout',
        'pro_header'      => 'kadence_pro_header_builder',
    ];
    foreach ( $option_map as $field => $option ) {
        if ( isset( $body[ $field ] ) ) {
            update_option( $option, $body[ $field ] );
            $saved[] = $field;
        }
    }

    // Bulk theme mods (stored as PHP arrays, never JSON strings)
    if ( isset( $body['theme_mods'] ) && is_array( $body['theme_mods'] ) ) {
        $stylesheet = get_option( 'stylesheet' );
        $mods       = get_option( 'theme_mods_' . $stylesheet, [] );
        foreach ( $body['theme_mods'] as $k => $v ) {
            $mods[ $k ] = $v;
        }
        update_option( 'theme_mods_' . $stylesheet, $mods );
        $saved[] = 'theme_mods';
    }

    return [ 'saved' => $saved ];
}

function mega_bridge_find_post( WP_REST_Request $request ) {
    $path = $request->get_param( 'path' );
    $slug = $request->get_param( 'slug' );

    $post_id = 0;

    if ( $path ) {
        if ( $path === '/' ) {
            $post_id = (int) get_option( 'page_on_front' );
        } else {
            $post_id = url_to_postid( home_url( $path ) );
        }
    } elseif ( $slug ) {
        $posts   = get_posts([ 'name' => $slug, 'post_type' => 'any', 'numberposts' => 1, 'post_status' => 'any' ]);
        $post_id = $posts ? $posts[0]->ID : 0;
    }

    if ( ! $post_id ) {
        return new WP_Error( 'not_found', 'Could not find a post for that path/slug.', [ 'status' => 404 ] );
    }

    $post = get_post( $post_id );
    return mega_bridge_post_payload( $post );
}

function mega_bridge_get_post( WP_REST_Request $request ) {
    $post = get_post( (int) $request->get_param( 'id' ) );
    if ( ! $post ) {
        return new WP_Error( 'not_found', 'Post not found.', [ 'status' => 404 ] );
    }
    return mega_bridge_post_payload( $post );
}

function mega_bridge_update_post( WP_REST_Request $request ) {
    $id   = (int) $request->get_param( 'id' );
    $body = $request->get_json_params();

    $update = [ 'ID' => $id ];
    if ( isset( $body['content'] ) ) $update['post_content'] = $body['content'];
    if ( isset( $body['title'] )   ) $update['post_title']   = $body['title'];
    if ( isset( $body['status'] )  ) $update['post_status']  = $body['status'];

    $result = wp_update_post( $update, true );
    if ( is_wp_error( $result ) ) {
        return new WP_Error( 'update_failed', $result->get_error_message(), [ 'status' => 500 ] );
    }

    return [ 'id' => $id, 'updated' => true ];
}

function mega_bridge_post_payload( WP_Post $post ) {
    return [
        'id'      => $post->ID,
        'title'   => $post->post_title,
        'content' => $post->post_content,
        'status'  => $post->post_status,
        'type'    => $post->post_type,
        'slug'    => $post->post_name,
        'url'     => get_permalink( $post->ID ),
    ];
}

/**
 * Flush every cache we know about — Hostinger LiteSpeed, WP Rocket, W3TC,
 * WP Super Cache, SiteGround SG Optimizer, GoDaddy / Bluehost / generic.
 */
function mega_bridge_flush_cache() {
    $flushed = [];

    wp_cache_flush();
    $flushed[] = 'wp_object_cache';

    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'" );
    $flushed[] = 'transients';

    // LiteSpeed (Hostinger, LiteSpeed hosts)
    do_action( 'litespeed_purge_all' );
    if ( class_exists( 'LiteSpeed\Purge' ) ) {
        LiteSpeed\Purge::purge_all();
        $flushed[] = 'litespeed';
    }

    // WP Rocket
    if ( function_exists( 'rocket_clean_domain' ) ) {
        rocket_clean_domain();
        $flushed[] = 'wp_rocket';
    }

    // W3 Total Cache
    if ( function_exists( 'w3tc_flush_all' ) ) {
        w3tc_flush_all();
        $flushed[] = 'w3tc';
    }

    // WP Super Cache
    if ( function_exists( 'wp_cache_clear_cache' ) ) {
        wp_cache_clear_cache();
        $flushed[] = 'wp_super_cache';
    }

    // SiteGround SG Optimizer
    if ( class_exists( 'SiteGround_Optimizer\Supercacher\Supercacher' ) ) {
        \SiteGround_Optimizer\Supercacher\Supercacher::purge_cache();
        $flushed[] = 'sg_optimizer';
    }

    // Kinsta / GoDaddy / WP Engine — common hooks
    do_action( 'cache_enabler_clear_complete_cache' );
    if ( function_exists( 'wpengine_purge_all' ) ) { wpengine_purge_all(); $flushed[] = 'wpengine'; }
    if ( class_exists( 'Endurance_Page_Cache' ) ) { do_action( 'epc_purge' ); $flushed[] = 'bluehost_epc'; }

    // Kadence block CSS cache
    delete_option( 'kadence_gutenberg_block_css' );
    delete_option( 'kadence_gutenberg_global_block_css' );
    $flushed[] = 'kadence_css_cache';

    // LiteSpeed via WP-CLI (Hostinger CDN layer — must be purged or browsers see stale HTML)
    $wp_cli = trim( shell_exec( 'which wp 2>/dev/null || which wp-cli 2>/dev/null || echo "/usr/local/bin/wp-cli-2.12.0.phar"' ) );
    $wp_path = ABSPATH;
    $output  = shell_exec( "$wp_cli litespeed-purge all --path=" . escapeshellarg( $wp_path ) . " 2>&1" );
    if ( $output && strpos( $output, 'Purged' ) !== false ) {
        $flushed[] = 'litespeed_cli';
    }

    return [ 'flushed' => $flushed, 'timestamp' => time() ];
}

function mega_bridge_site_info() {
    $theme = wp_get_theme();
    return [
        'site_url'      => get_site_url(),
        'home_url'      => home_url(),
        'wp_version'    => get_bloginfo( 'version' ),
        'php_version'   => PHP_VERSION,
        'theme'         => [
            'name'    => $theme->get( 'Name' ),
            'version' => $theme->get( 'Version' ),
            'slug'    => get_option( 'stylesheet' ),
            'parent'  => get_option( 'template' ),
        ],
        'active_plugins' => get_option( 'active_plugins', [] ),
        'front_page_id'  => (int) get_option( 'page_on_front' ),
        'permalink_structure' => get_option( 'permalink_structure' ),
    ];
}

// ─── Cache bypass for render requests ─────────────────────────────────────────

// LiteSpeed
add_filter( 'litespeed_is_forced_nocache', function( $nocache ) {
    if ( isset( $_SERVER['HTTP_X_MEGA_NOCACHE'] ) || isset( $_COOKIE['mega_bridge_nocache'] ) ) {
        return true;
    }
    return $nocache;
});

// WP Rocket
add_filter( 'rocket_buffer', function( $buffer ) {
    if ( isset( $_SERVER['HTTP_X_MEGA_NOCACHE'] ) ) {
        define( 'DONOTROCKETOPTIMIZE', true );
    }
    return $buffer;
}, 1 );
