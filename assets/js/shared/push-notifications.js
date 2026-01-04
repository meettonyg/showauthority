/**
 * Push Notifications Manager
 *
 * Handles service worker registration and push subscription management.
 *
 * @package Podcast_Influence_Tracker
 * @since 3.6.0
 */

const PushNotifications = (function() {
    'use strict';

    // Configuration (set via init)
    let config = {
        vapidPublicKey: null,
        nonce: '',
        serviceWorkerPath: '/wp-content/plugins/podcast-influence-tracker/assets/js/sw-push.js',
        restUrl: '/wp-json/guestify/v1/',
        pluginUrl: '/wp-content/plugins/podcast-influence-tracker/'
    };
    let isInitialized = false;

    /**
     * Initialize the push notifications manager
     * @param {Object} options Configuration object
     * @param {string} options.vapidPublicKey - VAPID public key
     * @param {string} options.nonce - WordPress REST API nonce
     * @param {string} options.serviceWorkerPath - Path to service worker file
     * @param {string} options.restUrl - Base REST API URL (e.g., '/wp-json/guestify/v1/')
     * @param {string} options.pluginUrl - Plugin base URL for assets
     */
    function init(options) {
        if (isInitialized) return;

        config = {
            vapidPublicKey: options.vapidPublicKey || null,
            nonce: options.nonce || '',
            serviceWorkerPath: options.serviceWorkerPath || config.serviceWorkerPath,
            restUrl: options.restUrl || config.restUrl,
            pluginUrl: options.pluginUrl || config.pluginUrl
        };

        isInitialized = true;
    }

    /**
     * Check if push notifications are supported
     * @returns {boolean}
     */
    function isSupported() {
        return 'serviceWorker' in navigator &&
               'PushManager' in window &&
               'Notification' in window;
    }

    /**
     * Get current permission status
     * @returns {string} 'granted', 'denied', or 'default'
     */
    function getPermission() {
        return Notification.permission;
    }

    /**
     * Request notification permission
     * @returns {Promise<string>} Permission status
     */
    async function requestPermission() {
        return await Notification.requestPermission();
    }

    /**
     * Build service worker URL with config params
     * @returns {string}
     */
    function buildServiceWorkerUrl() {
        const url = new URL(config.serviceWorkerPath, window.location.origin);
        url.searchParams.set('vapidKey', config.vapidPublicKey || '');
        url.searchParams.set('restUrl', config.restUrl);
        url.searchParams.set('pluginUrl', config.pluginUrl);
        return url.toString();
    }

    /**
     * Register the service worker
     * @returns {Promise<ServiceWorkerRegistration>}
     */
    async function registerServiceWorker() {
        if (!isSupported()) {
            throw new Error('Push notifications are not supported in this browser');
        }

        try {
            // Pass config to service worker via URL params
            const swUrl = buildServiceWorkerUrl();
            const registration = await navigator.serviceWorker.register(swUrl, {
                scope: '/'
            });
            console.log('Service Worker registered:', registration.scope);
            return registration;
        } catch (error) {
            console.error('Service Worker registration failed:', error);
            throw error;
        }
    }

    /**
     * Get existing subscription
     * @returns {Promise<PushSubscription|null>}
     */
    async function getSubscription() {
        const registration = await navigator.serviceWorker.ready;
        return await registration.pushManager.getSubscription();
    }

    /**
     * Subscribe to push notifications
     * @returns {Promise<Object>} Subscription details
     */
    async function subscribe() {
        if (!config.vapidPublicKey) {
            throw new Error('VAPID public key not configured');
        }

        const permission = await requestPermission();
        if (permission !== 'granted') {
            throw new Error('Notification permission denied');
        }

        const registration = await navigator.serviceWorker.ready;

        // Convert VAPID key to Uint8Array
        const applicationServerKey = urlBase64ToUint8Array(config.vapidPublicKey);

        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: applicationServerKey
        });

        // Send subscription to server using configured REST URL
        const response = await fetch(config.restUrl + 'notifications/subscribe', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                subscription: subscription.toJSON(),
                device_info: {
                    user_agent: navigator.userAgent,
                    device_name: getDeviceName()
                }
            })
        });

        if (!response.ok) {
            throw new Error('Failed to save subscription to server');
        }

        return await response.json();
    }

    /**
     * Unsubscribe from push notifications
     * @returns {Promise<boolean>}
     */
    async function unsubscribe() {
        const subscription = await getSubscription();
        if (!subscription) {
            return true;
        }

        const endpoint = subscription.endpoint;

        // Unsubscribe from browser
        const success = await subscription.unsubscribe();
        if (!success) {
            throw new Error('Failed to unsubscribe from browser');
        }

        // Remove from server using configured REST URL
        await fetch(config.restUrl + 'notifications/unsubscribe', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce
            },
            credentials: 'same-origin',
            body: JSON.stringify({ endpoint: endpoint })
        });

        return true;
    }

    /**
     * Check if currently subscribed
     * @returns {Promise<boolean>}
     */
    async function isSubscribed() {
        const subscription = await getSubscription();
        return subscription !== null;
    }

    /**
     * Convert URL-safe base64 to Uint8Array
     * @param {string} base64String
     * @returns {Uint8Array}
     */
    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding)
            .replace(/-/g, '+')
            .replace(/_/g, '/');

        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);

        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    /**
     * Get a friendly device name
     * @returns {string}
     */
    function getDeviceName() {
        const ua = navigator.userAgent;

        if (/iPhone/.test(ua)) return 'iPhone';
        if (/iPad/.test(ua)) return 'iPad';
        if (/Android/.test(ua)) return 'Android';
        if (/Windows/.test(ua)) return 'Windows';
        if (/Mac/.test(ua)) return 'Mac';
        if (/Linux/.test(ua)) return 'Linux';

        return 'Unknown Device';
    }

    // Public API
    return {
        init: init,
        isSupported: isSupported,
        getPermission: getPermission,
        requestPermission: requestPermission,
        registerServiceWorker: registerServiceWorker,
        subscribe: subscribe,
        unsubscribe: unsubscribe,
        isSubscribed: isSubscribed,
        getSubscription: getSubscription
    };
})();

// Export for module systems if available
if (typeof module !== 'undefined' && module.exports) {
    module.exports = PushNotifications;
}
