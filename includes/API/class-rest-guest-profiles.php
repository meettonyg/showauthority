<?php
/**
 * REST API: Guest Profiles Endpoint
 *
 * Provides REST API endpoints for managing guest profiles (Pods CPT: guests).
 *
 * @package Podcast_Influence_Tracker
 * @since 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Guest_Profiles {

    /**
     * Namespace for the REST API.
     *
     * @var string
     */
    private $namespace = 'guestify/v1';

    /**
     * Post type for guest profiles.
     *
     * @var string
     */
    private $post_type = 'guests';

    /**
     * Constructor.
     */
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST API routes.
     */
    public function register_routes() {
        // GET /guest-profiles - List all guest profiles for current user
        register_rest_route($this->namespace, '/guest-profiles', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_profiles'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'per_page' => [
                    'default'           => 50,
                    'sanitize_callback' => 'absint',
                ],
                'page' => [
                    'default'           => 1,
                    'sanitize_callback' => 'absint',
                ],
                'search' => [
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'orderby' => [
                    'default'           => 'title',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'order' => [
                    'default'           => 'ASC',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // GET /guest-profiles/{id} - Get single guest profile
        register_rest_route($this->namespace, '/guest-profiles/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_profile'],
            'permission_callback' => [$this, 'check_permission'],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    },
                ],
            ],
        ]);
    }

    /**
     * Check if user has permission.
     *
     * @return bool|WP_Error
     */
    public function check_permission() {
        if (!is_user_logged_in()) {
            return new WP_Error(
                'rest_forbidden',
                __('You must be logged in to access guest profiles.', 'flavor-flavor'),
                ['status' => 401]
            );
        }
        return true;
    }

    /**
     * Get list of guest profiles.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function get_profiles($request) {
        $current_user_id = get_current_user_id();
        $per_page = $request->get_param('per_page');
        $page = $request->get_param('page');
        $search = $request->get_param('search');
        $orderby = $request->get_param('orderby');
        $order = strtoupper($request->get_param('order')) === 'DESC' ? 'DESC' : 'ASC';

        // Build query args
        $args = [
            'post_type'      => $this->post_type,
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => $orderby,
            'order'          => $order,
        ];

        // Filter by author (current user) unless admin
        if (!current_user_can('manage_options')) {
            $args['author'] = $current_user_id;
        }

        // Add search
        if (!empty($search)) {
            $args['s'] = $search;
        }

        $query = new WP_Query($args);
        $profiles = [];

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                $profiles[] = $this->format_profile($post_id);
            }
            wp_reset_postdata();
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $profiles,
            'total'   => $query->found_posts,
            'pages'   => $query->max_num_pages,
            'page'    => $page,
        ]);
    }

    /**
     * Get single guest profile.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public function get_profile($request) {
        $post_id = (int) $request->get_param('id');
        $post = get_post($post_id);

        if (!$post || $post->post_type !== $this->post_type) {
            return new WP_Error(
                'not_found',
                __('Guest profile not found.', 'flavor-flavor'),
                ['status' => 404]
            );
        }

        // Check ownership unless admin
        if (!current_user_can('manage_options') && (int) $post->post_author !== get_current_user_id()) {
            return new WP_Error(
                'forbidden',
                __('You do not have permission to view this profile.', 'flavor-flavor'),
                ['status' => 403]
            );
        }

        return rest_ensure_response([
            'success' => true,
            'data'    => $this->format_profile($post_id, true),
        ]);
    }

    /**
     * Format profile data for response.
     *
     * @param int  $post_id   Post ID.
     * @param bool $full      Include full details.
     * @return array
     */
    private function format_profile($post_id, $full = false) {
        $post = get_post($post_id);
        
        // Get basic fields
        $first_name = get_post_meta($post_id, 'first_name', true);
        $last_name = get_post_meta($post_id, 'last_name', true);
        $full_name = get_post_meta($post_id, 'full_name', true);
        
        // Use full_name if set, otherwise construct from first/last
        $display_name = !empty($full_name) ? $full_name : trim($first_name . ' ' . $last_name);
        
        // Fallback to post title if no name fields
        if (empty($display_name)) {
            $display_name = $post->post_title;
        }

        $data = [
            'id'           => $post_id,
            'name'         => $display_name,
            'title'        => $post->post_title,
            'first_name'   => $first_name,
            'last_name'    => $last_name,
            'full_name'    => $full_name,
            'email'        => get_post_meta($post_id, 'email', true),
            'company'      => get_post_meta($post_id, 'company', true),
            'guest_title'  => get_post_meta($post_id, 'guest_title', true),
            'thumbnail'    => get_the_post_thumbnail_url($post_id, 'thumbnail'),
        ];

        // Add full details if requested
        if ($full) {
            $data = array_merge($data, [
                'biography'    => get_post_meta($post_id, 'biography', true),
                'tagline'      => get_post_meta($post_id, 'tagline', true),
                'introduction' => get_post_meta($post_id, 'introduction', true),
                'skype'        => get_post_meta($post_id, 'skype', true),
                'org_id'       => get_post_meta($post_id, 'org_id', true),
                'highlevel_contact_id' => get_post_meta($post_id, 'highlevel_contact_id', true),
                'links'        => [
                    'facebook'  => get_post_meta($post_id, '1_facebook', true),
                    'instagram' => get_post_meta($post_id, '1_instagram', true),
                    'linkedin'  => get_post_meta($post_id, '1_linkedin', true),
                    'pinterest' => get_post_meta($post_id, '1_pinterest', true),
                    'tiktok'    => get_post_meta($post_id, '1_tiktok', true),
                    'twitter'   => get_post_meta($post_id, '1_twitter', true),
                    'youtube'   => get_post_meta($post_id, 'guest_youtube', true),
                    'website1'  => get_post_meta($post_id, '1_website', true),
                    'website2'  => get_post_meta($post_id, '2_website', true),
                ],
                'topics' => [
                    get_post_meta($post_id, 'topic_1', true),
                    get_post_meta($post_id, 'topic_2', true),
                    get_post_meta($post_id, 'topic_3', true),
                    get_post_meta($post_id, 'topic_4', true),
                    get_post_meta($post_id, 'topic_5', true),
                ],
                'hook' => [
                    'when'  => get_post_meta($post_id, 'hook_when', true),
                    'what'  => get_post_meta($post_id, 'hook_what', true),
                    'how'   => get_post_meta($post_id, 'hook_how', true),
                    'where' => get_post_meta($post_id, 'hook_where', true),
                    'why'   => get_post_meta($post_id, 'hook_why', true),
                ],
                'video_intro' => get_post_meta($post_id, 'video_intro', true),
                'profile_photo' => $this->get_attachment_url($post_id, 'profile_photo'),
                'created_at'  => $post->post_date,
                'updated_at'  => $post->post_modified,
            ]);
        }

        return $data;
    }

    /**
     * Get attachment URL from meta.
     *
     * @param int    $post_id Post ID.
     * @param string $key     Meta key.
     * @return string|null
     */
    private function get_attachment_url($post_id, $key) {
        $attachment_id = get_post_meta($post_id, $key, true);
        if (!empty($attachment_id)) {
            return wp_get_attachment_url($attachment_id);
        }
        return null;
    }
}

// Initialize
new PIT_REST_Guest_Profiles();
