<?php
/**
 * Admin Notifications Test Page
 *
 * Provides an admin interface to test and debug the notifications system,
 * including VAPID key management, push subscriptions, and test notifications.
 *
 * @package Podcast_Influence_Tracker
 * @since 3.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Admin_Notifications_Test {

    /**
     * Initialize admin page
     */
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu_page']);
        add_action('admin_init', [__CLASS__, 'handle_actions']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
    }

    /**
     * Add submenu page
     */
    public static function add_menu_page() {
        add_submenu_page(
            'podcast-influence',
            __('Notifications Test', 'podcast-influence-tracker'),
            __('Notifications Test', 'podcast-influence-tracker'),
            'manage_options',
            'podcast-influence-notifications-test',
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Enqueue scripts for admin page
     */
    public static function enqueue_scripts($hook) {
        if ($hook !== 'podcast-influence_page_podcast-influence-notifications-test') {
            return;
        }

        // Enqueue shared push notifications module
        wp_enqueue_script(
            'pit-push-notifications',
            plugins_url('assets/js/shared/push-notifications.js', dirname(dirname(__FILE__))),
            [],
            '3.6.0',
            true
        );

        // Inline script for admin page
        wp_add_inline_script('pit-push-notifications', self::get_inline_script(), 'after');
    }

    /**
     * Get inline JavaScript for the admin page
     */
    private static function get_inline_script() {
        $vapid_public_key = get_option(PIT_Web_Push_Sender::VAPID_PUBLIC_KEY_OPTION, '');
        $rest_url = rest_url('guestify/v1/');
        $plugin_url = plugins_url('/', dirname(dirname(__FILE__)));
        $nonce = wp_create_nonce('wp_rest');

        return "
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize PushNotifications
            if (typeof PushNotifications !== 'undefined') {
                PushNotifications.init({
                    vapidPublicKey: " . json_encode($vapid_public_key) . ",
                    nonce: " . json_encode($nonce) . ",
                    restUrl: " . json_encode($rest_url) . ",
                    pluginUrl: " . json_encode($plugin_url) . ",
                    serviceWorkerPath: " . json_encode($plugin_url . 'assets/js/sw-push.js') . "
                });
            }

            // Status update function
            function updatePushStatus() {
                var statusEl = document.getElementById('push-status');
                var subscribeBtn = document.getElementById('subscribe-btn');
                var unsubscribeBtn = document.getElementById('unsubscribe-btn');

                if (!PushNotifications.isSupported()) {
                    statusEl.innerHTML = '<span style=\"color: red;\">❌ Push notifications not supported in this browser</span>';
                    subscribeBtn.disabled = true;
                    return;
                }

                var permission = PushNotifications.getPermission();
                PushNotifications.isSubscribed().then(function(subscribed) {
                    if (subscribed) {
                        statusEl.innerHTML = '<span style=\"color: green;\">✓ Subscribed to push notifications</span>';
                        subscribeBtn.style.display = 'none';
                        unsubscribeBtn.style.display = 'inline-block';
                    } else if (permission === 'denied') {
                        statusEl.innerHTML = '<span style=\"color: red;\">❌ Notification permission denied</span>';
                        subscribeBtn.disabled = true;
                    } else {
                        statusEl.innerHTML = '<span style=\"color: orange;\">○ Not subscribed</span>';
                        subscribeBtn.style.display = 'inline-block';
                        unsubscribeBtn.style.display = 'none';
                    }
                });
            }

            // Subscribe button
            var subscribeBtn = document.getElementById('subscribe-btn');
            if (subscribeBtn) {
                subscribeBtn.addEventListener('click', function() {
                    subscribeBtn.disabled = true;
                    subscribeBtn.textContent = 'Subscribing...';

                    PushNotifications.registerServiceWorker()
                        .then(function() {
                            return PushNotifications.subscribe();
                        })
                        .then(function(result) {
                            alert('Successfully subscribed to push notifications!');
                            updatePushStatus();
                            location.reload();
                        })
                        .catch(function(error) {
                            alert('Failed to subscribe: ' + error.message);
                            subscribeBtn.disabled = false;
                            subscribeBtn.textContent = 'Subscribe to Push';
                        });
                });
            }

            // Unsubscribe button
            var unsubscribeBtn = document.getElementById('unsubscribe-btn');
            if (unsubscribeBtn) {
                unsubscribeBtn.addEventListener('click', function() {
                    unsubscribeBtn.disabled = true;
                    unsubscribeBtn.textContent = 'Unsubscribing...';

                    PushNotifications.unsubscribe()
                        .then(function() {
                            alert('Successfully unsubscribed from push notifications!');
                            updatePushStatus();
                            location.reload();
                        })
                        .catch(function(error) {
                            alert('Failed to unsubscribe: ' + error.message);
                            unsubscribeBtn.disabled = false;
                            unsubscribeBtn.textContent = 'Unsubscribe';
                        });
                });
            }

            // Initial status update
            updatePushStatus();
        });
        ";
    }

    /**
     * Handle form actions
     */
    public static function handle_actions() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!isset($_POST['pit_notifications_action'])) {
            return;
        }

        check_admin_referer('pit_notifications_test');

        $action = sanitize_text_field($_POST['pit_notifications_action']);

        switch ($action) {
            case 'generate_vapid':
                self::handle_generate_vapid();
                break;

            case 'send_test':
                self::handle_send_test();
                break;

            case 'process_notifications':
                self::handle_process_notifications();
                break;

            case 'create_table':
                self::handle_create_table();
                break;
        }
    }

    /**
     * Handle VAPID key generation
     */
    private static function handle_generate_vapid() {
        $keys = PIT_Web_Push_Sender::generate_vapid_keys();

        if ($keys) {
            PIT_Web_Push_Sender::save_vapid_keys($keys['public_key'], $keys['private_key']);
            add_settings_error(
                'pit_notifications',
                'vapid_generated',
                __('VAPID keys generated successfully!', 'podcast-influence-tracker'),
                'success'
            );
        } else {
            add_settings_error(
                'pit_notifications',
                'vapid_failed',
                __('Failed to generate VAPID keys. Make sure OpenSSL is available.', 'podcast-influence-tracker'),
                'error'
            );
        }
    }

    /**
     * Handle sending test notification
     */
    private static function handle_send_test() {
        $user_id = get_current_user_id();
        $title = isset($_POST['test_title']) ? sanitize_text_field($_POST['test_title']) : 'Test Notification';
        $message = isset($_POST['test_message']) ? sanitize_text_field($_POST['test_message']) : 'This is a test push notification.';

        $payload = [
            'title'   => $title,
            'body'    => $message,
            'url'     => admin_url('admin.php?page=podcast-influence-notifications-test'),
            'tag'     => 'test-' . time(),
        ];

        $result = PIT_Web_Push_Sender::send_to_user($user_id, $payload);

        if ($result['sent'] > 0) {
            add_settings_error(
                'pit_notifications',
                'test_sent',
                sprintf(
                    __('Test notification sent to %d device(s). Check your browser!', 'podcast-influence-tracker'),
                    $result['sent']
                ),
                'success'
            );
        } elseif ($result['failed'] > 0) {
            add_settings_error(
                'pit_notifications',
                'test_failed',
                sprintf(
                    __('Failed to send to %d device(s). See error log for details.', 'podcast-influence-tracker'),
                    $result['failed']
                ),
                'error'
            );
        } else {
            add_settings_error(
                'pit_notifications',
                'no_subscriptions',
                __('No push subscriptions found for your account. Subscribe first!', 'podcast-influence-tracker'),
                'warning'
            );
        }
    }

    /**
     * Handle processing notifications
     */
    private static function handle_process_notifications() {
        $processor = new PIT_Notification_Processor();
        $processor->process();

        add_settings_error(
            'pit_notifications',
            'processed',
            __('Notification processing completed. Check the logs for details.', 'podcast-influence-tracker'),
            'success'
        );
    }

    /**
     * Handle creating push subscriptions table
     */
    private static function handle_create_table() {
        $result = PIT_Push_Subscriptions_Schema::create_table();

        if ($result) {
            add_settings_error(
                'pit_notifications',
                'table_created',
                __('Push subscriptions table created successfully!', 'podcast-influence-tracker'),
                'success'
            );
        } else {
            add_settings_error(
                'pit_notifications',
                'table_failed',
                __('Failed to create table. Check database permissions.', 'podcast-influence-tracker'),
                'error'
            );
        }
    }

    /**
     * Render the admin page
     */
    public static function render_page() {
        $vapid_configured = PIT_Web_Push_Sender::is_configured();
        $vapid_public_key = get_option(PIT_Web_Push_Sender::VAPID_PUBLIC_KEY_OPTION, '');
        $table_exists = PIT_Push_Subscriptions_Schema::table_exists();
        $user_subscriptions = $table_exists ? PIT_Push_Subscriptions_Schema::get_user_subscriptions(get_current_user_id()) : [];

        ?>
        <div class="wrap">
            <h1><?php _e('Notifications Test', 'podcast-influence-tracker'); ?></h1>

            <?php settings_errors('pit_notifications'); ?>

            <!-- System Status -->
            <div class="card" style="max-width: 800px; margin-bottom: 20px;">
                <h2><?php _e('System Status', 'podcast-influence-tracker'); ?></h2>
                <table class="widefat" style="margin-top: 10px;">
                    <tbody>
                        <tr>
                            <td><strong><?php _e('Push Subscriptions Table', 'podcast-influence-tracker'); ?></strong></td>
                            <td>
                                <?php if ($table_exists): ?>
                                    <span style="color: green;">✓ <?php _e('Created', 'podcast-influence-tracker'); ?></span>
                                <?php else: ?>
                                    <span style="color: red;">✗ <?php _e('Not created', 'podcast-influence-tracker'); ?></span>
                                    <form method="post" style="display: inline; margin-left: 10px;">
                                        <?php wp_nonce_field('pit_notifications_test'); ?>
                                        <input type="hidden" name="pit_notifications_action" value="create_table">
                                        <button type="submit" class="button button-small"><?php _e('Create Table', 'podcast-influence-tracker'); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('VAPID Keys', 'podcast-influence-tracker'); ?></strong></td>
                            <td>
                                <?php if ($vapid_configured): ?>
                                    <span style="color: green;">✓ <?php _e('Configured', 'podcast-influence-tracker'); ?></span>
                                <?php else: ?>
                                    <span style="color: red;">✗ <?php _e('Not configured', 'podcast-influence-tracker'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('OpenSSL Extension', 'podcast-influence-tracker'); ?></strong></td>
                            <td>
                                <?php if (function_exists('openssl_pkey_new')): ?>
                                    <span style="color: green;">✓ <?php _e('Available', 'podcast-influence-tracker'); ?></span>
                                <?php else: ?>
                                    <span style="color: red;">✗ <?php _e('Not available', 'podcast-influence-tracker'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Your Push Subscriptions', 'podcast-influence-tracker'); ?></strong></td>
                            <td><?php echo count($user_subscriptions); ?> <?php _e('device(s)', 'podcast-influence-tracker'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Browser Push Status', 'podcast-influence-tracker'); ?></strong></td>
                            <td id="push-status"><?php _e('Checking...', 'podcast-influence-tracker'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- VAPID Keys Section -->
            <div class="card" style="max-width: 800px; margin-bottom: 20px;">
                <h2><?php _e('VAPID Keys', 'podcast-influence-tracker'); ?></h2>

                <?php if ($vapid_configured): ?>
                    <p><?php _e('VAPID keys are configured. Your public key:', 'podcast-influence-tracker'); ?></p>
                    <code style="display: block; padding: 10px; background: #f0f0f0; word-break: break-all; margin-bottom: 10px;">
                        <?php echo esc_html($vapid_public_key); ?>
                    </code>
                    <p class="description">
                        <?php _e('⚠️ Regenerating keys will invalidate all existing push subscriptions.', 'podcast-influence-tracker'); ?>
                    </p>
                <?php else: ?>
                    <p><?php _e('VAPID keys are required for push notifications. Generate them now:', 'podcast-influence-tracker'); ?></p>
                <?php endif; ?>

                <form method="post" style="margin-top: 10px;">
                    <?php wp_nonce_field('pit_notifications_test'); ?>
                    <input type="hidden" name="pit_notifications_action" value="generate_vapid">
                    <button type="submit" class="button <?php echo $vapid_configured ? '' : 'button-primary'; ?>"
                            onclick="return <?php echo $vapid_configured ? "confirm('This will invalidate all existing subscriptions. Continue?')" : 'true'; ?>">
                        <?php echo $vapid_configured ? __('Regenerate VAPID Keys', 'podcast-influence-tracker') : __('Generate VAPID Keys', 'podcast-influence-tracker'); ?>
                    </button>
                </form>
            </div>

            <!-- Push Subscription Section -->
            <?php if ($vapid_configured && $table_exists): ?>
            <div class="card" style="max-width: 800px; margin-bottom: 20px;">
                <h2><?php _e('Subscribe This Browser', 'podcast-influence-tracker'); ?></h2>
                <p><?php _e('Subscribe this browser to receive push notifications:', 'podcast-influence-tracker'); ?></p>

                <button type="button" id="subscribe-btn" class="button button-primary">
                    <?php _e('Subscribe to Push', 'podcast-influence-tracker'); ?>
                </button>
                <button type="button" id="unsubscribe-btn" class="button" style="display: none;">
                    <?php _e('Unsubscribe', 'podcast-influence-tracker'); ?>
                </button>
            </div>
            <?php endif; ?>

            <!-- Test Notification Section -->
            <?php if ($vapid_configured && $table_exists && !empty($user_subscriptions)): ?>
            <div class="card" style="max-width: 800px; margin-bottom: 20px;">
                <h2><?php _e('Send Test Notification', 'podcast-influence-tracker'); ?></h2>
                <p><?php _e('Send a test push notification to your subscribed devices:', 'podcast-influence-tracker'); ?></p>

                <form method="post">
                    <?php wp_nonce_field('pit_notifications_test'); ?>
                    <input type="hidden" name="pit_notifications_action" value="send_test">

                    <table class="form-table">
                        <tr>
                            <th><label for="test_title"><?php _e('Title', 'podcast-influence-tracker'); ?></label></th>
                            <td>
                                <input type="text" name="test_title" id="test_title"
                                       value="Test Notification" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="test_message"><?php _e('Message', 'podcast-influence-tracker'); ?></label></th>
                            <td>
                                <textarea name="test_message" id="test_message" class="large-text" rows="3">This is a test push notification from Guestify.</textarea>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary">
                            <?php _e('Send Test Notification', 'podcast-influence-tracker'); ?>
                        </button>
                    </p>
                </form>

                <p class="description">
                    <strong><?php _e('Note:', 'podcast-influence-tracker'); ?></strong>
                    <?php _e('The notification may take a few seconds to arrive. If this tab is focused, the notification may not appear (browser behavior). Try minimizing the browser or switching tabs.', 'podcast-influence-tracker'); ?>
                </p>
            </div>
            <?php endif; ?>

            <!-- Your Subscriptions -->
            <?php if ($table_exists && !empty($user_subscriptions)): ?>
            <div class="card" style="max-width: 800px; margin-bottom: 20px;">
                <h2><?php _e('Your Push Subscriptions', 'podcast-influence-tracker'); ?></h2>

                <table class="widefat striped" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th><?php _e('Device', 'podcast-influence-tracker'); ?></th>
                            <th><?php _e('Created', 'podcast-influence-tracker'); ?></th>
                            <th><?php _e('Last Used', 'podcast-influence-tracker'); ?></th>
                            <th><?php _e('Status', 'podcast-influence-tracker'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($user_subscriptions as $sub): ?>
                        <tr>
                            <td><?php echo esc_html($sub['device_name'] ?: __('Unknown Device', 'podcast-influence-tracker')); ?></td>
                            <td><?php echo esc_html($sub['created_at']); ?></td>
                            <td><?php echo $sub['last_used_at'] ? esc_html($sub['last_used_at']) : '-'; ?></td>
                            <td>
                                <?php if ($sub['is_active']): ?>
                                    <span style="color: green;">✓ <?php _e('Active', 'podcast-influence-tracker'); ?></span>
                                    <?php if ($sub['failed_attempts'] > 0): ?>
                                        <span style="color: orange;">(<?php echo esc_html($sub['failed_attempts']); ?> <?php _e('failures', 'podcast-influence-tracker'); ?>)</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: red;">✗ <?php _e('Inactive', 'podcast-influence-tracker'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Process Notifications Section -->
            <div class="card" style="max-width: 800px; margin-bottom: 20px;">
                <h2><?php _e('Process Notifications', 'podcast-influence-tracker'); ?></h2>
                <p><?php _e('Manually trigger the notification processor (normally runs via cron):', 'podcast-influence-tracker'); ?></p>

                <form method="post">
                    <?php wp_nonce_field('pit_notifications_test'); ?>
                    <input type="hidden" name="pit_notifications_action" value="process_notifications">
                    <button type="submit" class="button">
                        <?php _e('Run Notification Processor', 'podcast-influence-tracker'); ?>
                    </button>
                </form>

                <p class="description" style="margin-top: 10px;">
                    <?php _e('This will process any pending notifications (task reminders, interview reminders, etc.) and send both email and push notifications.', 'podcast-influence-tracker'); ?>
                </p>
            </div>

            <!-- Encryption Warning -->
            <div class="card" style="max-width: 800px; margin-bottom: 20px; border-left: 4px solid #dba617;">
                <h2><?php _e('⚠️ Important: Production Encryption', 'podcast-influence-tracker'); ?></h2>
                <p>
                    <?php _e('The current push notification implementation uses a <strong>placeholder encryption</strong>. Push services (Chrome, Firefox, etc.) will <strong>reject</strong> unencrypted payloads.', 'podcast-influence-tracker'); ?>
                </p>
                <p>
                    <?php _e('For production use, you must install the web-push library:', 'podcast-influence-tracker'); ?>
                </p>
                <code style="display: block; padding: 10px; background: #f0f0f0; margin: 10px 0;">
                    composer require minishlink/web-push
                </code>
                <p>
                    <?php _e('Then update the PIT_Web_Push_Sender class to use proper RFC 8291 encryption.', 'podcast-influence-tracker'); ?>
                </p>
            </div>

        </div>
        <?php
    }
}
