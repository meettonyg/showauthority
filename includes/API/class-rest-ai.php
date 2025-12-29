<?php
/**
 * AI REST API Endpoints
 *
 * Provides AI-powered email refinement and generation with cost tracking.
 * Uses OpenAI API for content generation.
 *
 * @package PodcastInfluenceTracker
 * @subpackage API
 * @since 5.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_REST_AI {

    /**
     * API namespace
     */
    const NAMESPACE = 'guestify/v1';

    /**
     * OpenAI model to use
     */
    const OPENAI_MODEL = 'gpt-4o-mini';

    /**
     * Cost per 1K input tokens (approximate)
     */
    const COST_PER_1K_INPUT = 0.00015;

    /**
     * Cost per 1K output tokens (approximate)
     */
    const COST_PER_1K_OUTPUT = 0.0006;

    /**
     * Register routes
     */
    public static function register_routes() {
        // Refine email content
        register_rest_route(self::NAMESPACE, '/pit-ai/refine', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [__CLASS__, 'refine_email'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args'                => [
                'subject' => [
                    'type'        => 'string',
                    'default'     => '',
                ],
                'body' => [
                    'type'        => 'string',
                    'required'    => true,
                    'description' => 'Email body to refine',
                ],
                'instruction' => [
                    'type'        => 'string',
                    'required'    => true,
                    'description' => 'Refinement instruction',
                ],
                'appearance_id' => [
                    'type'        => 'integer',
                    'default'     => null,
                    'description' => 'Appearance ID for context',
                ],
            ],
        ]);

        // Generate email from scratch
        register_rest_route(self::NAMESPACE, '/pit-ai/generate', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [__CLASS__, 'generate_email'],
            'permission_callback' => [__CLASS__, 'check_permission'],
            'args'                => [
                'appearance_id' => [
                    'type'        => 'integer',
                    'required'    => true,
                    'description' => 'Appearance ID for context',
                ],
                'purpose' => [
                    'type'        => 'string',
                    'required'    => true,
                    'description' => 'Email purpose (pitch, follow_up, thank_you)',
                ],
                'additional_context' => [
                    'type'        => 'string',
                    'default'     => '',
                    'description' => 'Additional instructions',
                ],
            ],
        ]);

        // Get AI usage stats
        register_rest_route(self::NAMESPACE, '/pit-ai/usage', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'get_usage'],
            'permission_callback' => [__CLASS__, 'check_permission'],
        ]);
    }

    /**
     * Check if user has permission
     */
    public static function check_permission() {
        return is_user_logged_in();
    }

    /**
     * Refine email content
     */
    public static function refine_email(WP_REST_Request $request) {
        $subject     = $request->get_param('subject') ?? '';
        $body        = $request->get_param('body');
        $instruction = $request->get_param('instruction');
        $appearance_id = $request->get_param('appearance_id');

        if (empty($body)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Email body is required',
            ], 400);
        }

        // Get OpenAI API key
        $api_key = get_option('pit_openai_api_key', '');
        if (empty($api_key)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'AI features are not configured. Please set up your OpenAI API key in settings.',
            ], 400);
        }

        // Build system prompt
        $system_prompt = self::get_refine_system_prompt();

        // Build user prompt
        $user_prompt = self::build_refine_prompt($subject, $body, $instruction);

        // Call OpenAI
        $result = self::call_openai($api_key, $system_prompt, $user_prompt);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $result->get_error_message(),
            ], 500);
        }

        // Log cost
        self::log_cost(
            $result['input_tokens'],
            $result['output_tokens'],
            $appearance_id,
            'refine'
        );

        // Parse the response
        $parsed = self::parse_email_response($result['content']);

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'subject' => $parsed['subject'] ?: $subject,
                'body'    => $parsed['body'],
            ],
        ], 200);
    }

    /**
     * Generate email from scratch
     */
    public static function generate_email(WP_REST_Request $request) {
        $appearance_id     = $request->get_param('appearance_id');
        $purpose           = $request->get_param('purpose');
        $additional_context = $request->get_param('additional_context') ?? '';

        // Get OpenAI API key
        $api_key = get_option('pit_openai_api_key', '');
        if (empty($api_key)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'AI features are not configured. Please set up your OpenAI API key in settings.',
            ], 400);
        }

        // Get appearance context
        $context = self::get_appearance_context($appearance_id);

        // Build prompts
        $system_prompt = self::get_generate_system_prompt();
        $user_prompt = self::build_generate_prompt($context, $purpose, $additional_context);

        // Call OpenAI
        $result = self::call_openai($api_key, $system_prompt, $user_prompt);

        if (is_wp_error($result)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => $result->get_error_message(),
            ], 500);
        }

        // Log cost
        self::log_cost(
            $result['input_tokens'],
            $result['output_tokens'],
            $appearance_id,
            'generate'
        );

        // Parse the response
        $parsed = self::parse_email_response($result['content']);

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'subject' => $parsed['subject'],
                'body'    => $parsed['body'],
            ],
        ], 200);
    }

    /**
     * Get AI usage stats
     */
    public static function get_usage(WP_REST_Request $request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'pit_cost_log';

        // Total stats
        $total = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total_requests,
                COALESCE(SUM(cost_usd), 0) as total_cost
             FROM {$table}
             WHERE user_id = %d AND action_type = 'ai_generation'",
            $user_id
        ));

        // This month stats
        $month_start = date('Y-m-01 00:00:00');
        $monthly = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as requests_this_month,
                COALESCE(SUM(cost_usd), 0) as cost_this_month
             FROM {$table}
             WHERE user_id = %d
               AND action_type = 'ai_generation'
               AND logged_at >= %s",
            $user_id,
            $month_start
        ));

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'total_requests'      => (int) ($total->total_requests ?? 0),
                'total_cost'          => (float) ($total->total_cost ?? 0),
                'requests_this_month' => (int) ($monthly->requests_this_month ?? 0),
                'cost_this_month'     => (float) ($monthly->cost_this_month ?? 0),
            ],
        ], 200);
    }

    /**
     * Call OpenAI API
     */
    private static function call_openai($api_key, $system_prompt, $user_prompt) {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'model'    => self::OPENAI_MODEL,
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user', 'content' => $user_prompt],
                ],
                'temperature' => 0.7,
                'max_tokens'  => 2000,
            ]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200) {
            $error_message = $body['error']['message'] ?? 'Unknown OpenAI API error';
            return new WP_Error('openai_error', $error_message);
        }

        return [
            'content'       => $body['choices'][0]['message']['content'] ?? '',
            'input_tokens'  => $body['usage']['prompt_tokens'] ?? 0,
            'output_tokens' => $body['usage']['completion_tokens'] ?? 0,
        ];
    }

    /**
     * Log AI cost to database
     */
    private static function log_cost($input_tokens, $output_tokens, $appearance_id, $action) {
        global $wpdb;

        $cost = ($input_tokens / 1000 * self::COST_PER_1K_INPUT) +
                ($output_tokens / 1000 * self::COST_PER_1K_OUTPUT);

        $wpdb->insert(
            $wpdb->prefix . 'pit_cost_log',
            [
                'user_id'     => get_current_user_id(),
                'podcast_id'  => null,
                'job_id'      => null,
                'action_type' => 'ai_generation',
                'platform'    => null,
                'cost_usd'    => $cost,
                'api_provider' => 'other',
                'api_calls_made' => 1,
                'success'     => 1,
                'metadata'    => json_encode([
                    'action'        => $action,
                    'appearance_id' => $appearance_id,
                    'model'         => self::OPENAI_MODEL,
                    'input_tokens'  => $input_tokens,
                    'output_tokens' => $output_tokens,
                ]),
                'logged_at'   => current_time('mysql'),
            ],
            ['%d', '%d', '%d', '%s', '%s', '%f', '%s', '%d', '%d', '%s', '%s']
        );
    }

    /**
     * Get system prompt for refinement
     */
    private static function get_refine_system_prompt() {
        return <<<PROMPT
You are an expert email copywriter specializing in podcast outreach and guest pitching.
Your task is to refine emails while maintaining the sender's authentic voice.

Guidelines:
- Keep the core message and intent intact
- Maintain any template variables like {{host_name}}, {{podcast_name}}, etc.
- Make the email more engaging and professional
- Ensure the email sounds natural and personal, not templated
- Keep emails concise - busy podcast hosts appreciate brevity
- Focus on value proposition and relevance to the podcast

Output format:
SUBJECT: [refined subject line]
BODY:
[refined email body]
PROMPT;
    }

    /**
     * Get system prompt for generation
     */
    private static function get_generate_system_prompt() {
        return <<<PROMPT
You are an expert email copywriter specializing in podcast outreach and guest pitching.
Write compelling, personalized outreach emails that get responses.

Guidelines:
- Write in a warm, professional tone
- Focus on value to the podcast host and their audience
- Be specific about why the guest is relevant to THIS podcast
- Include a clear call to action
- Keep emails concise (under 200 words typically)
- Use template variables where appropriate: {{host_name}}, {{podcast_name}}, {{guest_name}}, etc.

Output format:
SUBJECT: [subject line]
BODY:
[email body]
PROMPT;
    }

    /**
     * Build refinement prompt
     */
    private static function build_refine_prompt($subject, $body, $instruction) {
        $prompt = "Please refine this email based on the following instruction:\n\n";
        $prompt .= "INSTRUCTION: {$instruction}\n\n";

        if (!empty($subject)) {
            $prompt .= "CURRENT SUBJECT: {$subject}\n\n";
        }

        $prompt .= "CURRENT BODY:\n{$body}";

        return $prompt;
    }

    /**
     * Build generation prompt
     */
    private static function build_generate_prompt($context, $purpose, $additional_context) {
        $prompt = "Generate a {$purpose} email for podcast outreach.\n\n";

        if (!empty($context)) {
            $prompt .= "CONTEXT:\n";
            foreach ($context as $key => $value) {
                if (!empty($value)) {
                    $prompt .= "- {$key}: {$value}\n";
                }
            }
            $prompt .= "\n";
        }

        if (!empty($additional_context)) {
            $prompt .= "ADDITIONAL INSTRUCTIONS: {$additional_context}\n\n";
        }

        return $prompt;
    }

    /**
     * Get appearance context for generation
     */
    private static function get_appearance_context($appearance_id) {
        global $wpdb;

        $appearance = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, p.title as podcast_name, p.description as podcast_description
             FROM {$wpdb->prefix}pit_guest_appearances a
             LEFT JOIN {$wpdb->prefix}pit_podcasts p ON a.podcast_id = p.id
             WHERE a.id = %d",
            $appearance_id
        ));

        if (!$appearance) {
            return [];
        }

        // Try to get guest profile info
        $guest_profile = null;
        if (!empty($appearance->guest_profile_id)) {
            $guest_profile = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}guestify_guest_profiles WHERE id = %d",
                $appearance->guest_profile_id
            ));
        }

        return [
            'podcast_name'  => $appearance->podcast_name ?? '',
            'podcast_description' => $appearance->podcast_description ?? '',
            'guest_name'    => $guest_profile->full_name ?? '',
            'guest_title'   => $guest_profile->title ?? '',
            'guest_company' => $guest_profile->company ?? '',
            'guest_expertise' => $guest_profile->expertise_areas ?? '',
        ];
    }

    /**
     * Parse email response from AI
     */
    private static function parse_email_response($content) {
        $subject = '';
        $body = $content;

        // Try to extract subject
        if (preg_match('/^SUBJECT:\s*(.+?)(?:\n|$)/im', $content, $matches)) {
            $subject = trim($matches[1]);
            // Remove the subject line from content
            $content = preg_replace('/^SUBJECT:\s*.+?(?:\n|$)/im', '', $content);
        }

        // Try to extract body after BODY: marker
        if (preg_match('/BODY:\s*\n(.+)$/is', $content, $matches)) {
            $body = trim($matches[1]);
        } else {
            $body = trim($content);
        }

        return [
            'subject' => $subject,
            'body'    => $body,
        ];
    }
}

// Register routes on REST API init
add_action('rest_api_init', ['PIT_REST_AI', 'register_routes']);
