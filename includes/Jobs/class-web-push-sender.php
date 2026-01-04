<?php
/**
 * Web Push Notification Sender
 *
 * Handles sending push notifications to subscribed browsers
 * using the Web Push protocol with the minishlink/web-push library.
 *
 * @package Podcast_Influence_Tracker
 * @since 3.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class PIT_Web_Push_Sender {

    /**
     * VAPID configuration option names
     */
    const VAPID_PUBLIC_KEY_OPTION = 'pit_vapid_public_key';
    const VAPID_PRIVATE_KEY_OPTION = 'pit_vapid_private_key';

    /**
     * Check if the web-push library is available
     *
     * @return bool
     */
    public static function is_library_available() {
        return class_exists('Minishlink\WebPush\WebPush');
    }

    /**
     * Send push notification to a user
     *
     * @param int   $user_id User ID
     * @param array $payload Notification payload
     * @return array Results for each subscription
     */
    public static function send_to_user($user_id, $payload) {
        $subscriptions = PIT_Push_Subscriptions_Schema::get_user_subscriptions($user_id);

        if (empty($subscriptions)) {
            return ['sent' => 0, 'failed' => 0, 'results' => []];
        }

        // Check if library is available
        if (!self::is_library_available()) {
            error_log('PIT Web Push: minishlink/web-push library not installed. Run: composer require minishlink/web-push');
            return [
                'sent'    => 0,
                'failed'  => count($subscriptions),
                'results' => [['success' => false, 'error' => 'Web Push library not installed']],
            ];
        }

        $results = [];
        $sent = 0;
        $failed = 0;

        foreach ($subscriptions as $subscription) {
            $result = self::send_to_subscription($subscription, $payload);
            $results[] = $result;

            if ($result['success']) {
                $sent++;
                PIT_Push_Subscriptions_Schema::mark_success($subscription['id']);
            } else {
                $failed++;
                PIT_Push_Subscriptions_Schema::mark_failed($subscription['id']);
            }
        }

        return [
            'sent'    => $sent,
            'failed'  => $failed,
            'results' => $results,
        ];
    }

    /**
     * Send push notification to a specific subscription
     *
     * @param array $subscription Subscription data
     * @param array $payload      Notification payload
     * @return array Result with success status
     */
    public static function send_to_subscription($subscription, $payload) {
        $vapid_public_key = get_option(self::VAPID_PUBLIC_KEY_OPTION);
        $vapid_private_key = get_option(self::VAPID_PRIVATE_KEY_OPTION);

        if (empty($vapid_public_key) || empty($vapid_private_key)) {
            return [
                'success' => false,
                'error'   => 'VAPID keys not configured',
            ];
        }

        if (!self::is_library_available()) {
            return [
                'success' => false,
                'error'   => 'Web Push library not installed',
            ];
        }

        try {
            // Create VAPID auth array
            $auth = [
                'VAPID' => [
                    'subject'    => 'mailto:' . get_option('admin_email', 'notifications@guestify.ai'),
                    'publicKey'  => $vapid_public_key,
                    'privateKey' => $vapid_private_key,
                ],
            ];

            // Create WebPush instance
            $webPush = new WebPush($auth);

            // Create subscription object
            $pushSubscription = Subscription::create([
                'endpoint' => $subscription['endpoint'],
                'keys'     => [
                    'p256dh' => $subscription['p256dh'],
                    'auth'   => $subscription['auth'],
                ],
            ]);

            // Prepare payload
            $json_payload = json_encode($payload);

            // Send notification
            $webPush->queueNotification($pushSubscription, $json_payload);

            // Flush and get results
            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    return ['success' => true];
                } else {
                    $reason = $report->getReason();
                    $statusCode = $report->getResponse() ? $report->getResponse()->getStatusCode() : null;

                    // Handle expired/invalid subscriptions
                    if ($statusCode === 410 || $statusCode === 404) {
                        PIT_Push_Subscriptions_Schema::delete_subscription($subscription['id']);
                        return [
                            'success' => false,
                            'error'   => 'Subscription expired or invalid',
                            'code'    => $statusCode,
                        ];
                    }

                    return [
                        'success' => false,
                        'error'   => $reason ?: 'Unknown error',
                        'code'    => $statusCode,
                    ];
                }
            }

            return [
                'success' => false,
                'error'   => 'No response from push service',
            ];

        } catch (Exception $e) {
            error_log('PIT Web Push Error: ' . $e->getMessage());
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Send push notifications in batch
     *
     * @param array $notifications Array of ['user_id' => int, 'payload' => array]
     * @return array Results summary
     */
    public static function send_batch($notifications) {
        if (!self::is_library_available()) {
            return [
                'sent'   => 0,
                'failed' => count($notifications),
                'error'  => 'Web Push library not installed',
            ];
        }

        $vapid_public_key = get_option(self::VAPID_PUBLIC_KEY_OPTION);
        $vapid_private_key = get_option(self::VAPID_PRIVATE_KEY_OPTION);

        if (empty($vapid_public_key) || empty($vapid_private_key)) {
            return [
                'sent'   => 0,
                'failed' => count($notifications),
                'error'  => 'VAPID keys not configured',
            ];
        }

        try {
            $auth = [
                'VAPID' => [
                    'subject'    => 'mailto:' . get_option('admin_email', 'notifications@guestify.ai'),
                    'publicKey'  => $vapid_public_key,
                    'privateKey' => $vapid_private_key,
                ],
            ];

            $webPush = new WebPush($auth);
            $subscription_map = [];

            // Queue all notifications
            foreach ($notifications as $notification) {
                $user_id = $notification['user_id'];
                $payload = $notification['payload'];
                $json_payload = json_encode($payload);

                $subscriptions = PIT_Push_Subscriptions_Schema::get_user_subscriptions($user_id);

                foreach ($subscriptions as $subscription) {
                    $pushSubscription = Subscription::create([
                        'endpoint' => $subscription['endpoint'],
                        'keys'     => [
                            'p256dh' => $subscription['p256dh'],
                            'auth'   => $subscription['auth'],
                        ],
                    ]);

                    $webPush->queueNotification($pushSubscription, $json_payload);
                    $subscription_map[$subscription['endpoint']] = $subscription['id'];
                }
            }

            // Flush all at once
            $sent = 0;
            $failed = 0;

            foreach ($webPush->flush() as $report) {
                $endpoint = $report->getRequest()->getUri()->__toString();
                $sub_id = $subscription_map[$endpoint] ?? null;

                if ($report->isSuccess()) {
                    $sent++;
                    if ($sub_id) {
                        PIT_Push_Subscriptions_Schema::mark_success($sub_id);
                    }
                } else {
                    $failed++;
                    if ($sub_id) {
                        PIT_Push_Subscriptions_Schema::mark_failed($sub_id);

                        // Remove expired subscriptions
                        $statusCode = $report->getResponse() ? $report->getResponse()->getStatusCode() : null;
                        if ($statusCode === 410 || $statusCode === 404) {
                            PIT_Push_Subscriptions_Schema::delete_subscription($sub_id);
                        }
                    }
                }
            }

            return [
                'sent'   => $sent,
                'failed' => $failed,
            ];

        } catch (Exception $e) {
            error_log('PIT Web Push Batch Error: ' . $e->getMessage());
            return [
                'sent'   => 0,
                'failed' => count($notifications),
                'error'  => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate VAPID key pair
     *
     * Call this once during plugin setup to generate keys.
     *
     * @return array|false Keys array or false on failure
     */
    public static function generate_vapid_keys() {
        // Try using the library first if available
        if (self::is_library_available() && class_exists('Minishlink\WebPush\VAPID')) {
            try {
                $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
                return [
                    'public_key'  => $keys['publicKey'],
                    'private_key' => $keys['privateKey'],
                ];
            } catch (Exception $e) {
                error_log('PIT VAPID generation via library failed: ' . $e->getMessage());
            }
        }

        // Fallback to manual generation
        if (!function_exists('openssl_pkey_new')) {
            return false;
        }

        $key = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if (!$key) {
            return false;
        }

        $details = openssl_pkey_get_details($key);

        if (!isset($details['ec']) || !isset($details['ec']['d']) || !isset($details['ec']['x']) || !isset($details['ec']['y'])) {
            return false;
        }

        // Private key is the 'd' component (32 bytes for P-256)
        $private_raw = $details['ec']['d'];

        // Public key is x || y (64 bytes total), prefixed with 0x04 for uncompressed format
        $public_raw = "\x04" . $details['ec']['x'] . $details['ec']['y'];

        return [
            'public_key'  => self::base64url_encode($public_raw),
            'private_key' => self::base64url_encode($private_raw),
        ];
    }

    /**
     * Check if VAPID keys are configured
     *
     * @return bool
     */
    public static function is_configured() {
        return !empty(get_option(self::VAPID_PUBLIC_KEY_OPTION))
            && !empty(get_option(self::VAPID_PRIVATE_KEY_OPTION));
    }

    /**
     * Save VAPID keys to options
     *
     * @param string $public_key  Base64url encoded public key
     * @param string $private_key Base64url encoded private key
     */
    public static function save_vapid_keys($public_key, $private_key) {
        update_option(self::VAPID_PUBLIC_KEY_OPTION, $public_key);
        update_option(self::VAPID_PRIVATE_KEY_OPTION, $private_key);
    }

    /**
     * Base64url encode
     *
     * @param string $data Data to encode
     * @return string
     */
    private static function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64url decode
     *
     * @param string $data Data to decode
     * @return string
     */
    private static function base64url_decode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
