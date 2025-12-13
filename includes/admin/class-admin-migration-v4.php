<?php
/**
 * Admin Page: Database Migration v4
 * 
 * Provides UI for running the v4.0 schema migration
 * (Global Guest Directory & CRM/Intelligence Separation)
 *
 * @package PodcastInfluenceTracker
 * @since 4.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Admin_Migration_V4 {

    private static $initialized = false;

    /**
     * Initialize admin hooks
     */
    public static function init() {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_init', [__CLASS__, 'handle_actions']);
        add_action('wp_ajax_pit_migration_v4_status', [__CLASS__, 'ajax_status']);
        add_action('wp_ajax_pit_migration_v4_run', [__CLASS__, 'ajax_run']);
        add_action('wp_ajax_pit_merge_guests', [__CLASS__, 'ajax_merge_guests']);
    }

    /**
     * Add admin menu item
     */
    public static function add_admin_menu() {
        add_submenu_page(
            'tools.php',
            'PIT Migration v4.0',
            'PIT Migration v4.0',
            'manage_options',
            'pit-migration-v4',
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Handle form actions
     */
    public static function handle_actions() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle dry run
        if (isset($_POST['pit_migration_dry_run']) && wp_verify_nonce($_POST['_wpnonce'], 'pit_migration_v4')) {
            $results = PIT_Schema_Migration_V4::run(true);
            set_transient('pit_migration_v4_results', $results, 300);
            wp_redirect(admin_url('tools.php?page=pit-migration-v4&action=dry_run_complete'));
            exit;
        }

        // Handle actual migration
        if (isset($_POST['pit_migration_execute']) && wp_verify_nonce($_POST['_wpnonce'], 'pit_migration_v4')) {
            $results = PIT_Schema_Migration_V4::run(false);
            set_transient('pit_migration_v4_results', $results, 300);
            wp_redirect(admin_url('tools.php?page=pit-migration-v4&action=migration_complete'));
            exit;
        }

        // Handle rollback
        if (isset($_POST['pit_migration_rollback']) && wp_verify_nonce($_POST['_wpnonce'], 'pit_migration_v4')) {
            $results = PIT_Schema_Migration_V4::rollback(false);
            set_transient('pit_migration_v4_results', $results, 300);
            wp_redirect(admin_url('tools.php?page=pit-migration-v4&action=rollback_complete'));
            exit;
        }

        // Handle v4.2 migration (Featured Podcasts)
        if (isset($_POST['pit_migration_v42_execute']) && wp_verify_nonce($_POST['_wpnonce'], 'pit_migration_v4')) {
            if (class_exists('PIT_Schema_Migration_V4_2')) {
                $results = PIT_Schema_Migration_V4_2::run(false);
                set_transient('pit_migration_v42_results', $results, 300);
            }
            wp_redirect(admin_url('tools.php?page=pit-migration-v4&action=v42_complete'));
            exit;
        }
    }

    /**
     * Render admin page
     */
    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }

        $status = PIT_Schema_Migration_V4::get_status();
        $results = get_transient('pit_migration_v4_results');
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';

        // Load merge helper for duplicate detection
        $duplicates = [];
        if (class_exists('PIT_Guest_Merge_Helper')) {
            $duplicates = PIT_Guest_Merge_Helper::find_duplicates();
        }

        ?>
        <div class="wrap">
            <h1>🚀 Database Migration v4.0</h1>
            <h2>Global Guest Directory & CRM/Intelligence Separation</h2>

            <?php if ($action === 'dry_run_complete'): ?>
                <div class="notice notice-info">
                    <p><strong>Dry Run Complete!</strong> Review the results below. No changes were made to the database.</p>
                </div>
            <?php elseif ($action === 'migration_complete'): ?>
                <div class="notice notice-success">
                    <p><strong>Migration Complete!</strong> The database has been updated to v4.0.</p>
                </div>
            <?php elseif ($action === 'rollback_complete'): ?>
                <div class="notice notice-warning">
                    <p><strong>Rollback Complete!</strong> The migration has been reverted.</p>
                </div>
            <?php elseif ($action === 'v42_complete'): ?>
                <div class="notice notice-success">
                    <p><strong>Migration v4.2 Complete!</strong> Featured podcasts columns have been added.</p>
                </div>
            <?php endif; ?>

            <!-- Status Section -->
            <div class="card" style="max-width: 800px; padding: 20px; margin-bottom: 20px;">
                <h3>📊 Current Status</h3>
                <table class="widefat" style="max-width: 600px;">
                    <tr>
                        <th>Current Version</th>
                        <td><code><?php echo esc_html($status['current_version']); ?></code></td>
                    </tr>
                    <tr>
                        <th>Target Version</th>
                        <td><code><?php echo esc_html($status['target_version']); ?></code></td>
                    </tr>
                    <tr>
                        <th>Needs Migration</th>
                        <td>
                            <?php if ($status['needs_migration']): ?>
                                <span style="color: orange;">⚠️ Yes</span>
                            <?php else: ?>
                                <span style="color: green;">✅ No - Already up to date</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (isset($status['appearances_to_migrate'])): ?>
                    <tr>
                        <th>Appearances to Migrate</th>
                        <td><?php echo number_format($status['appearances_to_migrate']); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>

                <h4>Table Status</h4>
                <table class="widefat" style="max-width: 600px;">
                    <thead>
                        <tr>
                            <th>Table</th>
                            <th>Exists</th>
                            <th>Rows</th>
                            <th>New Columns</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($status['tables'] as $table => $info): ?>
                        <tr>
                            <td><code><?php echo esc_html($table); ?></code></td>
                            <td><?php echo $info['exists'] ? '✅' : '❌'; ?></td>
                            <td><?php echo $info['exists'] ? number_format($info['row_count']) : '-'; ?></td>
                            <td>
                                <?php 
                                if (isset($info['has_new_columns'])) {
                                    echo $info['has_new_columns'] ? '✅' : '⚠️ Missing';
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Migration Actions -->
            <div class="card" style="max-width: 800px; padding: 20px; margin-bottom: 20px;">
                <h3>🔧 Migration Actions</h3>
                
                <form method="post" style="display: inline-block; margin-right: 10px;">
                    <?php wp_nonce_field('pit_migration_v4'); ?>
                    <button type="submit" name="pit_migration_dry_run" class="button button-secondary">
                        🔍 Dry Run (Preview Changes)
                    </button>
                </form>

                <form method="post" style="display: inline-block; margin-right: 10px;">
                    <?php wp_nonce_field('pit_migration_v4'); ?>
                    <button type="submit" name="pit_migration_execute" class="button button-primary" 
                            onclick="return confirm('Are you sure you want to run the migration? This will modify the database.');">
                        🚀 Execute Migration
                    </button>
                </form>

                <form method="post" style="display: inline-block;">
                    <?php wp_nonce_field('pit_migration_v4'); ?>
                    <button type="submit" name="pit_migration_rollback" class="button" style="color: #d63638;"
                            onclick="return confirm('WARNING: This will drop new tables and revert schema changes. Are you sure?');">
                        ⚠️ Rollback
                    </button>
                </form>
            </div>

            <!-- Migration v4.2: Featured Podcasts -->
            <?php
            $v42_status = class_exists('PIT_Schema_Migration_V4_2') ? PIT_Schema_Migration_V4_2::get_status() : null;
            $v42_results = get_transient('pit_migration_v42_results');
            ?>
            <?php if ($v42_status): ?>
            <div class="card" style="max-width: 800px; padding: 20px; margin-bottom: 20px;">
                <h3>⭐ Migration v4.2: Featured Podcasts</h3>
                <p>Adds the ability to mark portfolio items as "featured" for your media kit.</p>

                <table class="widefat" style="max-width: 400px; margin-bottom: 15px;">
                    <tr>
                        <th>Current Version</th>
                        <td><code><?php echo esc_html($v42_status['current_version']); ?></code></td>
                    </tr>
                    <tr>
                        <th>Target Version</th>
                        <td><code><?php echo esc_html($v42_status['target_version']); ?></code></td>
                    </tr>
                    <tr>
                        <th>is_featured column</th>
                        <td><?php echo $v42_status['has_is_featured'] ? '✅ Exists' : '❌ Missing'; ?></td>
                    </tr>
                    <tr>
                        <th>featured_at column</th>
                        <td><?php echo $v42_status['has_featured_at'] ? '✅ Exists' : '❌ Missing'; ?></td>
                    </tr>
                    <?php if ($v42_status['has_is_featured']): ?>
                    <tr>
                        <th>Featured Items</th>
                        <td><?php echo number_format($v42_status['total_featured']); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>

                <?php if ($v42_status['needs_migration']): ?>
                <form method="post" style="display: inline-block;">
                    <?php wp_nonce_field('pit_migration_v4'); ?>
                    <button type="submit" name="pit_migration_v42_execute" class="button button-primary">
                        🚀 Run Migration v4.2
                    </button>
                </form>
                <?php else: ?>
                <p style="color: green;">✅ Migration v4.2 is already complete.</p>
                <?php endif; ?>

                <?php if ($v42_results): ?>
                <h4>Migration Results</h4>
                <table class="widefat" style="max-width: 600px;">
                    <thead>
                        <tr><th>Step</th><th>Status</th><th>Message</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($v42_results['steps_completed'] ?? [] as $step): ?>
                        <tr>
                            <td><code><?php echo esc_html($step['step'] ?? 'unknown'); ?></code></td>
                            <td><?php echo esc_html($step['status'] ?? ''); ?></td>
                            <td><?php echo esc_html($step['message'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Results Section -->
            <?php if ($results): ?>
            <div class="card" style="max-width: 800px; padding: 20px; margin-bottom: 20px;">
                <h3>📋 <?php echo $results['dry_run'] ? 'Dry Run' : 'Migration'; ?> Results</h3>
                
                <?php if (!empty($results['errors'])): ?>
                <div class="notice notice-error inline">
                    <p><strong>Errors:</strong></p>
                    <ul>
                        <?php foreach ($results['errors'] as $error): ?>
                        <li><?php echo esc_html($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <table class="widefat">
                    <tr><th>Opportunities Created</th><td><?php echo number_format($results['opportunities_created'] ?? 0); ?></td></tr>
                    <tr><th>Engagements Created</th><td><?php echo number_format($results['engagements_created'] ?? 0); ?></td></tr>
                    <tr><th>Speaking Credits Created</th><td><?php echo number_format($results['speaking_credits_created'] ?? 0); ?></td></tr>
                    <tr><th>Private Contacts Migrated</th><td><?php echo number_format($results['private_contacts_migrated'] ?? 0); ?></td></tr>
                    <tr><th>Duplicate Groups Found</th><td><?php echo number_format($results['duplicates_found'] ?? 0); ?></td></tr>
                </table>

                <h4>Steps Completed</h4>
                <table class="widefat">
                    <thead>
                        <tr><th>Step</th><th>Status</th><th>Details</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results['steps_completed'] ?? [] as $step): ?>
                        <tr>
                            <td><code><?php echo esc_html($step['step'] ?? 'unknown'); ?></code></td>
                            <td>
                                <?php 
                                $status_val = $step['status'] ?? '';
                                $status_icon = match($status_val) {
                                    'completed' => '✅',
                                    'would_run' => '🔍',
                                    'skipped' => '⏭️',
                                    'failed' => '❌',
                                    default => '❓'
                                };
                                echo $status_icon . ' ' . esc_html($status_val ?: 'unknown');
                                ?>
                            </td>
                            <td>
                                <?php 
                                echo esc_html($step['message'] ?? '');
                                // Show additional details
                                if (isset($step['opportunities'])) {
                                    echo " (Opportunities: {$step['opportunities']}, Engagements: {$step['engagements']}, Credits: {$step['speaking_credits']})";
                                }
                                if (isset($step['count']) && !isset($step['opportunities'])) {
                                    echo " (Count: {$step['count']})";
                                }
                                if (!empty($step['error'])) {
                                    echo '<br><span style="color:red;">Error: ' . esc_html($step['error']) . '</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (!empty($results['duplicate_groups'])): ?>
                <h4>Duplicate Groups Found</h4>
                <table class="widefat">
                    <thead>
                        <tr><th>Type</th><th>Hash</th><th>Guest IDs</th><th>Count</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($results['duplicate_groups'], 0, 10) as $group): ?>
                        <tr>
                            <td><?php echo esc_html($group['type']); ?></td>
                            <td><code style="font-size: 10px;"><?php echo esc_html(substr($group['hash'], 0, 12) . '...'); ?></code></td>
                            <td><?php echo esc_html($group['ids']); ?></td>
                            <td><?php echo esc_html($group['count']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (count($results['duplicate_groups']) > 10): ?>
                <p><em>Showing first 10 of <?php echo count($results['duplicate_groups']); ?> duplicate groups.</em></p>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Duplicate Guests Section -->
            <?php if (!empty($duplicates)): ?>
            <div class="card" style="max-width: 1000px; padding: 20px; margin-bottom: 20px;">
                <h3>👥 Duplicate Guests (<?php echo count($duplicates); ?> groups)</h3>
                <p>These guests may be duplicates. Review and merge as needed.</p>

                <table class="widefat">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Key</th>
                            <th>Names</th>
                            <th>IDs</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($duplicates, 0, 20) as $group): ?>
                        <tr>
                            <td>
                                <span class="dashicons dashicons-<?php echo $group['type'] === 'email' ? 'email' : 'linkedin'; ?>"></span>
                                <?php echo esc_html($group['type']); ?>
                            </td>
                            <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis;">
                                <?php echo esc_html($group['key']); ?>
                            </td>
                            <td style="max-width: 300px;">
                                <?php echo esc_html($group['names']); ?>
                            </td>
                            <td>
                                <?php echo esc_html(implode(', ', $group['ids'])); ?>
                                <br><small>Suggested master: <strong><?php echo esc_html($group['suggested_master']); ?></strong></small>
                            </td>
                            <td>
                                <button type="button" class="button button-small pit-merge-btn" 
                                        data-master="<?php echo esc_attr($group['suggested_master']); ?>"
                                        data-duplicates="<?php echo esc_attr(json_encode(array_slice($group['ids'], 1))); ?>">
                                    Merge All → <?php echo esc_html($group['suggested_master']); ?>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (count($duplicates) > 20): ?>
                <p><em>Showing first 20 of <?php echo count($duplicates); ?> duplicate groups.</em></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- What This Migration Does -->
            <div class="card" style="max-width: 800px; padding: 20px;">
                <h3>📚 What This Migration Does</h3>
                
                <h4>1. Modifies <code>pit_guests</code> table</h4>
                <ul>
                    <li>Adds <code>claimed_by_user_id</code> - Links guest profile to WordPress user (identity)</li>
                    <li>Adds <code>claim_status</code> - unclaimed/pending/verified/rejected</li>
                    <li>Adds <code>claim_verified_at</code>, <code>claim_verification_method</code></li>
                    <li>Renames <code>user_id</code> → <code>created_by_user_id</code> (provenance tracking)</li>
                </ul>

                <h4>2. Creates <code>pit_guest_private_contacts</code> table</h4>
                <p>User-owned private contact info (phone, personal email) - not shared globally.</p>

                <h4>3. Creates <code>pit_claim_requests</code> table</h4>
                <p>Workflow for users to claim their own guest profiles.</p>

                <h4>4. Creates <code>pit_opportunities</code> table</h4>
                <p>CRM pipeline (replaces pit_guest_appearances for workflow data).</p>

                <h4>5. Creates <code>pit_engagements</code> table</h4>
                <p>Global public record of speaking engagements.</p>

                <h4>6. Creates <code>pit_speaking_credits</code> table</h4>
                <p>Links guests to engagements (enables network graph).</p>

                <h4>7. Creates <code>pit_pipeline_stages</code> table</h4>
                <p>Customizable pipeline stages for the CRM workflow.</p>

                <h4>8. Migrates existing data</h4>
                <ul>
                    <li>Appearances → Opportunities (all statuses)</li>
                    <li>Aired appearances → Engagements + Speaking Credits</li>
                    <li>Private contact fields → pit_guest_private_contacts</li>
                </ul>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('.pit-merge-btn').on('click', function() {
                var btn = $(this);
                var master = btn.data('master');
                var duplicates = btn.data('duplicates');
                
                if (!confirm('Merge guests ' + duplicates.join(', ') + ' into guest #' + master + '?')) {
                    return;
                }
                
                btn.prop('disabled', true).text('Merging...');
                
                $.post(ajaxurl, {
                    action: 'pit_merge_guests',
                    master_id: master,
                    duplicate_ids: duplicates,
                    _wpnonce: '<?php echo wp_create_nonce('pit_merge_guests'); ?>'
                }, function(response) {
                    if (response.success) {
                        btn.closest('tr').fadeOut();
                    } else {
                        alert('Merge failed: ' + response.data);
                        btn.prop('disabled', false).text('Retry');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: Get migration status
     */
    public static function ajax_status() {
        check_ajax_referer('pit_migration_v4', '_wpnonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        wp_send_json_success(PIT_Schema_Migration_V4::get_status());
    }

    /**
     * AJAX: Run migration
     */
    public static function ajax_run() {
        check_ajax_referer('pit_migration_v4', '_wpnonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $dry_run = isset($_POST['dry_run']) && $_POST['dry_run'] === 'true';

        $results = PIT_Schema_Migration_V4::run($dry_run);
        
        wp_send_json_success($results);
    }

    /**
     * AJAX: Merge guests
     */
    public static function ajax_merge_guests() {
        check_ajax_referer('pit_merge_guests', '_wpnonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $master_id = isset($_POST['master_id']) ? (int) $_POST['master_id'] : 0;
        $duplicate_ids = isset($_POST['duplicate_ids']) ? array_map('intval', (array) $_POST['duplicate_ids']) : [];

        if (!$master_id || empty($duplicate_ids)) {
            wp_send_json_error('Invalid parameters');
        }

        $results = [];
        foreach ($duplicate_ids as $dup_id) {
            $results[] = PIT_Guest_Merge_Helper::execute_merge($master_id, $dup_id, false);
        }

        wp_send_json_success($results);
    }
}
