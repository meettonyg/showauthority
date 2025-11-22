<?php
/**
 * Admin Bulk Tools
 *
 * Provides bulk update functionality for podcast and contact tables.
 * Uses podcast_index_id as the primary lookup key for podcast data.
 *
 * @package Podcast_Influence_Tracker
 * @subpackage Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Admin_Bulk_Tools {

    /**
     * @var PIT_Admin_Bulk_Tools Singleton instance
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
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'handle_bulk_actions']);
        add_action('wp_ajax_pit_bulk_import_preview', [$this, 'ajax_preview_import']);
        add_action('wp_ajax_pit_bulk_import_execute', [$this, 'ajax_execute_import']);
        add_action('wp_ajax_pit_export_table', [$this, 'ajax_export_table']);
    }

    /**
     * Add submenu page
     */
    public function add_menu_page() {
        add_submenu_page(
            'podcast-influence',
            __('Bulk Tools', 'podcast-influence-tracker'),
            __('Bulk Tools', 'podcast-influence-tracker'),
            'manage_options',
            'podcast-influence-bulk-tools',
            [$this, 'render_page']
        );
    }

    /**
     * Render the bulk tools page
     */
    public function render_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'podcasts';
        ?>
        <div class="wrap">
            <h1><?php _e('Podcast Intelligence Bulk Tools', 'podcast-influence-tracker'); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=podcast-influence-bulk-tools&tab=podcasts"
                   class="nav-tab <?php echo $active_tab === 'podcasts' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Podcasts', 'podcast-influence-tracker'); ?>
                </a>
                <a href="?page=podcast-influence-bulk-tools&tab=contacts"
                   class="nav-tab <?php echo $active_tab === 'contacts' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Contacts', 'podcast-influence-tracker'); ?>
                </a>
                <a href="?page=podcast-influence-bulk-tools&tab=relationships"
                   class="nav-tab <?php echo $active_tab === 'relationships' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Relationships', 'podcast-influence-tracker'); ?>
                </a>
                <a href="?page=podcast-influence-bulk-tools&tab=export"
                   class="nav-tab <?php echo $active_tab === 'export' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Export', 'podcast-influence-tracker'); ?>
                </a>
            </nav>

            <div class="tab-content" style="margin-top: 20px;">
                <?php
                switch ($active_tab) {
                    case 'contacts':
                        $this->render_contacts_tab();
                        break;
                    case 'relationships':
                        $this->render_relationships_tab();
                        break;
                    case 'export':
                        $this->render_export_tab();
                        break;
                    case 'podcasts':
                    default:
                        $this->render_podcasts_tab();
                        break;
                }
                ?>
            </div>
        </div>

        <?php $this->render_scripts(); ?>
        <?php
    }

    /**
     * Render Podcasts bulk import tab
     */
    private function render_podcasts_tab() {
        ?>
        <div class="pit-bulk-tool">
            <h2><?php _e('Bulk Update Podcasts', 'podcast-influence-tracker'); ?></h2>
            <p class="description">
                <?php _e('Import or update podcast data using Podcast Index ID as the lookup key. Existing podcasts will be updated, new ones will be created.', 'podcast-influence-tracker'); ?>
            </p>

            <div class="pit-import-section">
                <h3><?php _e('Import Data', 'podcast-influence-tracker'); ?></h3>

                <form id="pit-podcasts-import-form" method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('pit_bulk_import', 'pit_bulk_nonce'); ?>
                    <input type="hidden" name="import_type" value="podcasts">
                    <input type="hidden" name="lookup_field" value="podcast_index_id">

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="import_method"><?php _e('Import Method', 'podcast-influence-tracker'); ?></label>
                            </th>
                            <td>
                                <select id="import_method" name="import_method" class="pit-import-method">
                                    <option value="paste"><?php _e('Paste Data (CSV/JSON)', 'podcast-influence-tracker'); ?></option>
                                    <option value="file"><?php _e('Upload File', 'podcast-influence-tracker'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr class="pit-paste-row">
                            <th scope="row">
                                <label for="import_data"><?php _e('Paste Data', 'podcast-influence-tracker'); ?></label>
                            </th>
                            <td>
                                <textarea id="import_data" name="import_data" rows="10" class="large-text code"
                                    placeholder="<?php esc_attr_e('Paste CSV or JSON data here...', 'podcast-influence-tracker'); ?>"></textarea>
                                <p class="description"><?php _e('Paste CSV (with headers) or JSON array data.', 'podcast-influence-tracker'); ?></p>
                            </td>
                        </tr>
                        <tr class="pit-file-row" style="display:none;">
                            <th scope="row">
                                <label for="import_file"><?php _e('Upload File', 'podcast-influence-tracker'); ?></label>
                            </th>
                            <td>
                                <input type="file" id="import_file" name="import_file" accept=".csv,.json">
                                <p class="description"><?php _e('Upload a CSV or JSON file.', 'podcast-influence-tracker'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="lookup_field"><?php _e('Lookup Field', 'podcast-influence-tracker'); ?></label>
                            </th>
                            <td>
                                <select id="lookup_field" name="lookup_field">
                                    <option value="podcast_index_id" selected><?php _e('Podcast Index ID (recommended)', 'podcast-influence-tracker'); ?></option>
                                    <option value="podcast_index_guid"><?php _e('Podcast Index GUID', 'podcast-influence-tracker'); ?></option>
                                    <option value="rss_feed_url"><?php _e('RSS Feed URL', 'podcast-influence-tracker'); ?></option>
                                    <option value="itunes_id"><?php _e('iTunes ID', 'podcast-influence-tracker'); ?></option>
                                </select>
                                <p class="description"><?php _e('Field used to match existing podcasts for updates.', 'podcast-influence-tracker'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <h4><?php _e('Available Fields for Podcasts', 'podcast-influence-tracker'); ?></h4>
                    <div class="pit-field-reference">
                        <code>podcast_index_id</code>, <code>podcast_index_guid</code>, <code>title</code>, <code>description</code>,
                        <code>rss_feed_url</code>, <code>website_url</code>, <code>category</code>, <code>language</code>,
                        <code>episode_count</code>, <code>frequency</code>, <code>average_duration</code>,
                        <code>itunes_id</code>, <code>artwork_url</code>, <code>city</code>, <code>state_region</code>,
                        <code>country</code>, <code>country_code</code>, <code>timezone</code>, <code>location_display</code>,
                        <code>data_quality_score</code>, <code>relevance_score</code>, <code>source</code>, <code>is_active</code>
                    </div>

                    <p class="submit">
                        <button type="button" class="button button-secondary pit-preview-btn" data-type="podcasts">
                            <?php _e('Preview Import', 'podcast-influence-tracker'); ?>
                        </button>
                        <button type="button" class="button button-primary pit-import-btn" data-type="podcasts" disabled>
                            <?php _e('Execute Import', 'podcast-influence-tracker'); ?>
                        </button>
                    </p>
                </form>

                <div id="pit-podcasts-preview" class="pit-preview-results" style="display:none;">
                    <h4><?php _e('Preview Results', 'podcast-influence-tracker'); ?></h4>
                    <div class="pit-preview-summary"></div>
                    <div class="pit-preview-table"></div>
                </div>

                <div id="pit-podcasts-results" class="pit-import-results" style="display:none;">
                    <h4><?php _e('Import Results', 'podcast-influence-tracker'); ?></h4>
                    <div class="pit-results-content"></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Contacts bulk import tab
     */
    private function render_contacts_tab() {
        ?>
        <div class="pit-bulk-tool">
            <h2><?php _e('Bulk Update Contacts', 'podcast-influence-tracker'); ?></h2>
            <p class="description">
                <?php _e('Import or update contact data. Contacts are matched by email address.', 'podcast-influence-tracker'); ?>
            </p>

            <div class="pit-import-section">
                <h3><?php _e('Import Data', 'podcast-influence-tracker'); ?></h3>

                <form id="pit-contacts-import-form" method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('pit_bulk_import', 'pit_bulk_nonce'); ?>
                    <input type="hidden" name="import_type" value="contacts">

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="import_method_contacts"><?php _e('Import Method', 'podcast-influence-tracker'); ?></label>
                            </th>
                            <td>
                                <select id="import_method_contacts" name="import_method" class="pit-import-method">
                                    <option value="paste"><?php _e('Paste Data (CSV/JSON)', 'podcast-influence-tracker'); ?></option>
                                    <option value="file"><?php _e('Upload File', 'podcast-influence-tracker'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr class="pit-paste-row">
                            <th scope="row">
                                <label for="import_data_contacts"><?php _e('Paste Data', 'podcast-influence-tracker'); ?></label>
                            </th>
                            <td>
                                <textarea id="import_data_contacts" name="import_data" rows="10" class="large-text code"
                                    placeholder="<?php esc_attr_e('Paste CSV or JSON data here...', 'podcast-influence-tracker'); ?>"></textarea>
                            </td>
                        </tr>
                        <tr class="pit-file-row" style="display:none;">
                            <th scope="row">
                                <label for="import_file_contacts"><?php _e('Upload File', 'podcast-influence-tracker'); ?></label>
                            </th>
                            <td>
                                <input type="file" id="import_file_contacts" name="import_file" accept=".csv,.json">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="lookup_field_contacts"><?php _e('Lookup Field', 'podcast-influence-tracker'); ?></label>
                            </th>
                            <td>
                                <select id="lookup_field_contacts" name="lookup_field">
                                    <option value="email" selected><?php _e('Email (recommended)', 'podcast-influence-tracker'); ?></option>
                                    <option value="id"><?php _e('Contact ID', 'podcast-influence-tracker'); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <h4><?php _e('Available Fields for Contacts', 'podcast-influence-tracker'); ?></h4>
                    <div class="pit-field-reference">
                        <code>full_name</code>, <code>first_name</code>, <code>last_name</code>, <code>email</code>,
                        <code>personal_email</code>, <code>phone</code>, <code>role</code>, <code>company</code>,
                        <code>title</code>, <code>linkedin_url</code>, <code>twitter_url</code>, <code>website_url</code>,
                        <code>city</code>, <code>state_region</code>, <code>country</code>, <code>country_code</code>,
                        <code>timezone</code>, <code>location_display</code>, <code>notes</code>, <code>source</code>
                    </div>

                    <p class="submit">
                        <button type="button" class="button button-secondary pit-preview-btn" data-type="contacts">
                            <?php _e('Preview Import', 'podcast-influence-tracker'); ?>
                        </button>
                        <button type="button" class="button button-primary pit-import-btn" data-type="contacts" disabled>
                            <?php _e('Execute Import', 'podcast-influence-tracker'); ?>
                        </button>
                    </p>
                </form>

                <div id="pit-contacts-preview" class="pit-preview-results" style="display:none;">
                    <h4><?php _e('Preview Results', 'podcast-influence-tracker'); ?></h4>
                    <div class="pit-preview-summary"></div>
                    <div class="pit-preview-table"></div>
                </div>

                <div id="pit-contacts-results" class="pit-import-results" style="display:none;">
                    <h4><?php _e('Import Results', 'podcast-influence-tracker'); ?></h4>
                    <div class="pit-results-content"></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Relationships bulk import tab
     */
    private function render_relationships_tab() {
        ?>
        <div class="pit-bulk-tool">
            <h2><?php _e('Bulk Link Contacts to Podcasts', 'podcast-influence-tracker'); ?></h2>
            <p class="description">
                <?php _e('Create relationships between contacts and podcasts. Use podcast_index_id to identify podcasts and email to identify contacts.', 'podcast-influence-tracker'); ?>
            </p>

            <div class="pit-import-section">
                <h3><?php _e('Import Relationships', 'podcast-influence-tracker'); ?></h3>

                <form id="pit-relationships-import-form" method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('pit_bulk_import', 'pit_bulk_nonce'); ?>
                    <input type="hidden" name="import_type" value="relationships">

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="import_method_rel"><?php _e('Import Method', 'podcast-influence-tracker'); ?></label>
                            </th>
                            <td>
                                <select id="import_method_rel" name="import_method" class="pit-import-method">
                                    <option value="paste"><?php _e('Paste Data (CSV/JSON)', 'podcast-influence-tracker'); ?></option>
                                    <option value="file"><?php _e('Upload File', 'podcast-influence-tracker'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr class="pit-paste-row">
                            <th scope="row">
                                <label for="import_data_rel"><?php _e('Paste Data', 'podcast-influence-tracker'); ?></label>
                            </th>
                            <td>
                                <textarea id="import_data_rel" name="import_data" rows="10" class="large-text code"
                                    placeholder="<?php esc_attr_e('Paste CSV or JSON data here...', 'podcast-influence-tracker'); ?>"></textarea>
                            </td>
                        </tr>
                        <tr class="pit-file-row" style="display:none;">
                            <th scope="row">
                                <label for="import_file_rel"><?php _e('Upload File', 'podcast-influence-tracker'); ?></label>
                            </th>
                            <td>
                                <input type="file" id="import_file_rel" name="import_file" accept=".csv,.json">
                            </td>
                        </tr>
                    </table>

                    <h4><?php _e('Required Fields for Relationships', 'podcast-influence-tracker'); ?></h4>
                    <div class="pit-field-reference">
                        <strong>Required:</strong> <code>podcast_index_id</code>, <code>contact_email</code>, <code>role</code><br>
                        <strong>Optional:</strong> <code>is_primary</code> (0 or 1), <code>notes</code>
                    </div>

                    <p class="submit">
                        <button type="button" class="button button-secondary pit-preview-btn" data-type="relationships">
                            <?php _e('Preview Import', 'podcast-influence-tracker'); ?>
                        </button>
                        <button type="button" class="button button-primary pit-import-btn" data-type="relationships" disabled>
                            <?php _e('Execute Import', 'podcast-influence-tracker'); ?>
                        </button>
                    </p>
                </form>

                <div id="pit-relationships-preview" class="pit-preview-results" style="display:none;">
                    <h4><?php _e('Preview Results', 'podcast-influence-tracker'); ?></h4>
                    <div class="pit-preview-summary"></div>
                    <div class="pit-preview-table"></div>
                </div>

                <div id="pit-relationships-results" class="pit-import-results" style="display:none;">
                    <h4><?php _e('Import Results', 'podcast-influence-tracker'); ?></h4>
                    <div class="pit-results-content"></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Export tab
     */
    private function render_export_tab() {
        ?>
        <div class="pit-bulk-tool">
            <h2><?php _e('Export Data', 'podcast-influence-tracker'); ?></h2>
            <p class="description">
                <?php _e('Export podcast and contact data in CSV or JSON format.', 'podcast-influence-tracker'); ?>
            </p>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Export Podcasts', 'podcast-influence-tracker'); ?></th>
                    <td>
                        <a href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=pit_export_table&table=podcasts&format=csv'), 'pit_export'); ?>"
                           class="button"><?php _e('Export CSV', 'podcast-influence-tracker'); ?></a>
                        <a href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=pit_export_table&table=podcasts&format=json'), 'pit_export'); ?>"
                           class="button"><?php _e('Export JSON', 'podcast-influence-tracker'); ?></a>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Export Contacts', 'podcast-influence-tracker'); ?></th>
                    <td>
                        <a href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=pit_export_table&table=contacts&format=csv'), 'pit_export'); ?>"
                           class="button"><?php _e('Export CSV', 'podcast-influence-tracker'); ?></a>
                        <a href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=pit_export_table&table=contacts&format=json'), 'pit_export'); ?>"
                           class="button"><?php _e('Export JSON', 'podcast-influence-tracker'); ?></a>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Export Relationships', 'podcast-influence-tracker'); ?></th>
                    <td>
                        <a href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=pit_export_table&table=relationships&format=csv'), 'pit_export'); ?>"
                           class="button"><?php _e('Export CSV', 'podcast-influence-tracker'); ?></a>
                        <a href="<?php echo wp_nonce_url(admin_url('admin-ajax.php?action=pit_export_table&table=relationships&format=json'), 'pit_export'); ?>"
                           class="button"><?php _e('Export JSON', 'podcast-influence-tracker'); ?></a>
                    </td>
                </tr>
            </table>

            <h3><?php _e('Database Statistics', 'podcast-influence-tracker'); ?></h3>
            <?php $this->render_stats(); ?>
        </div>
        <?php
    }

    /**
     * Render database statistics
     */
    private function render_stats() {
        global $wpdb;

        $podcasts_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}guestify_podcasts");
        $contacts_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}guestify_podcast_contacts");
        $relationships_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}guestify_podcast_contact_relationships");
        $tracker_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}guestify_interview_tracker_podcasts");
        ?>
        <table class="widefat striped" style="max-width: 500px;">
            <tbody>
                <tr>
                    <td><strong><?php _e('Total Podcasts', 'podcast-influence-tracker'); ?></strong></td>
                    <td><?php echo number_format_i18n($podcasts_count); ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e('Total Contacts', 'podcast-influence-tracker'); ?></strong></td>
                    <td><?php echo number_format_i18n($contacts_count); ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e('Contact-Podcast Relationships', 'podcast-influence-tracker'); ?></strong></td>
                    <td><?php echo number_format_i18n($relationships_count); ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e('Interview Tracker Links', 'podcast-influence-tracker'); ?></strong></td>
                    <td><?php echo number_format_i18n($tracker_count); ?></td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render JavaScript for the bulk tools
     */
    private function render_scripts() {
        ?>
        <style>
            .pit-bulk-tool { max-width: 1200px; }
            .pit-field-reference {
                background: #f5f5f5;
                padding: 15px;
                border-radius: 4px;
                margin: 10px 0 20px;
                font-size: 13px;
            }
            .pit-field-reference code {
                background: #e0e0e0;
                padding: 2px 6px;
                margin: 2px;
                display: inline-block;
            }
            .pit-preview-results, .pit-import-results {
                margin-top: 20px;
                padding: 15px;
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
            }
            .pit-preview-summary {
                margin-bottom: 15px;
                padding: 10px;
                background: #f0f6fc;
                border-left: 4px solid #2271b1;
            }
            .pit-preview-table table {
                width: 100%;
                border-collapse: collapse;
            }
            .pit-preview-table th, .pit-preview-table td {
                padding: 8px;
                border: 1px solid #ddd;
                text-align: left;
                font-size: 12px;
            }
            .pit-preview-table th {
                background: #f5f5f5;
            }
            .pit-preview-table .status-new { color: #00a32a; }
            .pit-preview-table .status-update { color: #2271b1; }
            .pit-preview-table .status-error { color: #d63638; }
            .pit-results-content .success { color: #00a32a; }
            .pit-results-content .error { color: #d63638; }
            .pit-loading { opacity: 0.6; pointer-events: none; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Toggle import method (paste vs file)
            $('.pit-import-method').on('change', function() {
                var form = $(this).closest('form');
                if ($(this).val() === 'paste') {
                    form.find('.pit-paste-row').show();
                    form.find('.pit-file-row').hide();
                } else {
                    form.find('.pit-paste-row').hide();
                    form.find('.pit-file-row').show();
                }
            });

            // Preview button
            $('.pit-preview-btn').on('click', function() {
                var type = $(this).data('type');
                var form = $('#pit-' + type + '-import-form');
                var previewDiv = $('#pit-' + type + '-preview');
                var importBtn = form.find('.pit-import-btn');

                form.addClass('pit-loading');
                previewDiv.hide();
                importBtn.prop('disabled', true);

                var formData = new FormData(form[0]);
                formData.append('action', 'pit_bulk_import_preview');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        form.removeClass('pit-loading');
                        if (response.success) {
                            var data = response.data;
                            var summary = '<strong>Records found:</strong> ' + data.total + '<br>';
                            summary += '<strong>New records:</strong> <span class="status-new">' + data.new_count + '</span><br>';
                            summary += '<strong>Updates:</strong> <span class="status-update">' + data.update_count + '</span><br>';
                            if (data.error_count > 0) {
                                summary += '<strong>Errors:</strong> <span class="status-error">' + data.error_count + '</span>';
                            }

                            previewDiv.find('.pit-preview-summary').html(summary);
                            previewDiv.find('.pit-preview-table').html(data.preview_html);
                            previewDiv.show();

                            if (data.total > 0 && data.error_count < data.total) {
                                importBtn.prop('disabled', false);
                            }

                            // Store parsed data for import
                            form.data('parsed_data', data.parsed_data);
                        } else {
                            alert('Error: ' + response.data);
                        }
                    },
                    error: function() {
                        form.removeClass('pit-loading');
                        alert('Request failed. Please try again.');
                    }
                });
            });

            // Import button
            $('.pit-import-btn').on('click', function() {
                if (!confirm('Are you sure you want to import this data? This will create/update records in the database.')) {
                    return;
                }

                var type = $(this).data('type');
                var form = $('#pit-' + type + '-import-form');
                var resultsDiv = $('#pit-' + type + '-results');
                var btn = $(this);

                form.addClass('pit-loading');
                btn.prop('disabled', true).text('Importing...');

                var formData = new FormData(form[0]);
                formData.append('action', 'pit_bulk_import_execute');
                formData.append('parsed_data', JSON.stringify(form.data('parsed_data')));

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        form.removeClass('pit-loading');
                        btn.text('Execute Import');

                        if (response.success) {
                            var data = response.data;
                            var html = '<p class="success"><strong>Import completed!</strong></p>';
                            html += '<p>Created: ' + data.created + ' | Updated: ' + data.updated + ' | Errors: ' + data.errors + '</p>';
                            if (data.error_messages && data.error_messages.length > 0) {
                                html += '<div class="error"><strong>Errors:</strong><ul>';
                                data.error_messages.forEach(function(msg) {
                                    html += '<li>' + msg + '</li>';
                                });
                                html += '</ul></div>';
                            }
                            resultsDiv.find('.pit-results-content').html(html);
                            resultsDiv.show();
                        } else {
                            resultsDiv.find('.pit-results-content').html('<p class="error">Import failed: ' + response.data + '</p>');
                            resultsDiv.show();
                        }
                    },
                    error: function() {
                        form.removeClass('pit-loading');
                        btn.text('Execute Import');
                        alert('Request failed. Please try again.');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Handle form submissions
     */
    public function handle_bulk_actions() {
        // Handle form posts if needed
    }

    /**
     * AJAX: Preview import
     */
    public function ajax_preview_import() {
        check_ajax_referer('pit_bulk_import', 'pit_bulk_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $import_type = sanitize_key($_POST['import_type'] ?? 'podcasts');
        $import_method = sanitize_key($_POST['import_method'] ?? 'paste');
        $lookup_field = sanitize_key($_POST['lookup_field'] ?? 'podcast_index_id');

        // Get data
        $raw_data = '';
        if ($import_method === 'paste') {
            $raw_data = wp_unslash($_POST['import_data'] ?? '');
        } else if (!empty($_FILES['import_file']['tmp_name'])) {
            $raw_data = file_get_contents($_FILES['import_file']['tmp_name']);
        }

        if (empty($raw_data)) {
            wp_send_json_error('No data provided');
        }

        // Parse data (CSV or JSON)
        $parsed_data = $this->parse_import_data($raw_data);
        if (is_wp_error($parsed_data)) {
            wp_send_json_error($parsed_data->get_error_message());
        }

        // Preview based on type
        switch ($import_type) {
            case 'contacts':
                $preview = $this->preview_contacts_import($parsed_data, $lookup_field);
                break;
            case 'relationships':
                $preview = $this->preview_relationships_import($parsed_data);
                break;
            case 'podcasts':
            default:
                $preview = $this->preview_podcasts_import($parsed_data, $lookup_field);
                break;
        }

        wp_send_json_success($preview);
    }

    /**
     * Parse CSV or JSON data
     */
    private function parse_import_data($raw_data) {
        $raw_data = trim($raw_data);

        // Try JSON first
        if (substr($raw_data, 0, 1) === '[' || substr($raw_data, 0, 1) === '{') {
            $decoded = json_decode($raw_data, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // If it's an object with a data key, extract it
                if (isset($decoded['data']) && is_array($decoded['data'])) {
                    return $decoded['data'];
                }
                // If it's a single object, wrap it
                if (!isset($decoded[0])) {
                    return [$decoded];
                }
                return $decoded;
            }
        }

        // Parse as CSV
        $lines = explode("\n", $raw_data);
        $lines = array_filter($lines, function($line) {
            return trim($line) !== '';
        });

        if (count($lines) < 2) {
            return new WP_Error('invalid_data', 'CSV must have a header row and at least one data row');
        }

        // Parse header
        $header = str_getcsv(array_shift($lines));
        $header = array_map('trim', $header);
        $header = array_map('sanitize_key', $header);

        $data = [];
        foreach ($lines as $line) {
            $values = str_getcsv($line);
            if (count($values) !== count($header)) {
                continue; // Skip malformed rows
            }
            $row = array_combine($header, $values);
            $data[] = $row;
        }

        return $data;
    }

    /**
     * Preview podcasts import
     */
    private function preview_podcasts_import($data, $lookup_field) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_podcasts';

        $preview = [
            'total' => count($data),
            'new_count' => 0,
            'update_count' => 0,
            'error_count' => 0,
            'parsed_data' => $data,
            'preview_html' => '',
        ];

        $rows = [];
        foreach (array_slice($data, 0, 20) as $row) { // Preview first 20
            $lookup_value = $row[$lookup_field] ?? '';
            $status = 'new';
            $status_text = 'New';

            if (empty($lookup_value)) {
                $status = 'error';
                $status_text = 'Missing ' . $lookup_field;
                $preview['error_count']++;
            } else {
                // Check if exists
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table WHERE $lookup_field = %s",
                    $lookup_value
                ));

                if ($existing) {
                    $status = 'update';
                    $status_text = 'Update (ID: ' . $existing . ')';
                    $preview['update_count']++;
                } else {
                    $preview['new_count']++;
                }
            }

            $rows[] = [
                'status' => $status,
                'status_text' => $status_text,
                'lookup' => $lookup_value,
                'title' => $row['title'] ?? '',
                'rss' => isset($row['rss_feed_url']) ? substr($row['rss_feed_url'], 0, 50) . '...' : '',
            ];
        }

        // Build preview table
        $html = '<table><thead><tr>';
        $html .= '<th>Status</th><th>' . esc_html($lookup_field) . '</th><th>Title</th><th>RSS URL</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            $html .= '<td class="status-' . $row['status'] . '">' . esc_html($row['status_text']) . '</td>';
            $html .= '<td>' . esc_html($row['lookup']) . '</td>';
            $html .= '<td>' . esc_html($row['title']) . '</td>';
            $html .= '<td>' . esc_html($row['rss']) . '</td>';
            $html .= '</tr>';
        }

        if (count($data) > 20) {
            $html .= '<tr><td colspan="4"><em>... and ' . (count($data) - 20) . ' more rows</em></td></tr>';
        }

        $html .= '</tbody></table>';
        $preview['preview_html'] = $html;

        return $preview;
    }

    /**
     * Preview contacts import
     */
    private function preview_contacts_import($data, $lookup_field) {
        global $wpdb;
        $table = $wpdb->prefix . 'guestify_podcast_contacts';

        $preview = [
            'total' => count($data),
            'new_count' => 0,
            'update_count' => 0,
            'error_count' => 0,
            'parsed_data' => $data,
            'preview_html' => '',
        ];

        $rows = [];
        foreach (array_slice($data, 0, 20) as $row) {
            $lookup_value = $row[$lookup_field] ?? '';
            $status = 'new';
            $status_text = 'New';

            if ($lookup_field === 'email' && empty($lookup_value)) {
                $status = 'error';
                $status_text = 'Missing email';
                $preview['error_count']++;
            } else if (!empty($lookup_value)) {
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table WHERE $lookup_field = %s",
                    $lookup_value
                ));

                if ($existing) {
                    $status = 'update';
                    $status_text = 'Update (ID: ' . $existing . ')';
                    $preview['update_count']++;
                } else {
                    $preview['new_count']++;
                }
            } else {
                $preview['new_count']++;
            }

            $rows[] = [
                'status' => $status,
                'status_text' => $status_text,
                'name' => $row['full_name'] ?? ($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''),
                'email' => $row['email'] ?? '',
                'role' => $row['role'] ?? '',
            ];
        }

        $html = '<table><thead><tr>';
        $html .= '<th>Status</th><th>Name</th><th>Email</th><th>Role</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            $html .= '<td class="status-' . $row['status'] . '">' . esc_html($row['status_text']) . '</td>';
            $html .= '<td>' . esc_html($row['name']) . '</td>';
            $html .= '<td>' . esc_html($row['email']) . '</td>';
            $html .= '<td>' . esc_html($row['role']) . '</td>';
            $html .= '</tr>';
        }

        if (count($data) > 20) {
            $html .= '<tr><td colspan="4"><em>... and ' . (count($data) - 20) . ' more rows</em></td></tr>';
        }

        $html .= '</tbody></table>';
        $preview['preview_html'] = $html;

        return $preview;
    }

    /**
     * Preview relationships import
     */
    private function preview_relationships_import($data) {
        global $wpdb;

        $preview = [
            'total' => count($data),
            'new_count' => 0,
            'update_count' => 0,
            'error_count' => 0,
            'parsed_data' => $data,
            'preview_html' => '',
        ];

        $rows = [];
        foreach (array_slice($data, 0, 20) as $row) {
            $podcast_index_id = $row['podcast_index_id'] ?? '';
            $contact_email = $row['contact_email'] ?? '';
            $role = $row['role'] ?? '';
            $status = 'new';
            $status_text = 'New';
            $podcast_title = '';
            $contact_name = '';

            if (empty($podcast_index_id) || empty($contact_email) || empty($role)) {
                $status = 'error';
                $status_text = 'Missing required fields';
                $preview['error_count']++;
            } else {
                // Find podcast
                $podcast = PIT_Database::get_podcast_by_podcast_index_id($podcast_index_id);
                if (!$podcast) {
                    $status = 'error';
                    $status_text = 'Podcast not found';
                    $preview['error_count']++;
                } else {
                    $podcast_title = $podcast->title;

                    // Find contact
                    $contact = PIT_Database::get_contact_by_email($contact_email);
                    if (!$contact) {
                        $status = 'error';
                        $status_text = 'Contact not found';
                        $preview['error_count']++;
                    } else {
                        $contact_name = $contact->full_name;

                        // Check if relationship exists
                        $rel_table = $wpdb->prefix . 'guestify_podcast_contact_relationships';
                        $existing = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM $rel_table WHERE podcast_id = %d AND contact_id = %d AND role = %s",
                            $podcast->id,
                            $contact->id,
                            $role
                        ));

                        if ($existing) {
                            $status = 'update';
                            $status_text = 'Update';
                            $preview['update_count']++;
                        } else {
                            $preview['new_count']++;
                        }
                    }
                }
            }

            $rows[] = [
                'status' => $status,
                'status_text' => $status_text,
                'podcast' => $podcast_title ?: $podcast_index_id,
                'contact' => $contact_name ?: $contact_email,
                'role' => $role,
            ];
        }

        $html = '<table><thead><tr>';
        $html .= '<th>Status</th><th>Podcast</th><th>Contact</th><th>Role</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            $html .= '<td class="status-' . $row['status'] . '">' . esc_html($row['status_text']) . '</td>';
            $html .= '<td>' . esc_html($row['podcast']) . '</td>';
            $html .= '<td>' . esc_html($row['contact']) . '</td>';
            $html .= '<td>' . esc_html($row['role']) . '</td>';
            $html .= '</tr>';
        }

        if (count($data) > 20) {
            $html .= '<tr><td colspan="4"><em>... and ' . (count($data) - 20) . ' more rows</em></td></tr>';
        }

        $html .= '</tbody></table>';
        $preview['preview_html'] = $html;

        return $preview;
    }

    /**
     * AJAX: Execute import
     */
    public function ajax_execute_import() {
        check_ajax_referer('pit_bulk_import', 'pit_bulk_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $import_type = sanitize_key($_POST['import_type'] ?? 'podcasts');
        $lookup_field = sanitize_key($_POST['lookup_field'] ?? 'podcast_index_id');
        $parsed_data = json_decode(wp_unslash($_POST['parsed_data'] ?? '[]'), true);

        if (empty($parsed_data)) {
            wp_send_json_error('No data to import');
        }

        switch ($import_type) {
            case 'contacts':
                $result = $this->execute_contacts_import($parsed_data, $lookup_field);
                break;
            case 'relationships':
                $result = $this->execute_relationships_import($parsed_data);
                break;
            case 'podcasts':
            default:
                $result = $this->execute_podcasts_import($parsed_data, $lookup_field);
                break;
        }

        wp_send_json_success($result);
    }

    /**
     * Execute podcasts import
     */
    private function execute_podcasts_import($data, $lookup_field) {
        $result = [
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
            'error_messages' => [],
        ];

        $allowed_fields = [
            'podcast_index_id', 'podcast_index_guid', 'title', 'slug', 'description',
            'rss_feed_url', 'website_url', 'category', 'language', 'episode_count',
            'frequency', 'average_duration', 'itunes_id', 'taddy_podcast_uuid',
            'artwork_url', 'city', 'state_region', 'country', 'country_code',
            'timezone', 'location_display', 'data_quality_score', 'relevance_score',
            'source', 'is_active', 'is_tracked'
        ];

        foreach ($data as $index => $row) {
            $lookup_value = $row[$lookup_field] ?? '';

            if (empty($lookup_value)) {
                $result['errors']++;
                $result['error_messages'][] = "Row " . ($index + 1) . ": Missing $lookup_field";
                continue;
            }

            // Build insert data
            $insert_data = [];
            foreach ($row as $key => $value) {
                $key = sanitize_key($key);
                if (in_array($key, $allowed_fields) && $value !== '') {
                    $insert_data[$key] = sanitize_text_field($value);
                }
            }

            // Integer fields
            $int_fields = ['podcast_index_id', 'episode_count', 'average_duration', 'data_quality_score', 'relevance_score', 'is_active', 'is_tracked'];
            foreach ($int_fields as $field) {
                if (isset($insert_data[$field])) {
                    $insert_data[$field] = intval($insert_data[$field]);
                }
            }

            try {
                // Check if exists
                global $wpdb;
                $table = $wpdb->prefix . 'guestify_podcasts';
                $existing_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $table WHERE $lookup_field = %s",
                    $lookup_value
                ));

                if ($existing_id) {
                    // Update
                    $wpdb->update($table, $insert_data, ['id' => $existing_id]);
                    $result['updated']++;
                } else {
                    // Insert
                    $wpdb->insert($table, $insert_data);
                    if ($wpdb->insert_id) {
                        $result['created']++;
                    } else {
                        $result['errors']++;
                        $result['error_messages'][] = "Row " . ($index + 1) . ": Insert failed - " . $wpdb->last_error;
                    }
                }
            } catch (Exception $e) {
                $result['errors']++;
                $result['error_messages'][] = "Row " . ($index + 1) . ": " . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Execute contacts import
     */
    private function execute_contacts_import($data, $lookup_field) {
        $result = [
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
            'error_messages' => [],
        ];

        $allowed_fields = [
            'full_name', 'first_name', 'last_name', 'email', 'personal_email',
            'phone', 'role', 'company', 'title', 'linkedin_url', 'twitter_url',
            'website_url', 'city', 'state_region', 'country', 'country_code',
            'timezone', 'location_display', 'notes', 'source', 'data_quality_score'
        ];

        foreach ($data as $index => $row) {
            // Build insert data
            $insert_data = [];
            foreach ($row as $key => $value) {
                $key = sanitize_key($key);
                if (in_array($key, $allowed_fields) && $value !== '') {
                    $insert_data[$key] = sanitize_text_field($value);
                }
            }

            // Generate full_name if not provided
            if (empty($insert_data['full_name'])) {
                $parts = [];
                if (!empty($insert_data['first_name'])) $parts[] = $insert_data['first_name'];
                if (!empty($insert_data['last_name'])) $parts[] = $insert_data['last_name'];
                if (!empty($parts)) {
                    $insert_data['full_name'] = implode(' ', $parts);
                }
            }

            if (empty($insert_data['full_name'])) {
                $result['errors']++;
                $result['error_messages'][] = "Row " . ($index + 1) . ": Missing name";
                continue;
            }

            try {
                $contact_id = PIT_Database::upsert_contact($insert_data);
                if ($contact_id) {
                    // Check if it was an update or insert
                    global $wpdb;
                    $table = $wpdb->prefix . 'guestify_podcast_contacts';
                    if (!empty($insert_data['email'])) {
                        $existing = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM $table WHERE email = %s AND id != %d",
                            $insert_data['email'],
                            $contact_id
                        ));
                        if ($existing > 0) {
                            $result['updated']++;
                        } else {
                            $result['created']++;
                        }
                    } else {
                        $result['created']++;
                    }
                } else {
                    $result['errors']++;
                    $result['error_messages'][] = "Row " . ($index + 1) . ": Insert/update failed";
                }
            } catch (Exception $e) {
                $result['errors']++;
                $result['error_messages'][] = "Row " . ($index + 1) . ": " . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Execute relationships import
     */
    private function execute_relationships_import($data) {
        $result = [
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
            'error_messages' => [],
        ];

        foreach ($data as $index => $row) {
            $podcast_index_id = $row['podcast_index_id'] ?? '';
            $contact_email = $row['contact_email'] ?? '';
            $role = $row['role'] ?? '';

            if (empty($podcast_index_id) || empty($contact_email) || empty($role)) {
                $result['errors']++;
                $result['error_messages'][] = "Row " . ($index + 1) . ": Missing required fields";
                continue;
            }

            // Find podcast
            $podcast = PIT_Database::get_podcast_by_podcast_index_id($podcast_index_id);
            if (!$podcast) {
                $result['errors']++;
                $result['error_messages'][] = "Row " . ($index + 1) . ": Podcast not found (podcast_index_id: $podcast_index_id)";
                continue;
            }

            // Find contact
            $contact = PIT_Database::get_contact_by_email($contact_email);
            if (!$contact) {
                $result['errors']++;
                $result['error_messages'][] = "Row " . ($index + 1) . ": Contact not found (email: $contact_email)";
                continue;
            }

            try {
                $is_primary = !empty($row['is_primary']) && $row['is_primary'] != '0';
                $notes = $row['notes'] ?? null;

                // Check if relationship exists
                global $wpdb;
                $rel_table = $wpdb->prefix . 'guestify_podcast_contact_relationships';
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $rel_table WHERE podcast_id = %d AND contact_id = %d AND role = %s",
                    $podcast->id,
                    $contact->id,
                    $role
                ));

                $rel_id = PIT_Database::link_podcast_contact(
                    $podcast->id,
                    $contact->id,
                    sanitize_text_field($role),
                    $is_primary,
                    $notes ? sanitize_textarea_field($notes) : null
                );

                if ($rel_id) {
                    if ($existing) {
                        $result['updated']++;
                    } else {
                        $result['created']++;
                    }
                } else {
                    $result['errors']++;
                    $result['error_messages'][] = "Row " . ($index + 1) . ": Failed to create relationship";
                }
            } catch (Exception $e) {
                $result['errors']++;
                $result['error_messages'][] = "Row " . ($index + 1) . ": " . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * AJAX: Export table data
     */
    public function ajax_export_table() {
        if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'pit_export')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Permission denied');
        }

        $table = sanitize_key($_GET['table'] ?? 'podcasts');
        $format = sanitize_key($_GET['format'] ?? 'csv');

        global $wpdb;

        switch ($table) {
            case 'contacts':
                $data = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}guestify_podcast_contacts ORDER BY id", ARRAY_A);
                $filename = 'podcast-contacts';
                break;
            case 'relationships':
                $data = $wpdb->get_results("
                    SELECT r.*, p.title as podcast_title, p.podcast_index_id, c.full_name as contact_name, c.email as contact_email
                    FROM {$wpdb->prefix}guestify_podcast_contact_relationships r
                    LEFT JOIN {$wpdb->prefix}guestify_podcasts p ON r.podcast_id = p.id
                    LEFT JOIN {$wpdb->prefix}guestify_podcast_contacts c ON r.contact_id = c.id
                    ORDER BY r.id
                ", ARRAY_A);
                $filename = 'podcast-relationships';
                break;
            case 'podcasts':
            default:
                $data = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}guestify_podcasts ORDER BY id", ARRAY_A);
                $filename = 'podcasts';
                break;
        }

        if ($format === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="' . $filename . '-' . date('Y-m-d') . '.json"');
            echo json_encode($data, JSON_PRETTY_PRINT);
            exit;
        } else {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="' . $filename . '-' . date('Y-m-d') . '.csv"');

            $output = fopen('php://output', 'w');

            if (!empty($data)) {
                // Header row
                fputcsv($output, array_keys($data[0]));

                // Data rows
                foreach ($data as $row) {
                    fputcsv($output, $row);
                }
            }

            fclose($output);
            exit;
        }
    }
}
