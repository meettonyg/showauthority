/**
 * Push Notification Service Worker
 *
 * Handles receiving and displaying push notifications
 * when the browser/tab is in the background.
 *
 * Configuration is passed via URL query parameters during registration:
 * - vapidKey: The VAPID public key for re-subscription
 * - restUrl: Base REST API URL
 * - pluginUrl: Plugin assets URL for icons
 *
 * @package Podcast_Influence_Tracker
 * @since 3.6.0
 */

// Parse configuration from service worker URL
const swUrl = new URL(self.location.href);
const config = {
    vapidKey: swUrl.searchParams.get('vapidKey') || '',
    restUrl: swUrl.searchParams.get('restUrl') || '/wp-json/guestify/v1/',
    pluginUrl: swUrl.searchParams.get('pluginUrl') || '/wp-content/plugins/podcast-influence-tracker/'
};

/**
 * Convert URL-safe base64 to Uint8Array
 */
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding)
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const rawData = atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

// Handle push event
self.addEventListener('push', function(event) {
    if (!event.data) {
        console.log('Push event received but no data');
        return;
    }

    let data;
    try {
        data = event.data.json();
    } catch (e) {
        data = {
            title: 'Guestify',
            body: event.data.text()
        };
    }

    // Use configured plugin URL for default icons
    const defaultIcon = config.pluginUrl + 'assets/img/icon-192.png';
    const defaultBadge = config.pluginUrl + 'assets/img/badge-72.png';

    const options = {
        body: data.body || data.message || '',
        icon: data.icon || defaultIcon,
        badge: data.badge || defaultBadge,
        tag: data.tag || 'guestify-notification',
        data: {
            url: data.url || data.action_url || '/',
            notification_id: data.notification_id || null,
            restUrl: config.restUrl
        },
        vibrate: [100, 50, 100],
        requireInteraction: data.require_interaction || false,
        actions: data.actions || []
    };

    event.waitUntil(
        self.registration.showNotification(data.title || 'Guestify', options)
    );
});

// Handle notification click
self.addEventListener('notificationclick', function(event) {
    event.notification.close();

    const url = event.notification.data.url || '/';
    const restUrl = event.notification.data.restUrl || config.restUrl;

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
            // If a window is already open, focus it
            for (let client of clientList) {
                if (client.url.includes(url) && 'focus' in client) {
                    return client.focus();
                }
            }
            // Otherwise open a new window
            if (clients.openWindow) {
                return clients.openWindow(url);
            }
        })
    );

    // Track click if notification_id is present
    const notificationId = event.notification.data.notification_id;
    if (notificationId) {
        fetch(restUrl + 'notifications/' + notificationId + '/read', {
            method: 'POST',
            credentials: 'same-origin'
        }).catch(function(err) {
            console.log('Failed to mark notification as read:', err);
        });
    }
});

// Handle notification close
self.addEventListener('notificationclose', function(event) {
    // Optional: Track dismissals
    console.log('Notification closed', event.notification.tag);
});

// Handle push subscription change (e.g., browser refreshed keys)
self.addEventListener('pushsubscriptionchange', function(event) {
    // Only attempt re-subscription if we have the VAPID key
    if (!config.vapidKey) {
        console.log('Cannot re-subscribe: VAPID key not available');
        return;
    }

    event.waitUntil(
        self.registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(config.vapidKey)
        }).then(function(subscription) {
            // Re-register with server using correct payload format
            return fetch(config.restUrl + 'notifications/subscribe', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    subscription: subscription.toJSON(),
                    device_info: {
                        user_agent: 'ServiceWorker Re-subscription'
                    }
                })
            });
        }).catch(function(err) {
            console.log('Failed to re-subscribe:', err);
        })
    );
});
