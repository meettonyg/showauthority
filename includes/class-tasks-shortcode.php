<?php
/**
 * Tasks Dashboard Shortcode
 *
 * Renders the Vue.js Tasks Dashboard showing all tasks across appearances.
 * URL: /app/tasks/
 *
 * @package Podcast_Influence_Tracker
 * @since 3.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Tasks_Shortcode {

    /**
     * Initialize the shortcode
     */
    public static function init() {
        add_shortcode('guestify_tasks', [__CLASS__, 'render']);
    }

    /**
     * Render the tasks dashboard
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public static function render($atts) {
        if (!is_user_logged_in()) {
            return '<div class="pit-error"><p>' . esc_html__('Please log in to access tasks.', 'podcast-influence-tracker') . '</p></div>';
        }

        $atts = shortcode_atts([
            'view'   => 'list',    // 'list', 'board', 'widget'
            'status' => '',        // Filter by status
            'limit'  => 50,        // Max items for widget view
        ], $atts);

        // Enqueue scripts and styles
        self::enqueue_assets($atts);

        ob_start();
        ?>
        <div id="tasks-app"
             class="pit-tasks-page"
             data-view="<?php echo esc_attr($atts['view']); ?>"
             data-status="<?php echo esc_attr($atts['status']); ?>"
             data-limit="<?php echo esc_attr($atts['limit']); ?>">
            <div class="pit-loading">
                <div class="pit-loading-spinner"></div>
                <p><?php esc_html_e('Loading tasks...', 'podcast-influence-tracker'); ?></p>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Enqueue required scripts and styles
     */
    private static function enqueue_assets($atts) {
        // Enqueue Vue, Pinia, and shared API (uses local vendor files if available)
        PIT_Vue_Scripts::enqueue();

        // Tasks App
        wp_enqueue_script(
            'pit-tasks',
            PIT_PLUGIN_URL . 'assets/js/tasks-vue.js',
            PIT_Vue_Scripts::get_dependencies(),
            PIT_VERSION,
            true
        );

        // Tasks CSS
        wp_enqueue_style(
            'pit-tasks',
            PIT_PLUGIN_URL . 'assets/css/tasks.css',
            [],
            PIT_VERSION
        );

        // Localize script data with translations
        wp_localize_script('pit-tasks', 'pitTasksData', [
            'restUrl'            => rest_url('guestify/v1/'),
            'nonce'              => wp_create_nonce('wp_rest'),
            'userId'             => get_current_user_id(),
            'isAdmin'            => current_user_can('manage_options'),
            'interviewDetailUrl' => '/app/interview/detail/',
            'defaultView'        => $atts['view'],
            'defaultStatus'      => $atts['status'],
            'defaultLimit'       => (int) $atts['limit'],
            'i18n'               => self::get_translations(),
        ]);
    }

    /**
     * Get translations for JavaScript
     */
    private static function get_translations() {
        return [
            // Header
            'tasks'              => __('Tasks', 'podcast-influence-tracker'),
            'addTask'            => __('Add Task', 'podcast-influence-tracker'),

            // Stats
            'totalTasks'         => __('Total Tasks', 'podcast-influence-tracker'),
            'pending'            => __('Pending', 'podcast-influence-tracker'),
            'inProgress'         => __('In Progress', 'podcast-influence-tracker'),
            'overdue'            => __('Overdue', 'podcast-influence-tracker'),
            'completed'          => __('Completed', 'podcast-influence-tracker'),
            'cancelled'          => __('Cancelled', 'podcast-influence-tracker'),

            // Filters
            'searchTasks'        => __('Search tasks...', 'podcast-influence-tracker'),
            'allStatuses'        => __('All Statuses', 'podcast-influence-tracker'),
            'allPriorities'      => __('All Priorities', 'podcast-influence-tracker'),
            'newestFirst'        => __('Newest First', 'podcast-influence-tracker'),
            'dueDate'            => __('Due Date', 'podcast-influence-tracker'),
            'priority'           => __('Priority', 'podcast-influence-tracker'),
            'clearFilters'       => __('Clear Filters', 'podcast-influence-tracker'),

            // Priorities
            'urgent'             => __('Urgent', 'podcast-influence-tracker'),
            'high'               => __('High', 'podcast-influence-tracker'),
            'medium'             => __('Medium', 'podcast-influence-tracker'),
            'low'                => __('Low', 'podcast-influence-tracker'),

            // Dates
            'today'              => __('Today', 'podcast-influence-tracker'),
            'tomorrow'           => __('Tomorrow', 'podcast-influence-tracker'),
            'yesterday'          => __('Yesterday', 'podcast-influence-tracker'),
            'daysAgo'            => __('%d days ago', 'podcast-influence-tracker'),
            'inDays'             => __('In %d days', 'podcast-influence-tracker'),

            // States
            'loadingTasks'       => __('Loading tasks...', 'podcast-influence-tracker'),
            'noTasksMatch'       => __('No tasks match your filters.', 'podcast-influence-tracker'),
            'noTasksYet'         => __('No tasks yet. Tasks will appear here when you add them to your appearances.', 'podcast-influence-tracker'),
            'failedToLoad'       => __('Failed to load tasks. Please try again.', 'podcast-influence-tracker'),
            'tryAgain'           => __('Try Again', 'podcast-influence-tracker'),

            // Pagination
            'previous'           => __('Previous', 'podcast-influence-tracker'),
            'next'               => __('Next', 'podcast-influence-tracker'),
            'pageOf'             => __('Page %1$d of %2$d', 'podcast-influence-tracker'),

            // Task Creation Modal
            'interview'          => __('Interview', 'podcast-influence-tracker'),
            'selectInterview'    => __('Select an interview...', 'podcast-influence-tracker'),
            'loading'            => __('Loading...', 'podcast-influence-tracker'),
            'taskTitle'          => __('Task Title', 'podcast-influence-tracker'),
            'enterTaskTitle'     => __('Enter task title...', 'podcast-influence-tracker'),
            'description'        => __('Description', 'podcast-influence-tracker'),
            'enterDescription'   => __('Enter task description (optional)...', 'podcast-influence-tracker'),
            'taskType'           => __('Task Type', 'podcast-influence-tracker'),
            'typeTodo'           => __('To Do', 'podcast-influence-tracker'),
            'typeFollowUp'       => __('Follow Up', 'podcast-influence-tracker'),
            'typePrep'           => __('Preparation', 'podcast-influence-tracker'),
            'typeOutreach'       => __('Outreach', 'podcast-influence-tracker'),
            'typeReview'         => __('Review', 'podcast-influence-tracker'),
            'cancel'             => __('Cancel', 'podcast-influence-tracker'),
            'createTask'         => __('Create Task', 'podcast-influence-tracker'),
            'creating'           => __('Creating...', 'podcast-influence-tracker'),
            'titleRequired'      => __('Task title is required.', 'podcast-influence-tracker'),
            'appearanceRequired' => __('Please select an interview.', 'podcast-influence-tracker'),
            'failedToCreate'     => __('Failed to create task. Please try again.', 'podcast-influence-tracker'),
        ];
    }
}
