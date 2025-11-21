<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GPF_Ajax {

    public static function init() {
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
        add_action( 'wp_ajax_load_more_podcast_episodes',        [ __CLASS__, 'load_more' ] );
        add_action( 'wp_ajax_nopriv_load_more_podcast_episodes', [ __CLASS__, 'load_more' ] );
    }

    public static function enqueue_scripts() {
        // Only load assets on the specific interview detail page (ID: 43072)
        if ( ! is_page( 43072 ) ) {
            return;
        }

        // Enqueue JavaScript
        wp_enqueue_script(
            'gpf-infinite-scroll',
            GPF_PLUGIN_URL . 'js/infinite-scroll.js', 
            [ 'jquery' ],
            '1.0.2', // Or your current version
            true
        );
        wp_localize_script( 'gpf-infinite-scroll', 'gpfAjax', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'gpf-load-more' ),
        ] );

        // Enqueue your new CSS file
        wp_enqueue_style(
            'gpf-episode-card-styles', // Handle for your stylesheet
            GPF_PLUGIN_URL . 'css/gpf-styles.css', // Path to your CSS file
            [], // Dependencies (usually none for basic CSS)
            '1.0.0' // Version number for your styles
            // No media argument needed for all screens by default
        );
    }

    public static function load_more() {
        check_ajax_referer( 'gpf-load-more', 'nonce' );

        $page    = isset( $_POST['page'] )           ? absint( $_POST['page'] )           : 1;
        $feed    = isset( $_POST['feed'] )           ? esc_url_raw( wp_unslash( $_POST['feed'] ) ) : '';
        $initial = isset( $_POST['initial_posts'] )  ? absint( $_POST['initial_posts'] )  : 10;
        $perpage = isset( $_POST['posts_per_page'] ) ? absint( $_POST['posts_per_page'] ) : 10;

        if ( empty( $feed ) ) {
            wp_send_json_error( 'Feed URL missing.' );
        }

        include_once ABSPATH . WPINC . '/feed.php';
        $rss = fetch_feed( $feed );
        if ( is_wp_error( $rss ) ) {
            wp_send_json_error( $rss->get_error_message() );
        }

        $offset = $initial + ( ( $page - 2 ) * $perpage );
        $offset = max( 0, $offset );

        $items = $rss->get_items( $offset, $perpage );
        if ( empty( $items ) ) {
            wp_send_json_success( '' ); 
        }

        $html = '';
        $card_type = 'recent'; // Set card type for AJAX loaded items
        foreach ( $items as $item ) {
            ob_start();
            $template = GPF_PLUGIN_DIR . 'includes/templates/episode-card.php'; 
            if ( file_exists( $template ) ) {
                // Make $card_type available to the template
                include $template;
            } else {
                $html .= '';
            }
            $html .= ob_get_clean();
        }

        wp_send_json_success( $html );
    }
}