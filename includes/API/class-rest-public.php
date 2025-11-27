<?php
/**
 * Public REST API Controller
 *
 * Read-only public endpoints for headless frontend access.
 * No authentication required, but rate-limited by IP.
 *
 * @package PodcastInfluenceTracker
 * @subpackage API
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_Public extends PIT_REST_Base {

    /**
     * Register public routes
     */
    public static function register_routes() {
        // Podcasts endpoints
        register_rest_route(self::NAMESPACE . '/public', '/podcasts', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_podcasts'],
            'permission_callback' => '__return_true', // Public
            'args' => self::get_podcasts_query_args(),
        ]);

        register_rest_route(self::NAMESPACE . '/public', '/podcasts/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_podcast'],
            'permission_callback' => '__return_true', // Public
        ]);

        // Guests endpoints
        register_rest_route(self::NAMESPACE . '/public', '/guests', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_guests'],
            'permission_callback' => '__return_true', // Public
            'args' => self::get_guests_query_args(),
        ]);

        register_rest_route(self::NAMESPACE . '/public', '/guests/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_guest'],
            'permission_callback' => '__return_true', // Public
        ]);

        // Episodes for a podcast
        register_rest_route(self::NAMESPACE . '/public', '/podcasts/(?P<podcast_id>\d+)/episodes', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_podcast_episodes'],
            'permission_callback' => '__return_true', // Public
        ]);

        // Social metrics for a podcast
        register_rest_route(self::NAMESPACE . '/public', '/podcasts/(?P<podcast_id>\d+)/metrics', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_podcast_metrics'],
            'permission_callback' => '__return_true', // Public
        ]);

        // Search endpoint
        register_rest_route(self::NAMESPACE . '/public', '/search', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'search'],
            'permission_callback' => '__return_true', // Public
            'args' => [
                'q' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Search query',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => ['podcasts', 'guests', 'all'],
                    'default' => 'all',
                ],
            ],
        ]);
    }

    /**
     * Get podcasts list
     */
    public static function get_podcasts($request) {
        $page = $request->get_param('page') ?: 1;
        $per_page = min($request->get_param('per_page') ?: 20, 100);
        $search = $request->get_param('search');
        $category = $request->get_param('category');

        $args = [
            'page' => $page,
            'per_page' => $per_page,
            'is_tracked' => 1, // Only show tracked podcasts publicly
        ];

        if ($search) {
            $args['search'] = $search;
        }

        if ($category) {
            $args['category'] = $category;
        }

        $podcasts = PIT_Podcast_Repository::find($args);
        $total = PIT_Podcast_Repository::count($args);

        return new WP_REST_Response([
            'podcasts' => array_map([__CLASS__, 'format_podcast'], $podcasts),
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page),
        ]);
    }

    /**
     * Get single podcast
     */
    public static function get_podcast($request) {
        $podcast_id = $request->get_param('id');
        $podcast = PIT_Podcast_Repository::get($podcast_id);

        if (!$podcast || !$podcast->is_tracked) {
            return self::error('not_found', 'Podcast not found', 404);
        }

        return new WP_REST_Response([
            'podcast' => self::format_podcast_detail($podcast),
        ]);
    }

    /**
     * Get guests list
     */
    public static function get_guests($request) {
        $page = $request->get_param('page') ?: 1;
        $per_page = min($request->get_param('per_page') ?: 20, 100);
        $search = $request->get_param('search');
        $verified = $request->get_param('verified');

        $args = [
            'page' => $page,
            'per_page' => $per_page,
        ];

        if ($search) {
            $args['search'] = $search;
        }

        if ($verified !== null) {
            $args['is_verified'] = (bool) $verified;
        }

        $guests = PIT_Guest_Repository::find($args);
        $total = PIT_Guest_Repository::count($args);

        return new WP_REST_Response([
            'guests' => array_map([__CLASS__, 'format_guest'], $guests),
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page),
        ]);
    }

    /**
     * Get single guest
     */
    public static function get_guest($request) {
        $guest_id = $request->get_param('id');
        $guest = PIT_Guest_Repository::get($guest_id);

        if (!$guest) {
            return self::error('not_found', 'Guest not found', 404);
        }

        return new WP_REST_Response([
            'guest' => self::format_guest_detail($guest),
        ]);
    }

    /**
     * Get podcast episodes
     */
    public static function get_podcast_episodes($request) {
        $podcast_id = $request->get_param('podcast_id');
        $podcast = PIT_Podcast_Repository::get($podcast_id);

        if (!$podcast || !$podcast->is_tracked) {
            return self::error('not_found', 'Podcast not found', 404);
        }

        // Get episodes from content analysis table
        global $wpdb;
        $table = $wpdb->prefix . 'pit_content_analysis';

        $episodes = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE podcast_id = %d ORDER BY published_at DESC LIMIT 100",
            $podcast_id
        ));

        return new WP_REST_Response([
            'episodes' => array_map([__CLASS__, 'format_episode'], $episodes),
            'podcast_id' => $podcast_id,
        ]);
    }

    /**
     * Get podcast metrics
     */
    public static function get_podcast_metrics($request) {
        $podcast_id = $request->get_param('podcast_id');
        $podcast = PIT_Podcast_Repository::get($podcast_id);

        if (!$podcast || !$podcast->is_tracked) {
            return self::error('not_found', 'Podcast not found', 404);
        }

        // Get social links
        $social_links = PIT_Social_Link_Repository::get_by_podcast($podcast_id);

        // Get latest metrics for each platform
        $metrics = [];
        foreach ($social_links as $link) {
            $latest = PIT_Metrics_Repository::get_latest($link->id);
            if ($latest) {
                $metrics[$link->platform] = [
                    'platform' => $link->platform,
                    'url' => $link->url,
                    'followers' => $latest->followers,
                    'subscribers' => $latest->subscribers,
                    'views' => $latest->views,
                    'engagement_rate' => $latest->engagement_rate,
                    'last_updated' => $latest->created_at,
                ];
            }
        }

        return new WP_REST_Response([
            'podcast_id' => $podcast_id,
            'metrics' => $metrics,
        ]);
    }

    /**
     * Search across podcasts and guests
     */
    public static function search($request) {
        $query = $request->get_param('q');
        $type = $request->get_param('type');

        $results = [];

        if ($type === 'podcasts' || $type === 'all') {
            $podcasts = PIT_Podcast_Repository::find([
                'search' => $query,
                'is_tracked' => 1,
                'per_page' => 10,
            ]);
            $results['podcasts'] = array_map([__CLASS__, 'format_podcast'], $podcasts);
        }

        if ($type === 'guests' || $type === 'all') {
            $guests = PIT_Guest_Repository::find([
                'search' => $query,
                'per_page' => 10,
            ]);
            $results['guests'] = array_map([__CLASS__, 'format_guest'], $guests);
        }

        return new WP_REST_Response($results);
    }

    /**
     * Format podcast for public display
     */
    private static function format_podcast($podcast) {
        return [
            'id' => (int) $podcast->id,
            'title' => $podcast->title,
            'slug' => $podcast->slug,
            'description' => $podcast->description,
            'author' => $podcast->author,
            'artwork_url' => $podcast->artwork_url,
            'website_url' => $podcast->website_url,
            'category' => $podcast->category,
            'episode_count' => (int) $podcast->episode_count,
            'language' => $podcast->language,
        ];
    }

    /**
     * Format podcast detail (includes more fields)
     */
    private static function format_podcast_detail($podcast) {
        $basic = self::format_podcast($podcast);

        return array_merge($basic, [
            'frequency' => $podcast->frequency,
            'average_duration' => (int) $podcast->average_duration,
            'city' => $podcast->city,
            'state_region' => $podcast->state_region,
            'country' => $podcast->country,
        ]);
    }

    /**
     * Format guest for public display
     */
    private static function format_guest($guest) {
        return [
            'id' => (int) $guest->id,
            'full_name' => $guest->full_name,
            'company' => $guest->company,
            'title' => $guest->title,
            'bio' => $guest->bio,
            'photo_url' => $guest->photo_url,
            'linkedin_url' => $guest->linkedin_url,
            'twitter_handle' => $guest->twitter_handle,
            'is_verified' => (bool) $guest->is_verified,
            'topics' => $guest->topics ? explode(',', $guest->topics) : [],
        ];
    }

    /**
     * Format guest detail (includes appearance count)
     */
    private static function format_guest_detail($guest) {
        $basic = self::format_guest($guest);

        // Get appearance count
        $appearance_count = PIT_Appearance_Repository::count_by_guest($guest->id);

        return array_merge($basic, [
            'website' => $guest->website,
            'appearance_count' => $appearance_count,
        ]);
    }

    /**
     * Format episode for public display
     */
    private static function format_episode($episode) {
        return [
            'id' => (int) $episode->id,
            'podcast_id' => (int) $episode->podcast_id,
            'title' => $episode->episode_title,
            'description' => $episode->episode_description,
            'published_at' => $episode->published_at,
            'duration' => (int) $episode->duration,
            'topics' => $episode->topics ? json_decode($episode->topics, true) : [],
        ];
    }

    /**
     * Query args for podcasts endpoint
     */
    private static function get_podcasts_query_args() {
        return [
            'page' => [
                'type' => 'integer',
                'default' => 1,
                'minimum' => 1,
            ],
            'per_page' => [
                'type' => 'integer',
                'default' => 20,
                'minimum' => 1,
                'maximum' => 100,
            ],
            'search' => [
                'type' => 'string',
            ],
            'category' => [
                'type' => 'string',
            ],
        ];
    }

    /**
     * Query args for guests endpoint
     */
    private static function get_guests_query_args() {
        return [
            'page' => [
                'type' => 'integer',
                'default' => 1,
                'minimum' => 1,
            ],
            'per_page' => [
                'type' => 'integer',
                'default' => 20,
                'minimum' => 1,
                'maximum' => 100,
            ],
            'search' => [
                'type' => 'string',
            ],
            'verified' => [
                'type' => 'boolean',
            ],
        ];
    }
}
