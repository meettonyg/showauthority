<?php
/**
 * Contact Data Integration
 *
 * Retrieves and displays contact information from the Podcast Intelligence Database.
 * Provides contact data for external email plugins.
 *
 * Priority order for contact lookup:
 * 1. Formidable field (direct entry)
 * 2. Podcast contacts table (via podcast_id)
 * 3. Primary contact from relationship table
 * 4. RSS feed data
 * 5. Clay enrichment
 * 6. Manual entry fallback
 *
 * NOTE: This plugin does NOT send emails. Contact data is provided via shortcode
 * and REST API for use with external email plugins.
 *
 * @package Podcast_Influence_Tracker
 * @subpackage Podcast_Intelligence
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Email_Integration {

    /**
     * @var PIT_Email_Integration Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Register shortcode for contact data display
        add_shortcode('guestify_contact', [$this, 'render_contact_interface']);

        // Legacy shortcode support
        add_shortcode('guestify_email', [$this, 'render_contact_interface']);

        // AJAX handlers
        add_action('wp_ajax_pit_get_contact_info', [$this, 'ajax_get_contact_info']);
        add_action('wp_ajax_pit_save_manual_contact', [$this, 'ajax_save_manual_contact']);
    }

    /**
     * Get contact information from all sources
     *
     * Priority order:
     * 1. Formidable field (direct entry)
     * 2. Podcast contacts table (via podcast_id)
     * 3. Primary contact from relationship table
     * 4. RSS feed data
     * 5. Clay enrichment
     * 6. Manual entry
     *
     * @param int $entry_id Formidable entry ID
     * @return array Contact information
     */
    public function get_contact_from_all_sources($entry_id) {
        $contact_info = [
            'email' => null,
            'name' => null,
            'podcast_name' => null,
            'source' => null,
            'confidence' => 0,
            'additional_info' => [],
        ];

        // 1. Check Formidable field (direct entry)
        $formidable_email = $this->get_formidable_field($entry_id, 'host_email');
        $formidable_name = $this->get_formidable_field($entry_id, 'host_name');
        $formidable_podcast = $this->get_formidable_field($entry_id, 'podcast_name');

        if (!empty($formidable_email)) {
            $contact_info['email'] = $formidable_email;
            $contact_info['name'] = $formidable_name ?: 'Host';
            $contact_info['podcast_name'] = $formidable_podcast;
            $contact_info['source'] = 'formidable_direct';
            $contact_info['confidence'] = 100;
            return $contact_info;
        }

        // 2. Check podcast contacts table (via podcast_id)
        $podcast = PIT_Database::get_entry_podcast($entry_id);
        if ($podcast) {
            $contact_info['podcast_name'] = $podcast->title;

            // 3. Check primary contact from relationship table
            $primary_contact = PIT_Database::get_primary_contact($podcast->id);
            if ($primary_contact && !empty($primary_contact->email)) {
                $contact_info['email'] = $primary_contact->email;
                $contact_info['name'] = $primary_contact->full_name;
                $contact_info['source'] = 'podcast_database';
                $contact_info['confidence'] = 90;
                $contact_info['additional_info'] = [
                    'role' => $primary_contact->role,
                    'linkedin' => $primary_contact->linkedin_url,
                    'twitter' => $primary_contact->twitter_url,
                    'phone' => $primary_contact->phone,
                    'clay_enriched' => (bool) $primary_contact->clay_enriched,
                ];
                return $contact_info;
            }

            // 4. Check any contacts for this podcast
            $contacts = PIT_Database::get_podcast_contacts($podcast->id, 'host');
            if (!empty($contacts)) {
                foreach ($contacts as $contact) {
                    if (!empty($contact->email)) {
                        $contact_info['email'] = $contact->email;
                        $contact_info['name'] = $contact->full_name;
                        $contact_info['source'] = 'podcast_contact_secondary';
                        $contact_info['confidence'] = 80;
                        $contact_info['additional_info'] = [
                            'role' => $contact->role,
                            'is_primary' => (bool) $contact->is_primary,
                        ];
                        return $contact_info;
                    }
                }
            }
        }

        // 5. Fallback to Formidable name if we have podcast but no contact
        if ($formidable_name) {
            $contact_info['name'] = $formidable_name;
            $contact_info['source'] = 'formidable_partial';
            $contact_info['confidence'] = 30;
        }

        // 6. Suggest Clay enrichment if we have a name but no email
        if ($formidable_name && !$contact_info['email']) {
            $contact_info['suggest_enrichment'] = true;
            $contact_info['enrichment_data'] = [
                'name' => $formidable_name,
                'company' => $formidable_podcast,
            ];
        }

        return $contact_info;
    }

    /**
     * Get Formidable field value
     *
     * @param int $entry_id
     * @param string $field_key
     * @return string|null
     */
    private function get_formidable_field($entry_id, $field_key) {
        if (!class_exists('FrmProEntriesController')) {
            return null;
        }

        $value = FrmProEntriesController::get_field_value_shortcode([
            'field_key' => $field_key,
            'entry' => $entry_id
        ]);

        return $value ?: null;
    }

    /**
     * Render contact data interface shortcode
     *
     * Usage: [guestify_contact entry_id="123"]
     * Legacy: [guestify_email entry_id="123"]
     *
     * @param array $atts
     * @return string
     */
    public function render_contact_interface($atts) {
        $atts = shortcode_atts([
            'entry_id' => 0,
        ], $atts);

        $entry_id = intval($atts['entry_id']);
        if (!$entry_id) {
            return '<p>Error: No entry ID provided.</p>';
        }

        // Get contact info
        $contact = $this->get_contact_from_all_sources($entry_id);

        ob_start();
        ?>
        <div id="pit-contact-interface-<?php echo esc_attr($entry_id); ?>" class="pit-contact-interface">
            <div class="pit-contact-info">
                <h3>Contact Information</h3>

                <?php if ($contact['email']): ?>
                    <div class="pit-contact-found">
                        <p><strong>Email:</strong>
                            <span class="pit-contact-email"><?php echo esc_html($contact['email']); ?></span>
                            <button class="button button-small pit-copy-email" data-email="<?php echo esc_attr($contact['email']); ?>" title="Copy email">
                                Copy
                            </button>
                        </p>
                        <p><strong>Name:</strong> <?php echo esc_html($contact['name']); ?></p>
                        <?php if ($contact['podcast_name']): ?>
                            <p><strong>Podcast:</strong> <?php echo esc_html($contact['podcast_name']); ?></p>
                        <?php endif; ?>
                        <p class="pit-source">
                            <small>
                                <strong>Source:</strong> <?php echo esc_html($contact['source']); ?>
                                (<?php echo esc_html($contact['confidence']); ?>% confidence)
                            </small>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="pit-contact-not-found">
                        <p><strong>No email found for this entry.</strong></p>

                        <?php if ($contact['suggest_enrichment']): ?>
                            <div class="pit-enrichment-suggestion">
                                <p>We found the host name: <strong><?php echo esc_html($contact['name']); ?></strong></p>
                                <p>You can enrich this contact with Clay to find their email.</p>
                            </div>
                        <?php endif; ?>

                        <div class="pit-manual-entry">
                            <h4>Add Contact Manually</h4>
                            <form class="pit-manual-contact-form" data-entry-id="<?php echo esc_attr($entry_id); ?>">
                                <p>
                                    <label>Host Name:</label><br>
                                    <input type="text" name="host_name" value="<?php echo esc_attr($contact['name'] ?: ''); ?>" required>
                                </p>
                                <p>
                                    <label>Host Email:</label><br>
                                    <input type="email" name="host_email" required>
                                </p>
                                <p>
                                    <button type="submit" class="button">Save Contact</button>
                                </p>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($contact['additional_info'])): ?>
                <div class="pit-additional-info">
                    <h4>Additional Information</h4>
                    <ul>
                        <?php foreach ($contact['additional_info'] as $key => $value): ?>
                            <?php if ($value): ?>
                                <li><strong><?php echo esc_html(ucwords(str_replace('_', ' ', $key))); ?>:</strong>
                                    <?php
                                    if (filter_var($value, FILTER_VALIDATE_URL)) {
                                        echo '<a href="' . esc_url($value) . '" target="_blank">' . esc_html($value) . '</a>';
                                    } else {
                                        echo esc_html($value);
                                    }
                                    ?>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>

        <style>
            .pit-contact-interface {
                max-width: 600px;
                margin: 20px 0;
                padding: 20px;
                background: #f9f9f9;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
            .pit-contact-found {
                background: #e7f7e7;
                padding: 15px;
                margin-bottom: 20px;
                border-left: 4px solid #4caf50;
            }
            .pit-contact-not-found {
                background: #fff3cd;
                padding: 15px;
                margin-bottom: 20px;
                border-left: 4px solid #ffc107;
            }
            .pit-contact-email {
                font-family: monospace;
                background: #fff;
                padding: 2px 6px;
                border-radius: 3px;
            }
            .pit-copy-email {
                margin-left: 10px;
                font-size: 11px;
            }
            .pit-manual-entry input {
                width: 100%;
                max-width: 400px;
            }
            .pit-source {
                color: #666;
                font-style: italic;
                margin-top: 10px;
            }
            .pit-additional-info {
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid #ddd;
            }
            .pit-enrichment-suggestion {
                margin: 15px 0;
                padding: 10px;
                background: #e3f2fd;
                border-left: 3px solid #2196f3;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Copy email to clipboard
            $('.pit-copy-email').on('click', function(e) {
                e.preventDefault();
                var email = $(this).data('email');
                var button = $(this);

                // Create temporary input to copy
                var temp = $('<input>');
                $('body').append(temp);
                temp.val(email).select();
                document.execCommand('copy');
                temp.remove();

                // Visual feedback
                button.text('Copied!').prop('disabled', true);
                setTimeout(function() {
                    button.text('Copy').prop('disabled', false);
                }, 2000);
            });

            // Handle manual contact save
            $('.pit-manual-contact-form').on('submit', function(e) {
                e.preventDefault();

                var form = $(this);
                var entryId = form.data('entry-id');
                var button = form.find('button[type="submit"]');
                var originalText = button.text();

                button.prop('disabled', true).text('Saving...');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'pit_save_manual_contact',
                        entry_id: entryId,
                        host_name: form.find('[name="host_name"]').val(),
                        host_email: form.find('[name="host_email"]').val(),
                        nonce: '<?php echo wp_create_nonce('pit_save_contact'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Contact saved successfully! Refreshing...');
                            location.reload();
                        } else {
                            alert('Error: ' + (response.data || 'Unknown error'));
                            button.prop('disabled', false).text(originalText);
                        }
                    },
                    error: function() {
                        alert('Error saving contact. Please try again.');
                        button.prop('disabled', false).text(originalText);
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: Get contact info
     */
    public function ajax_get_contact_info() {
        check_ajax_referer('pit_get_contact', 'nonce');

        $entry_id = intval($_POST['entry_id'] ?? 0);
        if (!$entry_id) {
            wp_send_json_error('Invalid entry ID');
        }

        $contact = $this->get_contact_from_all_sources($entry_id);
        wp_send_json_success($contact);
    }

    /**
     * AJAX: Save manual contact
     */
    public function ajax_save_manual_contact() {
        check_ajax_referer('pit_save_contact', 'nonce');

        $entry_id = intval($_POST['entry_id'] ?? 0);
        $host_name = sanitize_text_field($_POST['host_name'] ?? '');
        $host_email = sanitize_email($_POST['host_email'] ?? '');

        if (!$entry_id || !$host_name || !$host_email) {
            wp_send_json_error('Missing required fields');
        }

        // Get podcast for this entry
        $podcast = PIT_Database::get_entry_podcast($entry_id);
        if (!$podcast) {
            wp_send_json_error('No podcast found for this entry');
        }

        // Create or update contact
        $manager = PIT_Podcast_Intelligence_Manager::get_instance();

        $name_parts = explode(' ', trim($host_name), 2);
        $contact_data = [
            'full_name' => $host_name,
            'first_name' => $name_parts[0] ?? '',
            'last_name' => $name_parts[1] ?? '',
            'email' => $host_email,
            'role' => 'host',
            'enrichment_source' => 'manual',
            'data_quality_score' => 50,
        ];

        $contact_id = $manager->create_or_find_contact($contact_data);

        // Link to podcast
        $manager->link_podcast_contact($podcast->id, $contact_id, 'host', true);

        // Update entry bridge
        PIT_Database::link_entry_to_podcast($entry_id, $podcast->id, [
            'primary_contact_id' => $contact_id,
        ]);

        wp_send_json_success('Contact saved successfully');
    }
}
