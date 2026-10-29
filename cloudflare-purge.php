<?php
/**
 * Plugin Name: Cloudflare Purge (Admin Bar — Portable + Dashboard)
 * Description: Admin Bar actions + Dashboard widget to purge Cloudflare cache. Editors and Authors allowed to Purge. Settings restricted to Admins. Optional auto-purge on content changes.
 * Version:     2.5.0
 * Author:      Segmant Design - chris@segmant.co.uk - segmant.co.uk
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CF_AdminBar_Purge_Portable {
    const OPT_TOKEN         = 'cf_purge_api_token';
    const OPT_ZONE_ID       = 'cf_purge_zone_id';
    const OPT_AUTODISCOVER  = 'cf_purge_autodiscover';
    const TRANS_ZONE_CACHE  = 'cf_purge_zone_auto_%s'; // host-hash
    const OPT_AUTOPURGE     = 'cf_purge_auto';
    const OPT_AUTO_LAST     = 'cf_purge_auto_last';
    const MAX_FILES_PER_CALL = 30; // Cloudflare limit for purge by URL (non-Enterprise)

    /** URLs queued for auto-purge during this request. */
    private $auto_queue = array();
    /** Whether this request should purge everything instead of URLs. */
    private $auto_everything = false;
    /** Human-readable reason for the queued purge (for the last-result log). */
    private $auto_reason = '';
    /** Whether the shutdown handler is registered. */
    private $auto_shutdown_hooked = false;

    public function __construct() {
        add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
        add_action( 'admin_post_cf_purge_simple', array( $this, 'handle' ) );
        add_action( 'admin_notices', array( $this, 'notices' ) );

        // Settings UI
        add_action( 'admin_menu', array( $this, 'settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_cf_purge_verify', array( $this, 'handle_verify' ) );

        // Plugins screen → “Settings” link
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'settings_link' ) );

        // Dashboard widget + CSS
        add_action( 'wp_dashboard_setup', array( $this, 'cf_add_dashboard_widget' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'cf_dashboard_widget_css' ) );

        // Auto-purge on content changes (opt-in per site)
        if ( $this->auto_enabled() ) {
            add_action( 'post_updated', array( $this, 'auto_on_post_updated' ), 10, 3 );
            add_action( 'transition_post_status', array( $this, 'auto_on_transition' ), 10, 3 );
            add_action( 'elementor/document/after_save', array( $this, 'auto_on_elementor_save' ), 10, 2 );
            add_action( 'wp_update_nav_menu', array( $this, 'auto_on_menu_update' ) );

            // Gravity Forms: editor, settings and add-on saves all write display_meta.
            add_action( 'gform_post_update_form_meta', array( $this, 'auto_on_gform_meta' ), 10, 3 );
            add_action( 'gform_post_form_activated', array( $this, 'auto_on_gform_status' ) );
            add_action( 'gform_post_form_deactivated', array( $this, 'auto_on_gform_status' ) );
            add_action( 'gform_post_form_trashed', array( $this, 'auto_on_gform_status' ) );
            add_action( 'gform_post_form_restored', array( $this, 'auto_on_gform_status' ) );
        }
    }

    /* ---------------------------
     * Capability / Config
     * -------------------------- */

    // MODIFIED: Changed from manage_options to publish_posts to allow Editors/Authors
    private function can_use() {
        return is_admin_bar_showing() && current_user_can( 'publish_posts' );
    }

    private function api_token() {
        if ( defined( 'CF_PURGE_API_TOKEN' ) && CF_PURGE_API_TOKEN ) {
            return CF_PURGE_API_TOKEN;
        }
        $t = get_option( self::OPT_TOKEN, '' );
        return is_string( $t ) ? trim( $t ) : '';
    }

    private function zone_id() {
        if ( defined( 'CF_PURGE_ZONE_ID' ) && CF_PURGE_ZONE_ID ) {
            return CF_PURGE_ZONE_ID;
        }
        $saved = get_option( self::OPT_ZONE_ID, '' );
        if ( ! empty( $saved ) ) return trim( $saved );

        $auto = get_option( self::OPT_AUTODISCOVER, 0 );
        if ( $auto && $this->api_token() ) {
            $host = parse_url( home_url(), PHP_URL_HOST );
            $cache_key = $this->zone_transient_key( $host );
            $cached = get_transient( $cache_key );
            if ( $cached ) return $cached;

            $zone_id = $this->discover_zone_id_for_host( $host );
            if ( $zone_id ) {
                set_transient( $cache_key, $zone_id, 7 * DAY_IN_SECONDS );
                return $zone_id;
            }
        }
        return '';
    }

    private function zone_transient_key( $host ) {
        $token = $this->api_token();
        $hash  = substr( md5( $host . '|' . $token ), 0, 12 );
        return sprintf( self::TRANS_ZONE_CACHE, $hash );
    }

    /* ---------------------------
     * Admin Bar
     * -------------------------- */

    public function admin_bar( $bar ) {
        if ( ! $this->can_use() ) return;

        $parent_id = 'cf-purge-simple';
        
        // Only link to settings if the user is actually an Admin
        $settings_href = current_user_can('manage_options') ? admin_url( 'options-general.php?page=cf-purge-settings' ) : false;

        $bar->add_node( array(
            'id'    => $parent_id,
            'title' => 'Cloudflare Purge',
            'href'  => $settings_href, 
            'meta'  => array( 'title' => 'Cloudflare cache purge actions' ),
        ) );

        $nonce = wp_create_nonce( 'cf_purge_simple' );

        $bar->add_node( array(
            'id'     => 'cf-purge-simple-all',
            'parent' => $parent_id,
            'title'  => 'Purge Everything',
            'href'   => add_query_arg(
                array( 'action'=>'cf_purge_simple','type'=>'everything','_wpnonce'=>$nonce ),
                admin_url( 'admin-post.php' )
            ),
            'meta'   => array( 'title' => 'POST /purge_cache {"purge_everything": true}' ),
        ) );

        $bar->add_node( array(
            'id'     => 'cf-purge-simple-host',
            'parent' => $parent_id,
            'title'  => 'Purge By Host',
            'href'   => add_query_arg(
                array( 'action'=>'cf_purge_simple','type'=>'host','_wpnonce'=>$nonce ),
                admin_url( 'admin-post.php' )
            ),
            'meta'   => array( 'title' => 'POST /purge_cache {"hosts":[...]]' ),
        ) );

        $bar->add_node( array(
            'id'     => 'cf-purge-simple-url',
            'parent' => $parent_id,
            'title'  => 'Purge Current URL',
            'href'   => add_query_arg(
                array( 'action'=>'cf_purge_simple','type'=>'url','_wpnonce'=>$nonce ),
                admin_url( 'admin-post.php' )
            ),
            'meta'   => array( 'title' => 'POST /purge_cache {"files":[current]}' ),
        ) );
    }

    /* ---------------------------
     * Purge Actions
     * -------------------------- */

    public function handle() {
        // MODIFIED: Changed permission check to allow Authors/Editors
        if ( ! current_user_can( 'publish_posts' ) ) wp_die( 'Insufficient permissions.' );
        
        check_admin_referer( 'cf_purge_simple' );

        $type      = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'everything';
        $api_token = $this->api_token();
        $zone_id   = $this->zone_id();

        if ( empty( $api_token ) ) { $this->redirect_with( 'missing_token', '', '' ); }
        if ( empty( $zone_id ) )   { $this->redirect_with( 'missing_zone', '', '' ); }

        $endpoint = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone_id ) . '/purge_cache';

        if ( $type === 'everything' ) {
            $body = array( 'purge_everything' => true );
        } elseif ( $type === 'host' ) {
            $host  = parse_url( home_url(), PHP_URL_HOST );
            $hosts = array( $host );
            if ( strpos( $host, 'www.' ) !== 0 ) $hosts[] = 'www.' . $host;
            $hosts = array_values( array_unique( $hosts ) );
            $body  = array( 'hosts' => $hosts );
        } else { // 'url'
            $file = isset($_GET['file']) ? esc_url_raw( wp_unslash($_GET['file']) ) : '';
            if ( empty( $file ) ) $file = $this->current_url_from_referer( wp_get_referer() );
            if ( ! $file ) $file = home_url( '/' );
            $body = array( 'files' => array( $file ) );
        }

        $headers = array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_token,
        );

        $response = wp_remote_post( $endpoint, array(
            'timeout' => 25,
            'headers' => $headers,
            'body'    => wp_json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            $this->redirect_with( 'error_wp', $type, $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $json = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 && is_array( $json ) && ! empty( $json['success'] ) ) {
            $this->redirect_with( 'success', $type, '' );
        }

        $err = 'Unknown error';
        if ( is_array( $json ) && ! empty( $json['errors'] ) ) {
            $err = wp_json_encode( $json['errors'] );
        }
        $this->redirect_with( 'error_api', $type, $err );
    }

    public function handle_verify() {
        // KEPT STRICT: Only Admins can verify/test the token settings
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
        check_admin_referer( 'cf_purge_verify' );

        $token = $this->api_token();
        if ( empty( $token ) ) $this->settings_redirect_with( 'verify_missing_token', '' );

        $res = wp_remote_get(
            'https://api.cloudflare.com/client/v4/user/tokens/verify',
            array( 'timeout'=>20, 'headers'=>array( 'Authorization' => 'Bearer ' . $token ) )
        );

        if ( is_wp_error( $res ) ) $this->settings_redirect_with( 'verify_error', $res->get_error_message() );

        $code = (int) wp_remote_retrieve_response_code( $res );
        $json = json_decode( wp_remote_retrieve_body( $res ), true );

        if ( 200 === $code && is_array( $json ) ) {
            $status = isset( $json['result']['status'] ) ? $json['result']['status'] : 'unknown';
            $id     = isset( $json['result']['id'] ) ? $json['result']['id'] : '';
            $msg    = sprintf( 'Token status: %s%s', esc_html( $status ), $id ? ' (ID: ' . esc_html( $id ) . ')' : '' );
            $this->settings_redirect_with( 'verify_ok', $msg );
        }

        $err = 'Unknown verification response';
        if ( is_array( $json ) && isset( $json['errors'] ) ) $err = wp_json_encode( $json['errors'] );
        $this->settings_redirect_with( 'verify_error', $err );
    }

    private function current_url_from_referer( $referer ) {
        if ( $referer ) return esc_url_raw( $referer );
        $scheme = ( is_ssl() || ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] === 'on' ) ) ? 'https' : 'http';
        $host   = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : parse_url( home_url(), PHP_URL_HOST );
        $uri    = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
        return $scheme . '://' . $host . $uri;
    }

    private function redirect_with( $flag, $type, $msg ) {
        $dest = wp_get_referer();
        if ( ! $dest ) $dest = admin_url();
        $dest = remove_query_arg( array( 'cfpm', 'cfpm_type', 'cfpm_msg' ), $dest );
        $args = array( 'cfpm' => $flag );
        if ( $type !== '' ) $args['cfpm_type'] = $type;
        if ( $msg  !== '' ) $args['cfpm_msg']  = $msg;
        wp_safe_redirect( add_query_arg( $args, $dest ) );
        exit;
    }

    private function settings_redirect_with( $flag, $msg ) {
        $dest = admin_url( 'options-general.php?page=cf-purge-settings' );
        $dest = remove_query_arg( array( 'cfps', 'cfps_msg' ), $dest );
        $args = array( 'cfps' => $flag );
        if ( $msg !== '' ) $args['cfps_msg'] = $msg;
        wp_safe_redirect( add_query_arg( $args, $dest ) );
        exit;
    }

    /* ---------------------------
     * Auto-purge on content changes
     * -------------------------- */

    private function auto_enabled() {
        if ( defined( 'CF_PURGE_AUTO' ) ) return (bool) CF_PURGE_AUTO;
        return (bool) get_option( self::OPT_AUTOPURGE, 0 );
    }

    private function auto_post_is_relevant( $post ) {
        if ( ! $post instanceof WP_Post ) return false;
        if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) return false;
        return is_post_type_viewable( $post->post_type );
    }

    // Old URL of a published post whose slug/parent changed, or that was unpublished/trashed.
    public function auto_on_post_updated( $post_id, $post_after, $post_before ) {
        if ( ! $this->auto_post_is_relevant( $post_before ) ) return;
        if ( $post_before->post_status !== 'publish' ) return;

        $old = get_permalink( $post_before );
        $new = get_permalink( $post_after );
        if ( $old && $old !== $new ) {
            $this->auto_queue_urls( array( $old ), 'URL change: ' . $post_before->post_title );
        }
    }

    // Only changes the public can see: publishing, updating a published post, or unpublishing.
    public function auto_on_transition( $new_status, $old_status, $post ) {
        if ( ! $post instanceof WP_Post ) return;
        if ( $new_status !== 'publish' && $old_status !== 'publish' ) return;

        if ( $post->post_type === 'elementor_library' ) {
            $this->auto_queue_everything( 'Elementor template: ' . $post->post_title );
            return;
        }
        if ( ! $this->auto_post_is_relevant( $post ) ) return;

        $this->auto_queue_urls( $this->auto_urls_for_post( $post ), ucfirst( $post->post_type ) . ': ' . $post->post_title );
    }

    // Elementor editor saves don't always transition the post status, so hook them directly.
    public function auto_on_elementor_save( $document, $data ) {
        if ( ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) ) return;
        $post = get_post( $document->get_main_id() );
        if ( ! $post || $post->post_status !== 'publish' ) return;

        if ( $post->post_type === 'elementor_library' ) {
            // Headers, footers, popups, kits and saved sections can appear on any page.
            $this->auto_queue_everything( 'Elementor template: ' . $post->post_title );
            return;
        }
        if ( ! $this->auto_post_is_relevant( $post ) ) return;

        $this->auto_queue_urls( $this->auto_urls_for_post( $post ), 'Elementor: ' . $post->post_title );
    }

    public function auto_on_menu_update( $menu_id ) {
        $this->auto_queue_everything( 'Menu updated' );
    }

    // A cached page keeps the old form markup and Gravity Forms' state check, so submissions fail until it is purged.
    public function auto_on_gform_meta( $form_meta, $form_id, $meta_name ) {
        if ( $meta_name !== 'display_meta' ) return;
        $this->auto_queue_form( $form_id );
    }

    public function auto_on_gform_status( $form_id ) {
        $this->auto_queue_form( $form_id );
    }

    private function auto_queue_form( $form_id ) {
        $form_id = absint( $form_id );
        if ( ! $form_id ) return;
        $reason = 'Gravity Form #' . $form_id;

        $urls = array();
        foreach ( $this->auto_posts_with_form( $form_id ) as $post ) {
            if ( $post->post_type === 'elementor_library' ) {
                $this->auto_queue_everything( $reason . ' in Elementor template: ' . $post->post_title );
                return;
            }
            if ( $this->auto_post_is_relevant( $post ) ) $urls[] = get_permalink( $post );
        }

        /**
         * Filter the URLs auto-purged when a Gravity Form changes.
         *
         * @param string[] $urls
         * @param int      $form_id
         */
        $urls = apply_filters( 'cf_purge_auto_form_urls', $urls, $form_id );

        if ( empty( $urls ) ) {
            // Embedded somewhere we can't see (widget, theme template, another builder).
            $this->auto_queue_everything( $reason . ' (no page found)' );
            return;
        }
        $this->auto_queue_urls( $urls, $reason );
    }

    // Published posts embedding the form by shortcode, block or Elementor data.
    private function auto_posts_with_form( $form_id ) {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT ID, post_content AS data FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type <> 'revision'
               AND ( post_content LIKE '%[gravityform%' OR post_content LIKE '%wp:gravityforms/form%' )
             UNION ALL
             SELECT p.ID, m.meta_value AS data FROM {$wpdb->postmeta} m
             JOIN {$wpdb->posts} p ON p.ID = m.post_id
             WHERE m.meta_key = '_elementor_data' AND p.post_status = 'publish' AND p.post_type <> 'revision'
               AND m.meta_value LIKE '%gravityform%'"
        );

        $shortcode = '/\[gravityforms?\s[^\]]*\bid=["\']?' . $form_id . '(?![0-9])/';
        $block     = '/"formId":"?' . $form_id . '(?![0-9])/';

        $posts = array();
        foreach ( (array) $rows as $row ) {
            if ( isset( $posts[ $row->ID ] ) ) continue;
            // Elementor stores JSON, so shortcode quotes arrive as \".
            $data = str_replace( '\\', '', (string) $row->data );
            if ( ! preg_match( $shortcode, $data ) && ! preg_match( $block, $data ) ) continue;
            $post = get_post( $row->ID );
            if ( $post ) $posts[ $row->ID ] = $post;
        }
        return array_values( $posts );
    }

    private function auto_urls_for_post( $post ) {
        $urls = array( get_permalink( $post ), home_url( '/' ) );

        $archive = get_post_type_archive_link( $post->post_type );
        if ( $archive ) $urls[] = $archive;

        foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
            if ( empty( $tax->public ) || empty( $tax->publicly_queryable ) ) continue;
            $terms = get_the_terms( $post, $tax->name );
            if ( ! is_array( $terms ) ) continue;
            foreach ( $terms as $term ) {
                $link = get_term_link( $term );
                if ( ! is_wp_error( $link ) ) $urls[] = $link;
            }
        }

        /**
         * Filter the URLs auto-purged when a post changes.
         *
         * @param string[] $urls
         * @param WP_Post  $post
         */
        return apply_filters( 'cf_purge_auto_urls', $urls, $post );
    }

    private function auto_queue_urls( $urls, $reason ) {
        foreach ( (array) $urls as $url ) {
            if ( ! is_string( $url ) || $url === '' ) continue;
            // "https://example.com" and "https://example.com/" are the same cache entry.
            if ( (string) parse_url( $url, PHP_URL_PATH ) === '' ) $url .= '/';
            $this->auto_queue[ $url ] = true;
        }
        $this->auto_add_reason( $reason );
        $this->auto_hook_shutdown();
    }

    private function auto_queue_everything( $reason ) {
        $this->auto_everything = true;
        $this->auto_add_reason( $reason );
        $this->auto_hook_shutdown();
    }

    private function auto_add_reason( $reason ) {
        if ( $reason === '' || strpos( $this->auto_reason, $reason ) !== false ) return;
        $this->auto_reason = $this->auto_reason === '' ? $reason : $this->auto_reason . '; ' . $reason;
    }

    // One purge per request, however many hooks fired.
    private function auto_hook_shutdown() {
        if ( $this->auto_shutdown_hooked ) return;
        add_action( 'shutdown', array( $this, 'auto_flush' ) );
        $this->auto_shutdown_hooked = true;
    }

    public function auto_flush() {
        if ( ! $this->auto_everything && empty( $this->auto_queue ) ) return;

        $files = array_values( array_unique( array_filter( array_map( 'esc_url_raw', array_keys( $this->auto_queue ) ) ) ) );

        /**
         * Filter whether an auto-purge should purge everything instead of individual URLs.
         *
         * @param bool     $everything
         * @param string[] $files
         */
        $everything = (bool) apply_filters( 'cf_purge_auto_purge_everything', $this->auto_everything, $files );

        if ( $everything ) {
            $result = $this->api_purge( array( 'purge_everything' => true ) );
            $scope  = 'everything';
        } else {
            $result = true;
            foreach ( array_chunk( $files, self::MAX_FILES_PER_CALL ) as $chunk ) {
                $result = $this->api_purge( array( 'files' => $chunk ) );
                if ( $result !== true ) break;
            }
            $scope = sprintf( '%d URL(s)', count( $files ) );
        }

        update_option( self::OPT_AUTO_LAST, array(
            'time'   => time(),
            'scope'  => $scope,
            'reason' => $this->auto_reason,
            'ok'     => $result === true,
            'error'  => $result === true ? '' : (string) $result,
        ), false );

        if ( $result !== true ) error_log( 'Cloudflare Purge (auto): ' . $result );

        $this->auto_queue      = array();
        $this->auto_everything = false;
        $this->auto_reason     = '';
    }

    /** Returns true on success, or an error message. */
    private function api_purge( $body ) {
        $api_token = $this->api_token();
        $zone_id   = $this->zone_id();
        if ( empty( $api_token ) ) return 'Missing API Token';
        if ( empty( $zone_id ) )   return 'Missing Zone ID';

        $response = wp_remote_post(
            'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone_id ) . '/purge_cache',
            array(
                'timeout' => 10,
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_token,
                ),
                'body'    => wp_json_encode( $body ),
            )
        );

        if ( is_wp_error( $response ) ) return $response->get_error_message();

        $code = (int) wp_remote_retrieve_response_code( $response );
        $json = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 && is_array( $json ) && ! empty( $json['success'] ) ) return true;
        if ( is_array( $json ) && ! empty( $json['errors'] ) ) return wp_json_encode( $json['errors'] );
        return 'HTTP ' . $code;
    }

    /* ---------------------------
     * Notices
     * -------------------------- */

    public function notices() {
        if ( ! empty( $_GET['cfpm'] ) ) {
            $flag = sanitize_text_field( wp_unslash( $_GET['cfpm'] ) );
            $type = isset( $_GET['cfpm_type'] ) ? esc_html( wp_unslash( $_GET['cfpm_type'] ) ) : '';
            $msg  = isset( $_GET['cfpm_msg'] )  ? esc_html( wp_unslash( $_GET['cfpm_msg'] ) )  : '';

            if ( $flag === 'missing_token' ) {
                echo '<div class="notice notice-error"><p><strong>Cloudflare Purge:</strong> Missing API Token. Add it in <a href="' . esc_url( admin_url( 'options-general.php?page=cf-purge-settings' ) ) . '">Settings → Cloudflare Purge</a>, or define <code>CF_PURGE_API_TOKEN</code> in <code>wp-config.php</code>.</p></div>';
                return;
            }
            if ( $flag === 'missing_zone' ) {
                echo '<div class="notice notice-error"><p><strong>Cloudflare Purge:</strong> Missing Zone ID. Add it in <a href="' . esc_url( admin_url( 'options-general.php?page=cf-purge-settings' ) ) . '">Settings</a>, define <code>CF_PURGE_ZONE_ID</code>, or enable Auto-discover.</p></div>';
                return;
            }
            if ( $flag === 'error_wp' ) {
                echo '<div class="notice notice-error"><p><strong>Cloudflare Purge (WP HTTP error):</strong> ' . $msg . '</p></div>';
                return;
            }
            if ( $flag === 'error_api' ) {
                echo '<div class="notice notice-error"><p><strong>Cloudflare Purge (API error, ' . $type . '):</strong> ' . $msg . '</p></div>';
                return;
            }
            if ( $flag === 'success' ) {
                $label = ( $type === 'host' ) ? 'by Host' : ( $type === 'url' ? 'for URL' : 'Everything' );
                echo '<div class="notice notice-success is-dismissible"><p>Cloudflare purge successful (' . $label . ').</p></div>';
                return;
            }
        }

        if ( ! empty( $_GET['cfps'] ) ) {
            $flag = sanitize_text_field( wp_unslash( $_GET['cfps'] ) );
            $msg  = isset( $_GET['cfps_msg'] ) ? esc_html( wp_unslash( $_GET['cfps_msg'] ) ) : '';

            if ( $flag === 'verify_ok' ) {
                echo '<div class="notice notice-success is-dismissible"><p><strong>Cloudflare:</strong> ' . $msg . '</p></div>';
                return;
            }
            if ( $flag === 'verify_error' ) {
                echo '<div class="notice notice-error"><p><strong>Cloudflare Verify Error:</strong> ' . $msg . '</p></div>';
                return;
            }
            if ( $flag === 'verify_missing_token' ) {
                echo '<div class="notice notice-error"><p><strong>Cloudflare Verify:</strong> Add an API token first.</p></div>';
                return;
            }
        }
    }

    /* ---------------------------
     * Settings Screen
     * -------------------------- */

    public function settings_page() {
        add_options_page(
            'Cloudflare Purge',
            'Cloudflare Purge',
            'manage_options',
            'cf-purge-settings',
            array( $this, 'render_settings' )
        );
    }

    public function register_settings() {
        register_setting( 'cf_purge_group', self::OPT_TOKEN, array(
            'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_token' ), 'default' => '',
        ) );
        register_setting( 'cf_purge_group', self::OPT_ZONE_ID, array(
            'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_zone' ), 'default' => '',
        ) );
        register_setting( 'cf_purge_group', self::OPT_AUTODISCOVER, array(
            'type' => 'boolean', 'sanitize_callback' => array( $this, 'sanitize_bool' ), 'default' => 0,
        ) );
        register_setting( 'cf_purge_group', self::OPT_AUTOPURGE, array(
            'type' => 'boolean', 'sanitize_callback' => array( $this, 'sanitize_bool' ), 'default' => 0,
        ) );
    }

    public function sanitize_token( $v ) { return is_string( $v ) ? trim( $v ) : ''; }
    public function sanitize_zone( $v ) {
        $v = is_string( $v ) ? trim( $v ) : '';
        if ( $v !== '' && ! preg_match( '/^[a-z0-9\-]{10,40}$/i', $v ) ) {
            add_settings_error( self::OPT_ZONE_ID, 'cf_zone_invalid', 'Zone ID looks invalid.' );
        }
        return $v;
    }
    public function sanitize_bool( $v ) { return $v ? 1 : 0; }

    public function render_settings() {
        // KEPT STRICT: Only Admins see settings
        if ( ! current_user_can( 'manage_options' ) ) return;

        $tok_opt  = get_option( self::OPT_TOKEN, '' );
        $zone_opt = get_option( self::OPT_ZONE_ID, '' );
        $auto_opt = get_option( self::OPT_AUTODISCOVER, 0 );
        $purge_opt   = get_option( self::OPT_AUTOPURGE, 0 );
        $purge_const = defined( 'CF_PURGE_AUTO' );
        $purge_last  = get_option( self::OPT_AUTO_LAST, array() );

        $tok_src  = ( defined( 'CF_PURGE_API_TOKEN' ) && CF_PURGE_API_TOKEN ) ? 'constant' : 'db';
        $zone_src = ( defined( 'CF_PURGE_ZONE_ID' ) && CF_PURGE_ZONE_ID ) ? 'constant' : ( $auto_opt ? 'auto' : 'db' );

        ?>
        <div class="wrap">
            <h1>Cloudflare Purge — Settings</h1>

            <?php if ( $tok_src === 'constant' || $zone_src === 'constant' ) : ?>
                <div class="notice notice-info">
                    <p><strong>Heads up:</strong> Values defined in <code>wp-config.php</code> override these fields:
                        <?php if ( $tok_src === 'constant' ) echo ' <code>CF_PURGE_API_TOKEN</code>'; ?>
                        <?php if ( $zone_src === 'constant' ) echo ' <code>CF_PURGE_ZONE_ID</code>'; ?>
                    </p>
                </div>
            <?php endif; ?>

            <form action="options.php" method="post">
                <?php settings_fields( 'cf_purge_group' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cf_purge_api_token">API Token</label></th>
                        <td>
                            <input type="password" id="cf_purge_api_token" name="<?php echo esc_attr( self::OPT_TOKEN ); ?>" value="<?php echo esc_attr( $tok_opt ); ?>" class="regular-text" autocomplete="off" <?php disabled( $tok_src === 'constant' ); ?> />
                            <p class="description">Use an API Token with <strong>Zone.Cache Purge: Write</strong>. Add <strong>Zone: Read</strong> if you enable Auto-discover.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cf_purge_zone_id">Zone ID</label></th>
                        <td>
                            <input type="text" id="cf_purge_zone_id" name="<?php echo esc_attr( self::OPT_ZONE_ID ); ?>" value="<?php echo esc_attr( $zone_opt ); ?>" class="regular-text" <?php disabled( $zone_src === 'constant' ); ?> />
                            <p class="description">Optional when Auto-discover is enabled.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Auto-discover Zone ID</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( self::OPT_AUTODISCOVER ); ?>" value="1" <?php checked( $auto_opt, 1 ); ?> />
                                Find the Zone ID for this site’s domain via API (cached 7 days).
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Auto-purge</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( self::OPT_AUTOPURGE ); ?>" value="1" <?php checked( $purge_const ? $this->auto_enabled() : $purge_opt, 1 ); ?> <?php disabled( $purge_const ); ?> />
                                Purge Cloudflare automatically when content changes.
                            </label>
                            <p class="description">Publishing, updating or unpublishing purges that page, the homepage, its archive and its category/tag pages. Saving an Elementor template or a menu purges everything. Saving a Gravity Form purges the pages it is on (everything if it is in an Elementor template or no page is found). Turn this on if your Cloudflare cache rule uses a long Edge TTL.</p>
                            <?php if ( $purge_const ) : ?>
                                <p class="description">Set by <code>CF_PURGE_AUTO</code> in <code>wp-config.php</code>.</p>
                            <?php endif; ?>
                            <?php if ( ! empty( $purge_last['time'] ) ) : ?>
                                <p class="description">
                                    <strong>Last auto-purge:</strong>
                                    <?php echo esc_html( wp_date( 'j M Y H:i:s', (int) $purge_last['time'] ) ); ?> —
                                    <?php echo esc_html( $purge_last['scope'] ); ?> —
                                    <?php echo ! empty( $purge_last['ok'] ) ? 'OK' : 'Failed: ' . esc_html( $purge_last['error'] ); ?>
                                    <?php if ( ! empty( $purge_last['reason'] ) ) : ?><br><em><?php echo esc_html( $purge_last['reason'] ); ?></em><?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Save Changes' ); ?>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em;">
                <?php wp_nonce_field( 'cf_purge_verify' ); ?>
                <input type="hidden" name="action" value="cf_purge_verify" />
                <?php submit_button( 'Verify token', 'secondary', 'submit', false ); ?>
            </form>
        </div>
        <?php
    }

    public function settings_link( $links ) {
        // Only show Settings link on plugins page if user is Admin
        if ( current_user_can('manage_options') ) {
             $url = admin_url( 'options-general.php?page=cf-purge-settings' );
             $links[] = '<a href="' . esc_url( $url ) . '">Settings</a>';
        }
        return $links;
    }

    /* ---------------------------
     * Cloudflare Helpers
     * -------------------------- */

    private function discover_zone_id_for_host( $host ) {
        $token = $this->api_token();
        if ( empty( $token ) || empty( $host ) ) return '';

        $candidates = $this->zone_name_candidates( $host );

        foreach ( $candidates as $name ) {
            $url = add_query_arg(
                array( 'name' => $name, 'status' => 'active', 'per_page' => 1 ),
                'https://api.cloudflare.com/client/v4/zones'
            );
            $res = wp_remote_get( $url, array(
                'timeout' => 15,
                'headers' => array( 'Authorization' => 'Bearer ' . $token ),
            ) );
            if ( is_wp_error( $res ) ) continue;

            $code = (int) wp_remote_retrieve_response_code( $res );
            $json = json_decode( wp_remote_retrieve_body( $res ), true );
            if ( $code === 200 && ! empty( $json['result'] ) && is_array( $json['result'] ) ) {
                $zone = $json['result'][0];
                if ( isset( $zone['id'] ) ) return $zone['id'];
            }
        }
        return '';
    }

    private function zone_name_candidates( $host ) {
        $labels = explode( '.', $host );
        $out = array();
        for ( $i = 2; $i <= count( $labels ); $i++ ) {
            $out[] = implode( '.', array_slice( $labels, -$i ) );
        }
        usort( $out, function( $a, $b ){ return substr_count( $a, '.' ) <=> substr_count( $b, '.' ); });
        return array_values( array_unique( $out ) );
    }

    /* ---------------------------
     * Dashboard Widget
     * -------------------------- */

    public function cf_add_dashboard_widget() {
        // MODIFIED: Allow Authors/Editors to see the widget
        if ( ! current_user_can('publish_posts') ) return;

        wp_add_dashboard_widget(
            'cf_purge_widget',
            'Cloudflare Purge', // visible title
            array( $this, 'cf_render_dashboard_widget' )
        );
    }

    public function cf_dashboard_widget_css( $hook ) {
        if ( $hook !== 'index.php' ) return;

        $css = '
        #cf_purge_widget .cfpw-header{
            display:flex;align-items:center;gap:.75rem;margin:-6px -12px 12px -12px;padding:12px;
            border-bottom:1px solid #e5e5e5;background:#f8f9fa;
        }
        #cf_purge_widget .cfpw-header svg{width:180px;height:auto;display:block}
        #cf_purge_widget .cfpw-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:8px}
        #cf_purge_widget .button.cfpw-btn svg{width:14px;height:14px;margin-right:6px;vertical-align:-2px}
        #cf_purge_widget .cfpw-sub{margin-top:6px;color:#666}
        /* Cloudflare orange primary */
        #cf_purge_widget .button.cfpw-orange{
            background:#fcad32;border-color:#fcad32;color:#1e1e1e;text-shadow:none;box-shadow:none;
        }
        #cf_purge_widget .button.cfpw-orange:hover,
        #cf_purge_widget .button.cfpw-orange:focus{
            background:#f78100;border-color:#f78100;color:#1e1e1e;
        }
        ';
        // Best-effort injection (covers classic & most custom admin themes)
        echo '<style>' . $css . '</style>';
    }

    public function cf_render_dashboard_widget() {
        // MODIFIED: Allow Authors/Editors to use the widget
        if ( ! current_user_can('publish_posts') ) { echo '<p>No access.</p>'; return; }

        $nonce      = wp_create_nonce( 'cf_purge_simple' );
        $admin_post = admin_url( 'admin-post.php' );

        $url_all  = add_query_arg(
            array( 'action'=>'cf_purge_simple','type'=>'everything','_wpnonce'=>$nonce ),
            $admin_post
        );

        $url_host = add_query_arg(
            array( 'action'=>'cf_purge_simple','type'=>'host','_wpnonce'=>$nonce ),
            $admin_post
        );

        $home     = home_url( '/' );
        $url_home = add_query_arg(
            array( 'action'=>'cf_purge_simple','type'=>'url','file'=>$home,'_wpnonce'=>$nonce ),
            $admin_post
        );

        $settings_url = admin_url( 'options-general.php?page=cf-purge-settings' );

        $zone_id = $this->zone_id();
        $host    = parse_url( $home, PHP_URL_HOST );

        ?>
        <div class="cfpw-header">
            <svg id="Layer_1" data-name="Layer 1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 651.29 94.76" aria-hidden="true" focusable="false" role="img">
                <defs><style>.cls-1{fill:#f78100;}.cls-2{fill:#fcad32;}</style></defs>
                <path class="cls-1" d="M143.05,93.42l1.07-3.71c1.27-4.41.8-8.48-1.34-11.48-2-2.76-5.26-4.38-9.25-4.57L58,72.7a1.47,1.47,0,0,1-1.35-2,2,2,0,0,1,1.75-1.34l76.26-1c9-.41,18.84-7.75,22.27-16.71l4.34-11.36a2.68,2.68,0,0,0,.18-1,3.31,3.31,0,0,0-.06-.54,49.67,49.67,0,0,0-95.49-5.14,22.35,22.35,0,0,0-35,23.42A31.73,31.73,0,0,0,.34,93.45a1.47,1.47,0,0,0,1.45,1.27l139.49,0h0A1.83,1.83,0,0,0,143.05,93.42Z"/>
                <path class="cls-2" d="M168.22,41.15q-1,0-2.1.06a.88.88,0,0,0-.32.07,1.17,1.17,0,0,0-.76.8l-3,10.26c-1.28,4.41-.81,8.48,1.34,11.48a11.65,11.65,0,0,0,9.24,4.57l16.11,1a1.44,1.44,0,0,1,1.14.62,1.5,1.5,0,0,1,.17,1.37,2,2,0,0,1-1.75,1.34l-16.73,1c-9.09.42-18.88,7.75-22.31,16.7l-1.21,3.16a.9.9,0,0,0,.79,1.22h57.63A1.55,1.55,0,0,0,208,93.63a41.34,41.34,0,0,0-39.76-52.48Z"/>
                <polygon points="273.03 59.66 282.56 59.66 282.56 85.72 299.23 85.72 299.23 94.07 273.03 94.07 273.03 59.66"/>
                <path d="M309.11,77v-.09c0-9.88,8-17.9,18.58-17.9s18.48,7.92,18.48,17.8v.1c0,9.88-8,17.89-18.58,17.89S309.11,86.85,309.11,77m27.33,0v-.09c0-5-3.59-9.29-8.85-9.29s-8.7,4.22-8.7,9.19v.1c0,5,3.59,9.29,8.8,9.29s8.75-4.23,8.75-9.2"/>
                <path d="M357.84,79V59.66h9.69V78.78c0,5,2.5,7.33,6.34,7.33s6.34-2.26,6.34-7.08V59.66h9.68V78.73c0,11.11-6.34,16-16.12,16s-15.93-5-15.93-15.73"/>
                <path d="M404.49,59.66h13.27c12.29,0,19.42,7.08,19.42,17v.1c0,9.93-7.23,17.3-19.61,17.3H404.49Zm13.42,26c5.7,0,9.49-3.15,9.49-8.71v-.09c0-5.51-3.79-8.71-9.49-8.71H414V85.62Z"/>
                <polygon points="451.04 59.66 478.56 59.66 478.56 68.02 460.58 68.02 460.58 73.87 476.85 73.87 476.85 81.78 460.58 81.78 460.58 94.07 451.04 94.07 451.04 59.66"/>
                <polygon points="491.84 59.66 501.37 59.66 501.37 85.72 518.04 85.72 518.04 94.07 491.84 94.07 491.84 59.66"/>
                <path d="M543,59.42h9.19L566.8,94.07H556.58l-2.51-6.14H540.79l-2.45,6.14h-10Zm8.35,21.08-3.83-9.78L543.6,80.5Z"/>
                <path d="M579.08,59.66h16.27c5.27,0,8.9,1.38,11.21,3.74a10.64,10.64,0,0,1,3.05,8v.1a10.88,10.88,0,0,1-7.08,10.57l8.21,12h-11L592.8,83.65h-4.18V94.07h-9.54Zm15.83,16.52c3.25,0,5.12-1.58,5.12-4.08V72c0-2.71-2-4.08-5.17-4.08h-6.24v8.26Z"/>
                <polygon points="623.37 59.66 651.05 59.66 651.05 67.77 632.81 67.77 632.81 72.98 649.33 72.98 649.33 80.5 632.81 80.5 632.81 85.96 651.29 85.96 651.29 94.07 623.37 94.07 623.37 59.66"/>
                <path d="M252.15,81a8.44,8.44,0,0,1-7.88,5.16c-5.22,0-8.8-4.33-8.8-9.29v-.1c0-5,3.49-9.2,8.7-9.2a8.64,8.64,0,0,1,8.18,5.71h10C260.79,65.09,253.6,59,244.27,59c-10.62,0-18.58,8-18.58,17.9V77c0,9.88,7.86,17.8,18.48,17.8,9.08,0,16.18-5.88,18.05-13.76Z"/>
            </svg>
        </div>

        <div class="cfpw-row">
            <a class="button cfpw-btn cfpw-orange" href="<?php echo esc_url( $url_all ); ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h18M12 3v18" fill="none" stroke="currentColor" stroke-width="2"/></svg>
                Purge Everything
            </a>

            <a class="button cfpw-btn" href="<?php echo esc_url( $url_host ); ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h16M4 16h16M4 8h16" fill="none" stroke="currentColor" stroke-width="2"/></svg>
                Purge Host
            </a>

            <a class="button cfpw-btn" href="<?php echo esc_url( $url_home ); ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l9 8h-3v9H6v-9H3l9-8z" fill="currentColor"/></svg>
                Purge Home URL
            </a>
        </div>

        <p class="cfpw-sub">
            <strong>Zone ID:</strong> <?php echo $zone_id ? esc_html( $zone_id ) : '<em>not set</em>'; ?>
            &nbsp;•&nbsp;
            <strong>Host:</strong> <?php echo esc_html( $host ); ?>
            <?php if ( current_user_can( 'manage_options' ) ) : ?>
            &nbsp;•&nbsp;
            <a href="<?php echo esc_url( $settings_url ); ?>" class="button-link">Settings</a>
            <?php endif; ?>
        </p>
        <?php
    }
}

/* Boot */
new CF_AdminBar_Purge_Portable();

/**
 * Optional overrides in wp-config.php:
 *
 * define('CF_PURGE_API_TOKEN', '...'); // Zone → Cache Purge: Write (and Zone: Read if using autodiscover)
 * define('CF_PURGE_ZONE_ID',   '...'); // to hardcode a Zone ID
 * define('CF_PURGE_AUTO',      true);  // force auto-purge on/off regardless of the setting
 */