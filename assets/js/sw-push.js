/**
 * Push Notification Service Worker
 *
 * Handles receiving and displaying push notifications
 * when the browser/tab is in the background.
 *
 * @package Podcast_Influence_Tracker
 * @since 3.6.0
 */

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
            body: event.data.text(),
            icon: '/wp-content/plugins/podcast-influence-tracker/assets/img/icon-192.png'
        };
    }

    const options = {
        body: data.body || data.message || '',
        icon: data.icon || '/wp-content/plugins/podcast-influence-tracker/assets/img/icon-192.png',
        badge: data.badge || '/wp-content/plugins/podcast-influence-tracker/assets/img/badge-72.png',
        tag: data.tag || 'guestify-notification',
        data: {
            url: data.url || data.action_url || '/',
            notification_id: data.notification_id || null
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
        fetch('/wp-json/guestify/v1/notifications/' + notificationId + '/read', {
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
    event.waitUntil(
        self.registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: self.applicationServerKey
        }).then(function(subscription) {
            // Re-register with server
            return fetch('/wp-json/guestify/v1/notifications/subscribe', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                credentials: 'same-origin',
                body: JSON.stringify(subscription.toJSON())
            });
        })
    );
});
