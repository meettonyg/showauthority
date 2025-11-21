<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GPF_Core {

    /**
     * Hook into feed caching filter.
     */
    public static function init() {
        add_filter(
            'wp_feed_cache_transient_lifetime',
            function() { return 12 * HOUR_IN_SECONDS; }
        );
    }

    /**
     * Parses HH:MM:SS or MM:SS or SS format into seconds.
     */
    private static function duration_to_seconds( $duration_str ) {
        $duration_str = trim( (string) $duration_str );
        if ( empty( $duration_str ) ) {
            return false;
        }
        if ( ctype_digit( $duration_str ) ) {
            return (int) $duration_str;
        }
        $parts = explode( ':', $duration_str );
        $seconds = 0;
        if ( count( $parts ) === 3 ) {
            $seconds += (int) $parts[0] * 3600;
            $seconds += (int) $parts[1] * 60;
            $seconds += (int) $parts[2];
        } elseif ( count( $parts ) === 2 ) {
            $seconds += (int) $parts[0] * 60;
            $seconds += (int) $parts[1];
        } elseif ( count( $parts ) === 1 && is_numeric( $parts[0] ) ) {
            $seconds += (int) $parts[0];
        } else {
            return false;
        }
        return $seconds;
    }

    /**
     * Exactly your original get_podcast_data_lightweight(), but static & inside our class.
     */
    public static function get_channel_data( $feed_url ) {
        $feed_url  = esc_url_raw( $feed_url );
        if ( empty( $feed_url ) ) {
            return [ 'error' => 'Feed URL is empty.' ];
        }

        $cache_key = 'podcast_data_lw_' . md5( $feed_url );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        // Prepare defaults
        $data = [
            'title'             => '',
            'description'       => '',
            'link'              => '',
            'language'          => '',
            'image_url'         => '',
            'itunes_type'       => '',
            'itunes_author'     => '',
            'itunes_explicit'   => '',
            'itunes_image_url'  => '',
            'itunes_owner_name' => '',
            'itunes_owner_email'=> '',
            'itunes_categories' => [],
            'atom_link_self'    => '',
            'podcast_locked'    => '',
            'error'             => '',
        ];

        // Fetch raw feed
        $response = wp_remote_get( $feed_url, [ 'timeout' => 10 ] );
        if ( is_wp_error( $response ) ) {
            $data['error'] = 'Error fetching feed: ' . $response->get_error_message();
            set_transient( $cache_key, $data, HOUR_IN_SECONDS );
            return $data;
        }
        $http_code = wp_remote_retrieve_response_code( $response );
        if ( $http_code >= 400 ) {
            $data['error'] = 'Error fetching feed: HTTP Status Code ' . $http_code;
            set_transient( $cache_key, $data, HOUR_IN_SECONDS );
            return $data;
        }
        $rss_body = wp_remote_retrieve_body( $response );
        if ( empty( $rss_body ) ) {
            $data['error'] = 'Feed returned empty body.';
            set_transient( $cache_key, $data, HOUR_IN_SECONDS );
            return $data;
        }

        // Extract <channel> block with fallback
        if ( ! preg_match( '/<channel[^>]*>([\s\S]*?)(?=<item\b|<\/channel>)/is', $rss_body, $m ) ) {
            if ( ! preg_match( '/<channel[^>]*>([\s\S]*?)<\/channel>/is', $rss_body, $m ) ) {
                $data['error'] = 'No channel data block found.';
                set_transient( $cache_key, $data, HOUR_IN_SECONDS );
                return $data;
            }
        }
        $channel_block = $m[1] ?? '';
        if ( empty( $channel_block ) ) {
            $data['error'] = 'Channel block extracted but empty.';
            set_transient( $cache_key, $data, HOUR_IN_SECONDS );
            return $data;
        }

        // STANDARD RSS FIELDS
        if ( preg_match( '/<title>([\s\S]*?)<\/title>/is', $channel_block, $m ) ) {
            $raw = preg_replace( '/^\s*<!\[CDATA\[|\]\]>\s*$/s', '', $m[1] );
            $data['title'] = trim( wp_strip_all_tags( $raw ) );
        }
        if ( preg_match( '/<description>([\s\S]*?)<\/description>/is', $channel_block, $m ) ) {
            $raw = preg_replace( '/^\s*<!\[CDATA\[|\]\]>\s*$/s', '', $m[1] );
            $data['description'] = trim( wp_kses_post( $raw ) );
        }
        if ( preg_match( '/<link>([\s\S]*?)<\/link>/is', $channel_block, $m ) && ! preg_match( '/<atom:link/i', $m[0] ) ) {
            $raw = preg_replace( '/^\s*<!\[CDATA\[|\]\]>\s*$/s', '', $m[1] );
            $data['link'] = trim( wp_strip_all_tags( $raw ) );
        }
        if ( preg_match( '/<language>([\s\S]*?)<\/language>/is', $channel_block, $m ) ) {
            $raw = preg_replace( '/^\s*<!\[CDATA\[|\]\]>\s*$/s', '', $m[1] );
            $data['language'] = trim( wp_strip_all_tags( $raw ) );
        }
        if ( preg_match( '/<image>[\s\S]*?<url>([\s\S]*?)<\/url>[\s\S]*?<\/image>/is', $channel_block, $m ) ) {
            $raw = preg_replace( '/^\s*<!\[CDATA\[|\]\]>\s*$/s', '', $m[1] );
            $data['image_url'] = trim( wp_strip_all_tags( $raw ) );
        }

        // ATOM SELF LINK
        if ( preg_match( '/<atom:link[^>]+rel=["\']self["\'][^>]+href=["\']([^"\']+)["\']/is', $channel_block, $m ) ) {
            $data['atom_link_self'] = trim( html_entity_decode( $m[1] ) );
        }

        // ITUNES FIELDS
        if ( preg_match( '/<itunes:type>([\s\S]*?)<\/itunes:type>/is', $channel_block, $m ) ) {
            $data['itunes_type'] = trim( wp_strip_all_tags( $m[1] ) );
        }
        if ( preg_match( '/<itunes:author>([\s\S]*?)<\/itunes:author>/is', $channel_block, $m ) ) {
            $data['itunes_author'] = trim( wp_strip_all_tags( $m[1] ) );
        }
        if ( preg_match( '/<itunes:explicit>([\s\S]*?)<\/itunes:explicit>/is', $channel_block, $m ) ) {
            $data['itunes_explicit'] = trim( wp_strip_all_tags( strtolower( $m[1] ) ) );
        }
        if ( preg_match( '/<itunes:image[^>]+href=["\']([^"\']+)["\']/is', $channel_block, $m ) ) {
            $data['itunes_image_url'] = trim( html_entity_decode( $m[1] ) );
        }

        // ITUNES OWNER
        if ( preg_match( '/<itunes:owner>([\s\S]*?)<\/itunes:owner>/is', $channel_block, $own ) ) {
            if ( preg_match( '/<itunes:name>([\s\S]*?)<\/itunes:name>/is', $own[1], $m2 ) ) {
                $data['itunes_owner_name'] = trim( wp_strip_all_tags( $m2[1] ) );
            }
            if ( preg_match( '/<itunes:email>([\s\S]*?)<\/itunes:email>/is', $own[1], $m2 ) ) {
                $em = sanitize_email( trim( wp_strip_all_tags( $m2[1] ) ) );
                if ( is_email( $em ) ) {
                    $data['itunes_owner_email'] = $em;
                }
            }
        }

        // ITUNES CATEGORIES
        if ( preg_match_all( '/<itunes:category\s+text=["\'](.*?)["\']/is', $channel_block, $cats ) ) {
            $data['itunes_categories'] = array_map( 'trim', $cats[1] );
        }

        // PODCAST:LOCKED
        if ( preg_match( '/<podcast:locked>([\s\S]*?)<\/podcast:locked>/is', $channel_block, $m ) ) {
            $data['podcast_locked'] = trim( wp_strip_all_tags( strtolower( $m[1] ) ) );
        }

        // Cache & return
        set_transient( $cache_key, $data, 12 * HOUR_IN_SECONDS );
        return $data;
    }

    /**
     * Exactly your original get_podcast_items_data(), static.
     */
    public static function get_feed_items( $feed_url, $max_items = 0, $since_timestamp = null ) {

        $output = [
            'items'      => [],
            'error'      => '',
            'feed_title' => '',
            'feed_link'  => '',
        ];

        $feed_url = esc_url_raw( $feed_url );
        if ( empty( $feed_url ) ) {
            $output['error'] = 'Feed URL is empty.';
            return $output;
        }

        include_once ABSPATH . WPINC . '/feed.php';
        $feed = fetch_feed( $feed_url );
        if ( is_wp_error( $feed ) ) {
            $output['error'] = 'Error fetching or parsing feed: ' . $feed->get_error_message();
            return $output;
        }

        $output['feed_title'] = $feed->get_title() ? esc_html( $feed->get_title() ) : '';
        $output['feed_link']  = $feed->get_permalink() ? esc_url( $feed->get_permalink() ) : '';

        $total = $feed->get_item_quantity();
        if ( $total === 0 ) {
            return $output;
        }

        $limit = ( $max_items > 0 && $max_items < $total ) ? $max_items : $total;
        $items = $feed->get_items( 0, $limit );
        $count = 0;

        foreach ( $items as $item ) {
            $ts = $item->get_date( 'U' );
            if ( $since_timestamp !== null && $ts < $since_timestamp ) {
                break;
            }

            $d = [
                'title'                    => $item->get_title() ? strip_tags( $item->get_title() ) : '',
                'link'                     => $item->get_permalink() ? esc_url( $item->get_permalink() ) : '',
                'pubDate'                  => $item->get_date( 'Y-m-d H:i:s' ),
                'pubTimestamp'             => $ts,
                'guid'                     => $item->get_id() ? esc_html( $item->get_id() ) : '',
                'description'              => $item->get_description(),
                'content'                  => $item->get_content(),
                'audio_url'                => null,
                'audio_type'               => null,
                'audio_length'             => null,
                'itunes_duration_raw'      => null,
                'itunes_duration_seconds'  => null,
                'itunes_episode'           => null,
                'itunes_season'            => null,
                'itunes_episodetype'       => null,
                'itunes_explicit'          => null,
                'itunes_image'             => null,
            ];

            // enclosure
            $enc = $item->get_enclosure(0);
            if ( $enc && $enc->get_link() && str_starts_with( strtolower($enc->get_type()), 'audio/' ) ) {
                $d['audio_url']    = esc_url( $enc->get_link() );
                $d['audio_type']   = esc_attr( $enc->get_type() );
                $d['audio_length'] = $enc->get_length() ? (int)$enc->get_length() : null;
            }

            // itunes tags
            $tags = $item->get_item_tags( SIMPLEPIE_NAMESPACE_ITUNES, '' );
            if ( is_array( $tags ) ) {
                foreach ( $tags as $tag ) {
                    $name = strtolower( $tag['name'] ?? '' );
                    $data = $tag['data'] ?? '';
                    switch ( $name ) {
                        case 'duration':
                            $raw = trim( $data );
                            $secs = self::duration_to_seconds( $raw );
                            if ( $secs !== false && $secs > 0 ) {
                                $d['itunes_duration_raw']     = $raw;
                                $d['itunes_duration_seconds'] = $secs;
                            }
                            break;
                        case 'episode':
                            $d['itunes_episode'] = ctype_digit(trim($data)) ? (int)trim($data) : null;
                            break;
                        case 'season':
                            $d['itunes_season'] = ctype_digit(trim($data)) ? (int)trim($data) : null;
                            break;
                        case 'episodetype':
                            $d['itunes_episodetype'] = esc_attr(trim($data));
                            break;
                        case 'explicit':
                            $d['itunes_explicit'] = esc_attr(strtolower(trim($data)));
                            break;
                        case 'image':
                            $href = $tag['attribs']['']['href'] ?? $tag['attribs']['href'] ?? '';
                            if ( $href ) {
                                $d['itunes_image'] = esc_url( $href );
                            }
                            break;
                    }
                }
            }

            $output['items'][] = $d;
            $count++;
            if ( $max_items > 0 && $count >= $max_items ) {
                break;
            }
        }

        return $output;
    }
}
