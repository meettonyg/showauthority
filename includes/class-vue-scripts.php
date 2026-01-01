<?php
/**
 * Vue.js Script Registration Helper
 *
 * Centralized registration of Vue.js, Vue-Demi, and Pinia scripts.
 * Allows easy switching between CDN and local bundled files.
 *
 * @package Podcast_Influence_Tracker
 * @since 3.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Vue_Scripts {

    /**
     * Whether to use local vendor files instead of CDN
     * Set to false to use CDN (more reliable if vendor files not bundled)
     */
    const USE_LOCAL_VENDOR = false;

    /**
     * Script versions
     */
    const VUE_VERSION = '3.3.4';
    const VUE_DEMI_VERSION = '0.14.6';
    const PINIA_VERSION = '2.1.7';

    /**
     * Register Vue.js scripts
     * Call this from any shortcode that needs Vue + Pinia
     */
    public static function enqueue() {
        // Vue 3
        if (!wp_script_is('vue', 'registered')) {
            wp_register_script(
                'vue',
                self::get_vue_url(),
                [],
                self::VUE_VERSION,
                true
            );
        }
        wp_enqueue_script('vue');

        // Vue Demi (required for Pinia)
        if (!wp_script_is('vue-demi', 'registered')) {
            wp_register_script(
                'vue-demi',
                self::get_vue_demi_url(),
                ['vue'],
                self::VUE_DEMI_VERSION,
                true
            );
        }
        wp_enqueue_script('vue-demi');

        // Pinia
        if (!wp_script_is('pinia', 'registered')) {
            wp_register_script(
                'pinia',
                self::get_pinia_url(),
                ['vue', 'vue-demi'],
                self::PINIA_VERSION,
                true
            );
        }
        wp_enqueue_script('pinia');

        // Shared API utility
        if (!wp_script_is('guestify-api', 'registered')) {
            wp_register_script(
                'guestify-api',
                PIT_PLUGIN_URL . 'assets/js/shared/api.js',
                [],
                PIT_VERSION,
                true
            );
        }
        wp_enqueue_script('guestify-api');
    }

    /**
     * Get Vue.js URL (local or CDN)
     */
    private static function get_vue_url() {
        if (self::USE_LOCAL_VENDOR && self::local_file_exists('vue.global.prod.js')) {
            return PIT_PLUGIN_URL . 'assets/js/vendor/vue.global.prod.js';
        }
        return 'https://unpkg.com/vue@' . self::VUE_VERSION . '/dist/vue.global.prod.js';
    }

    /**
     * Get Vue-Demi URL (local or CDN)
     */
    private static function get_vue_demi_url() {
        if (self::USE_LOCAL_VENDOR && self::local_file_exists('vue-demi.iife.js')) {
            return PIT_PLUGIN_URL . 'assets/js/vendor/vue-demi.iife.js';
        }
        return 'https://unpkg.com/vue-demi@' . self::VUE_DEMI_VERSION . '/lib/index.iife.js';
    }

    /**
     * Get Pinia URL (local or CDN)
     */
    private static function get_pinia_url() {
        if (self::USE_LOCAL_VENDOR && self::local_file_exists('pinia.iife.js')) {
            return PIT_PLUGIN_URL . 'assets/js/vendor/pinia.iife.js';
        }
        return 'https://unpkg.com/pinia@' . self::PINIA_VERSION . '/dist/pinia.iife.js';
    }

    /**
     * Check if local vendor file exists
     */
    private static function local_file_exists($filename) {
        return file_exists(PIT_PLUGIN_DIR . 'assets/js/vendor/' . $filename);
    }

    /**
     * Get script dependencies for custom Vue apps
     * Use this when registering your own Vue-based scripts
     */
    public static function get_dependencies() {
        return ['vue', 'pinia', 'guestify-api'];
    }
}
