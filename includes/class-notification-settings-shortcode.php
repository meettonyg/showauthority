<?php
/**
 * Notification Settings Shortcode
 *
 * Renders notification settings UI via shortcode.
 * Usage: [guestify_notification_settings]
 *
 * @package Podcast_Influence_Tracker
 * @since 4.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Notification_Settings_Shortcode {

    /**
     * Initialize shortcode
     */
    public static function init() {
        add_shortcode('guestify_notification_settings', [__CLASS__, 'render']);
    }

    /**
     * Render the notification settings
     *
     * @return string HTML output
     */
    public static function render($atts = []) {
        // Require login
        if (!is_user_logged_in()) {
            return '<div class="pit-notification-settings__message pit-notification-settings__message--error">Please <a href="' . esc_url(wp_login_url(get_permalink())) . '">log in</a> to view notification settings.</div>';
        }

        ob_start();
        ?>
        <div class="pit-notification-settings">
            <div class="pit-notification-settings__container">
                <header class="pit-notification-settings__header">
                    <h1>Notification Settings</h1>
                    <p>Manage how and when you receive notifications.</p>
                </header>

                <div class="pit-notification-settings__content" id="notificationSettingsApp">
                    <div class="pit-notification-settings__loading">
                        <span>Loading settings...</span>
                    </div>
                </div>
            </div>
        </div>

        <style>
            .pit-notification-settings {
                max-width: 800px;
                margin: 0 auto;
                padding: 2rem;
            }
            .pit-notification-settings__container {
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                padding: 2rem;
            }
            .pit-notification-settings__header {
                margin-bottom: 2rem;
                padding-bottom: 1rem;
                border-bottom: 1px solid #e5e7eb;
            }
            .pit-notification-settings__header h1 {
                margin: 0 0 0.5rem;
                font-size: 1.5rem;
                font-weight: 600;
                color: #1f2937;
            }
            .pit-notification-settings__header p {
                margin: 0;
                color: #6b7280;
            }
            .pit-notification-settings__loading {
                text-align: center;
                padding: 2rem;
                color: #6b7280;
            }
            .pit-notification-settings__section {
                margin-bottom: 2rem;
            }
            .pit-notification-settings__section-title {
                font-size: 1.125rem;
                font-weight: 600;
                color: #1f2937;
                margin-bottom: 1rem;
            }
            .pit-notification-settings__option {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 1rem;
                background: #f9fafb;
                border-radius: 6px;
                margin-bottom: 0.75rem;
            }
            .pit-notification-settings__option-info h4 {
                margin: 0 0 0.25rem;
                font-weight: 500;
                color: #1f2937;
            }
            .pit-notification-settings__option-info p {
                margin: 0;
                font-size: 0.875rem;
                color: #6b7280;
            }
            .pit-notification-settings__toggle {
                position: relative;
                width: 44px;
                height: 24px;
            }
            .pit-notification-settings__toggle input {
                opacity: 0;
                width: 0;
                height: 0;
            }
            .pit-notification-settings__toggle-slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #d1d5db;
                transition: 0.3s;
                border-radius: 24px;
            }
            .pit-notification-settings__toggle-slider:before {
                position: absolute;
                content: "";
                height: 18px;
                width: 18px;
                left: 3px;
                bottom: 3px;
                background-color: white;
                transition: 0.3s;
                border-radius: 50%;
            }
            .pit-notification-settings__toggle input:checked + .pit-notification-settings__toggle-slider {
                background-color: #14b8a6;
            }
            .pit-notification-settings__toggle input:checked + .pit-notification-settings__toggle-slider:before {
                transform: translateX(20px);
            }
            .pit-notification-settings__save {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                padding: 0.75rem 1.5rem;
                background: #14b8a6;
                color: white;
                border: none;
                border-radius: 6px;
                font-weight: 500;
                cursor: pointer;
                transition: background 0.2s;
            }
            .pit-notification-settings__save:hover {
                background: #0d9488;
            }
            .pit-notification-settings__save:disabled {
                background: #9ca3af;
                cursor: not-allowed;
            }
            .pit-notification-settings__message {
                padding: 1rem;
                border-radius: 6px;
                margin-bottom: 1rem;
            }
            .pit-notification-settings__message--success {
                background: #d1fae5;
                color: #065f46;
            }
            .pit-notification-settings__message--error {
                background: #fee2e2;
                color: #991b1b;
            }
        </style>

        <script>
        (function() {
            const app = document.getElementById('notificationSettingsApp');
            const nonce = window.guestifyAppNav?.nonce || '';
            const restUrl = window.guestifyAppNav?.restUrl || '/wp-json/guestify/v1/';

            let settings = {};
            let saving = false;

            async function loadSettings() {
                try {
                    const response = await fetch(restUrl + 'notifications/settings', {
                        credentials: 'same-origin',
                        headers: {
                            'X-WP-Nonce': nonce,
                            'Content-Type': 'application/json',
                        }
                    });

                    if (!response.ok) throw new Error('Failed to load settings');

                    settings = await response.json();
                    render();
                } catch (error) {
                    app.innerHTML = '<div class="pit-notification-settings__message pit-notification-settings__message--error">Failed to load settings. Please refresh the page.</div>';
                }
            }

            async function saveSettings() {
                if (saving) return;
                saving = true;

                const saveBtn = document.getElementById('saveSettingsBtn');
                if (saveBtn) saveBtn.disabled = true;

                try {
                    const response = await fetch(restUrl + 'notifications/settings', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'X-WP-Nonce': nonce,
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(settings)
                    });

                    if (!response.ok) throw new Error('Failed to save settings');

                    showMessage('Settings saved successfully!', 'success');
                } catch (error) {
                    showMessage('Failed to save settings. Please try again.', 'error');
                } finally {
                    saving = false;
                    if (saveBtn) saveBtn.disabled = false;
                }
            }

            function showMessage(text, type) {
                const msgDiv = document.getElementById('settingsMessage');
                if (msgDiv) {
                    msgDiv.className = 'pit-notification-settings__message pit-notification-settings__message--' + type;
                    msgDiv.textContent = text;
                    msgDiv.style.display = 'block';
                    setTimeout(() => { msgDiv.style.display = 'none'; }, 3000);
                }
            }

            function render() {
                const settingsConfig = [
                    {
                        key: 'email_enabled',
                        title: 'Email Notifications',
                        description: 'Receive notifications via email'
                    },
                    {
                        key: 'task_reminders',
                        title: 'Task Reminders',
                        description: 'Get reminded about upcoming and overdue tasks'
                    },
                    {
                        key: 'event_reminders',
                        title: 'Event Reminders',
                        description: 'Get reminded about upcoming calendar events'
                    },
                    {
                        key: 'interview_alerts',
                        title: 'Interview Alerts',
                        description: 'Get notified about upcoming interviews'
                    },
                    {
                        key: 'system_updates',
                        title: 'System Updates',
                        description: 'Receive updates about new features and changes'
                    }
                ];

                let html = '<div id="settingsMessage" class="pit-notification-settings__message" style="display:none;"></div>';

                html += '<div class="pit-notification-settings__section">';
                html += '<h3 class="pit-notification-settings__section-title">Notification Preferences</h3>';

                settingsConfig.forEach(config => {
                    const isChecked = settings[config.key] !== false;
                    html += `
                        <div class="pit-notification-settings__option">
                            <div class="pit-notification-settings__option-info">
                                <h4>${config.title}</h4>
                                <p>${config.description}</p>
                            </div>
                            <label class="pit-notification-settings__toggle">
                                <input type="checkbox" data-key="${config.key}" ${isChecked ? 'checked' : ''}>
                                <span class="pit-notification-settings__toggle-slider"></span>
                            </label>
                        </div>
                    `;
                });

                html += '</div>';
                html += '<button id="saveSettingsBtn" class="pit-notification-settings__save">Save Settings</button>';

                app.innerHTML = html;

                app.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                    checkbox.addEventListener('change', function() {
                        settings[this.dataset.key] = this.checked;
                    });
                });

                document.getElementById('saveSettingsBtn').addEventListener('click', saveSettings);
            }

            loadSettings();
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
