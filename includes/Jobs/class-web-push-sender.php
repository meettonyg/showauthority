<?php
/**
 * Web Push Notification Sender
 *
 * Handles sending push notifications to subscribed browsers
 * using the Web Push protocol.
 *
 * IMPORTANT: The payload encryption in this class is a placeholder implementation.
 * For production use, you MUST either:
 * 1. Install and use the minishlink/web-push library (recommended):
 *    composer require minishlink/web-push
 * 2. Implement full RFC 8291 encryption (ECDH + HKDF + AES-128-GCM)
 *
 * Without proper encryption, push notifications will be rejected by push services.
 *
 * @package Podcast_Influence_Tracker
 * @since 3.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Web_Push_Sender {

    /**
     * VAPID configuration option names
     */
    const VAPID_PUBLIC_KEY_OPTION = 'pit_vapid_public_key';
    const VAPID_PRIVATE_KEY_OPTION = 'pit_vapid_private_key';

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

        $endpoint = $subscription['endpoint'];
        $p256dh = $subscription['p256dh'];
        $auth = $subscription['auth'];

        // Prepare the payload
        $json_payload = json_encode($payload);

        // Create JWT for VAPID
        $vapid_headers = self::create_vapid_headers($endpoint, $vapid_public_key, $vapid_private_key);

        if (!$vapid_headers) {
            return [
                'success' => false,
                'error'   => 'Failed to create VAPID headers',
            ];
        }

        // Encrypt the payload
        $encrypted = self::encrypt_payload($json_payload, $p256dh, $auth);

        if (!$encrypted) {
            return [
                'success' => false,
                'error'   => 'Failed to encrypt payload',
            ];
        }

        // Send the request
        $response = wp_remote_post($endpoint, [
            'headers' => array_merge($vapid_headers, [
                'Content-Type'     => 'application/octet-stream',
                'Content-Encoding' => 'aes128gcm',
                'Content-Length'   => strlen($encrypted['ciphertext']),
                'TTL'              => 86400, // 24 hours
            ]),
            'body'    => $encrypted['ciphertext'],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error'   => $response->get_error_message(),
            ];
        }

        $response_code = wp_remote_retrieve_response_code($response);

        // 201 = Created (successful)
        // 410 = Gone (subscription expired - should remove)
        // 404 = Not Found (subscription invalid - should remove)
        if ($response_code === 201) {
            return ['success' => true];
        }

        if ($response_code === 410 || $response_code === 404) {
            // Remove invalid subscription
            PIT_Push_Subscriptions_Schema::delete_subscription($subscription['id']);
            return [
                'success' => false,
                'error'   => 'Subscription expired or invalid',
                'code'    => $response_code,
            ];
        }

        return [
            'success' => false,
            'error'   => 'Push failed with code ' . $response_code,
            'code'    => $response_code,
        ];
    }

    /**
     * Create VAPID authorization headers
     *
     * @param string $endpoint    Push endpoint URL
     * @param string $public_key  VAPID public key (base64url)
     * @param string $private_key VAPID private key (base64url)
     * @return array|false Headers or false on failure
     */
    private static function create_vapid_headers($endpoint, $public_key, $private_key) {
        $parsed_endpoint = parse_url($endpoint);
        $audience = $parsed_endpoint['scheme'] . '://' . $parsed_endpoint['host'];

        $expiration = time() + 86400; // 24 hours

        $header = [
            'typ' => 'JWT',
            'alg' => 'ES256',
        ];

        $payload = [
            'aud' => $audience,
            'exp' => $expiration,
            'sub' => 'mailto:' . get_option('admin_email', 'notifications@guestify.ai'),
        ];

        $header_b64 = self::base64url_encode(json_encode($header));
        $payload_b64 = self::base64url_encode(json_encode($payload));

        $signing_input = $header_b64 . '.' . $payload_b64;

        // Sign with ECDSA
        $signature = self::sign_ecdsa($signing_input, $private_key);
        if (!$signature) {
            return false;
        }

        $jwt = $signing_input . '.' . self::base64url_encode($signature);

        return [
            'Authorization' => 'vapid t=' . $jwt . ', k=' . $public_key,
        ];
    }

    /**
     * Sign data with ECDSA using the private key
     *
     * @param string $data        Data to sign
     * @param string $private_key Base64url encoded private key
     * @return string|false Signature or false on failure
     */
    private static function sign_ecdsa($data, $private_key) {
        if (!function_exists('openssl_sign')) {
            return false;
        }

        // Decode the private key
        $raw_key = self::base64url_decode($private_key);

        // Create a proper EC private key PEM
        // The key is 32 bytes for P-256 curve
        $pem = self::create_ec_private_key_pem($raw_key);
        if (!$pem) {
            return false;
        }

        $key = openssl_pkey_get_private($pem);
        if (!$key) {
            return false;
        }

        $signature = '';
        $result = openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);

        if (!$result) {
            return false;
        }

        // Convert DER signature to raw format (64 bytes)
        return self::der_to_raw($signature);
    }

    /**
     * Encrypt the notification payload using Web Push encryption
     *
     * @param string $payload Payload to encrypt
     * @param string $p256dh  Client's P-256 public key (base64url)
     * @param string $auth    Client's auth secret (base64url)
     * @return array|false Encrypted data or false on failure
     */
    private static function encrypt_payload($payload, $p256dh, $auth) {
        if (!function_exists('openssl_pkey_new')) {
            error_log('PIT Web Push: OpenSSL extension not available');
            return false;
        }

        // Decode client keys
        $client_public = self::base64url_decode($p256dh);
        $client_auth = self::base64url_decode($auth);

        // Generate server key pair
        $server_key = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if (!$server_key) {
            error_log('PIT Web Push: Failed to generate EC key pair');
            return false;
        }

        $server_details = openssl_pkey_get_details($server_key);
        $server_public = $server_details['ec']['x'] . $server_details['ec']['y'];
        $server_public = "\x04" . $server_public; // Uncompressed point format

        // WARNING: This is a PLACEHOLDER implementation!
        // Full Web Push encryption requires RFC 8291 compliance:
        // 1. Compute ECDH shared secret between server and client keys
        // 2. Derive encryption keys using HKDF with auth secret
        // 3. Encrypt payload with AES-128-GCM
        //
        // For production, install minishlink/web-push via Composer:
        //   composer require minishlink/web-push
        //
        // Push services will REJECT unencrypted payloads.
        error_log('PIT Web Push: WARNING - Using placeholder encryption. Install minishlink/web-push for production use.');

        // Placeholder return - actual encryption needed for production
        return [
            'ciphertext'    => $payload,
            'server_public' => self::base64url_encode($server_public),
        ];
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

    /**
     * Create EC private key PEM from raw bytes
     *
     * @param string $raw_key Raw 32-byte private key
     * @return string|false PEM formatted key
     */
    private static function create_ec_private_key_pem($raw_key) {
        if (strlen($raw_key) !== 32) {
            return false;
        }

        // SEC1 format for P-256 private key
        $asn1 = "\x30\x77" // SEQUENCE
              . "\x02\x01\x01" // INTEGER version = 1
              . "\x04\x20" . $raw_key // OCTET STRING private key
              . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // OID prime256v1

        return "-----BEGIN EC PRIVATE KEY-----\n"
             . chunk_split(base64_encode($asn1), 64, "\n")
             . "-----END EC PRIVATE KEY-----\n";
    }

    /**
     * Convert DER encoded signature to raw 64-byte format
     *
     * @param string $der DER encoded signature
     * @return string Raw signature
     */
    private static function der_to_raw($der) {
        // Skip SEQUENCE header
        $offset = 2;
        if (ord($der[1]) & 0x80) {
            $offset += (ord($der[1]) & 0x7f);
        }

        // Extract R
        $r_len = ord($der[$offset + 1]);
        $r = substr($der, $offset + 2, $r_len);

        // Extract S
        $offset = $offset + 2 + $r_len;
        $s_len = ord($der[$offset + 1]);
        $s = substr($der, $offset + 2, $s_len);

        // Pad/trim to 32 bytes each
        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

        return substr($r, -32) . substr($s, -32);
    }

    /**
     * Generate VAPID key pair
     *
     * Call this once during plugin setup to generate keys.
     *
     * @return array|false Keys array or false on failure
     */
    public static function generate_vapid_keys() {
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

        // Export private key (32 bytes)
        openssl_pkey_export($key, $pem);
        preg_match('/-----BEGIN EC PRIVATE KEY-----(.+?)-----END EC PRIVATE KEY-----/s', $pem, $matches);
        $der = base64_decode($matches[1]);

        // Extract private key from DER (last 32 bytes before the OID)
        $private_raw = substr($der, 7, 32);

        // Public key is x || y (64 bytes total)
        $public_raw = $details['ec']['x'] . $details['ec']['y'];

        return [
            'public_key'  => self::base64url_encode("\x04" . $public_raw),
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
}
