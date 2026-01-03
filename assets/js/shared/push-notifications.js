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

    // VAPID public key (to be set from WordPress)
    let vapidPublicKey = null;
    let apiNonce = null;
    let swPath = '/wp-content/plugins/podcast-influence-tracker/assets/js/sw-push.js';
    let isInitialized = false;

    /**
     * Initialize the push notifications manager
     * @param {Object} config Configuration object
     */
    function init(config) {
        if (isInitialized) return;

        vapidPublicKey = config.vapidPublicKey || null;
        apiNonce = config.nonce || '';
        swPath = config.serviceWorkerPath || swPath;

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
     * Register the service worker
     * @returns {Promise<ServiceWorkerRegistration>}
     */
    async function registerServiceWorker() {
        if (!isSupported()) {
            throw new Error('Push notifications are not supported in this browser');
        }

        try {
            const registration = await navigator.serviceWorker.register(swPath, {
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
        if (!vapidPublicKey) {
            throw new Error('VAPID public key not configured');
        }

        const permission = await requestPermission();
        if (permission !== 'granted') {
            throw new Error('Notification permission denied');
        }

        const registration = await navigator.serviceWorker.ready;

        // Convert VAPID key to Uint8Array
        const applicationServerKey = urlBase64ToUint8Array(vapidPublicKey);

        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: applicationServerKey
        });

        // Send subscription to server
        const response = await fetch('/wp-json/guestify/v1/notifications/subscribe', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': apiNonce
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

        // Remove from server
        await fetch('/wp-json/guestify/v1/notifications/unsubscribe', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': apiNonce
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
