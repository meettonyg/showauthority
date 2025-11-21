<?php
/**
 * Enhanced Email Integration
 *
 * Integrates with the Podcast Intelligence Database to provide contact information
 * for email sending. Implements the priority order:
 * 1. Formidable field (direct entry)
 * 2. Podcast contacts table (via podcast_id)
 * 3. Primary contact from relationship table
 * 4. RSS feed data
 * 5. Clay enrichment
 * 6. Manual entry fallback
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
        // Register shortcode for email interface
        add_shortcode('guestify_email', [$this, 'render_email_interface']);

        // AJAX handlers
        add_action('wp_ajax_pit_get_contact_info', [$this, 'ajax_get_contact_info']);
        add_action('wp_ajax_pit_send_email', [$this, 'ajax_send_email']);
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
     * Render email interface shortcode
     *
     * Usage: [guestify_email entry_id="123"]
     *
     * @param array $atts
     * @return string
     */
    public function render_email_interface($atts) {
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
        <div id="pit-email-interface-<?php echo esc_attr($entry_id); ?>" class="pit-email-interface">
            <div class="pit-contact-info">
                <h3>Contact Information</h3>

                <?php if ($contact['email']): ?>
                    <div class="pit-contact-found">
                        <p><strong>Email:</strong> <?php echo esc_html($contact['email']); ?></p>
                        <p><strong>Name:</strong> <?php echo esc_html($contact['name']); ?></p>
                        <?php if ($contact['podcast_name']): ?>
                            <p><strong>Podcast:</strong> <?php echo esc_html($contact['podcast_name']); ?></p>
                        <?php endif; ?>
                        <p class="pit-source">
                            <small>Source: <?php echo esc_html($contact['source']); ?>
                            (<?php echo esc_html($contact['confidence']); ?>% confidence)</small>
                        </p>
                    </div>

                    <div class="pit-email-form">
                        <h4>Send Email</h4>
                        <form class="pit-send-email-form" data-entry-id="<?php echo esc_attr($entry_id); ?>">
                            <p>
                                <label>To:</label><br>
                                <input type="email" name="to" value="<?php echo esc_attr($contact['email']); ?>" readonly>
                            </p>
                            <p>
                                <label>Subject:</label><br>
                                <input type="text" name="subject" value="Interview Request for <?php echo esc_attr($contact['podcast_name'] ?: 'Your Podcast'); ?>" required>
                            </p>
                            <p>
                                <label>Message:</label><br>
                                <textarea name="message" rows="10" required>Hi <?php echo esc_html($contact['name']); ?>,

I'd love to be a guest on <?php echo esc_html($contact['podcast_name'] ?: 'your podcast'); ?>. I have some great insights to share with your audience.

Looking forward to hearing from you!</textarea>
                            </p>
                            <p>
                                <button type="submit" class="button button-primary">Send Email</button>
                            </p>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="pit-contact-not-found">
                        <p><strong>No email found for this entry.</strong></p>

                        <?php if ($contact['suggest_enrichment']): ?>
                            <div class="pit-enrichment-suggestion">
                                <p>We found the host name: <strong><?php echo esc_html($contact['name']); ?></strong></p>
                                <p>Would you like to enrich this contact with Clay to find their email?</p>
                                <button class="button" data-action="enrich-contact" data-entry-id="<?php echo esc_attr($entry_id); ?>">
                                    Enrich with Clay
                                </button>
                            </div>
                        <?php endif; ?>

                        <div class="pit-manual-entry">
                            <h4>Manual Entry</h4>
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
            .pit-email-interface {
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
            .pit-email-form input,
            .pit-email-form textarea {
                width: 100%;
                max-width: 100%;
            }
            .pit-source {
                color: #666;
                font-style: italic;
            }
            .pit-additional-info {
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid #ddd;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Handle email send
            $('.pit-send-email-form').on('submit', function(e) {
                e.preventDefault();

                var form = $(this);
                var entryId = form.data('entry-id');
                var button = form.find('button[type="submit"]');

                button.prop('disabled', true).text('Sending...');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'pit_send_email',
                        entry_id: entryId,
                        to: form.find('[name="to"]').val(),
                        subject: form.find('[name="subject"]').val(),
                        message: form.find('[name="message"]').val(),
                        nonce: '<?php echo wp_create_nonce('pit_send_email'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Email sent successfully!');
                        } else {
                            alert('Error: ' + (response.data || 'Unknown error'));
                        }
                        button.prop('disabled', false).text('Send Email');
                    },
                    error: function() {
                        alert('Error sending email. Please try again.');
                        button.prop('disabled', false).text('Send Email');
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
     * AJAX: Send email
     */
    public function ajax_send_email() {
        check_ajax_referer('pit_send_email', 'nonce');

        $entry_id = intval($_POST['entry_id'] ?? 0);
        $to = sanitize_email($_POST['to'] ?? '');
        $subject = sanitize_text_field($_POST['subject'] ?? '');
        $message = sanitize_textarea_field($_POST['message'] ?? '');

        if (!$entry_id || !$to || !$subject || !$message) {
            wp_send_json_error('Missing required fields');
        }

        // Send email
        $sent = wp_mail($to, $subject, $message);

        if ($sent) {
            // Update outreach status
            $bridge = PIT_Formidable_Podcast_Bridge::get_instance();
            $bridge->mark_first_contact($entry_id);

            wp_send_json_success('Email sent successfully');
        } else {
            wp_send_json_error('Failed to send email');
        }
    }
}
