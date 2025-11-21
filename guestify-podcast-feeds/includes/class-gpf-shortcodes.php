<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GPF_Shortcodes {

    /**
     * Hook into WordPress init to register all shortcodes.
     */
    public static function init() {
        add_action( 'init', [ __CLASS__, 'register' ] );
    }

    /**
     * Register every shortcode.
     */
    public static function register() {
        // Channel Level
        add_shortcode( 'podcast_title',              [ __CLASS__, 'title' ] );
        add_shortcode( 'podcast_description',        [ __CLASS__, 'description' ] ); // We will modify this one
        add_shortcode( 'podcast_link',               [ __CLASS__, 'link' ] );
        add_shortcode( 'podcast_language',           [ __CLASS__, 'language' ] );
        add_shortcode( 'podcast_show_image',         [ __CLASS__, 'show_image' ] );
        add_shortcode( 'podcast_atom_link_self',     [ __CLASS__, 'atom_link_self' ] );
        add_shortcode( 'podcast_itunes_type',        [ __CLASS__, 'itunes_type' ] );
        add_shortcode( 'podcast_itunes_author',      [ __CLASS__, 'itunes_author' ] );
        add_shortcode( 'podcast_itunes_explicit',    [ __CLASS__, 'itunes_explicit' ] );
        add_shortcode( 'podcast_itunes_owner_name',  [ __CLASS__, 'itunes_owner_name' ] );
        add_shortcode( 'podcast_itunes_owner_email', [ __CLASS__, 'itunes_owner_email' ] );
        add_shortcode( 'podcast_itunes_categories',  [ __CLASS__, 'itunes_categories' ] );
        add_shortcode( 'podcast_locked',             [ __CLASS__, 'locked' ] );

        // Calculated Statistics
        add_shortcode( 'podcast_episode_count',          [ __CLASS__, 'episode_count' ] );
        add_shortcode( 'podcast_founded_date',           [ __CLASS__, 'founded_date' ] );
        add_shortcode( 'podcast_last_episode_date',      [ __CLASS__, 'last_episode_date' ] );
        add_shortcode( 'podcast_average_episode_length', [ __CLASS__, 'average_episode_length' ] );
        add_shortcode( 'podcast_is_active',              [ __CLASS__, 'is_active' ] );
        add_shortcode( 'podcast_publishing_frequency',   [ __CLASS__, 'publishing_frequency' ] );

        // Specific Episode Finder
        add_shortcode( 'podcast_specific_episode',       [ __CLASS__, 'specific_episode' ] );

        // Episodes List + AJAX “Load More”
        add_shortcode( 'podcast_episodes',               [ __CLASS__, 'episodes' ] );
    }

    // Helper to parse HH:MM:SS, MM:SS or plain seconds
    private static function duration_to_seconds( $duration_str ) {
        $s = trim( (string) $duration_str );
        if ( ctype_digit( $s ) ) return (int) $s;
        $parts = explode( ':', $s );
        if ( count( $parts ) === 3 ) return (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
        if ( count( $parts ) === 2 ) return (int)$parts[0] * 60 + (int)$parts[1];
        return false;
    }

    // --- Channel‑Level Shortcodes ---
    public static function title( $atts ) { $a = shortcode_atts( [ 'feed' => '' ], $atts ); if ( empty( $a['feed'] ) ) return 'Error: Feed URL missing.'; $d = GPF_Core::get_channel_data( $a['feed'] ); return !empty( $d['error'] ) ? 'Error: ' . esc_html( $d['error'] ) : esc_html( $d['title'] ); }
    
    /**
     * Shortcode to display podcast description with a snippet and read more toggle.
     */
    public static function description( $atts ) {
        $a = shortcode_atts( [
            'feed'             => '',
            'excerpt_length'   => 90, // Default number of words for the snippet
            'read_more_text'   => __( 'Read More', 'guestify-podcast-feeds' ),
            'show_less_text'   => __( 'Show Less', 'guestify-podcast-feeds' ),
        ], $atts, 'podcast_description' );

        if ( empty( $a['feed'] ) ) {
            return '<p>' . esc_html__( 'Error: Feed URL missing for podcast description.', 'guestify-podcast-feeds' ) . '</p>';
        }

        $d = GPF_Core::get_channel_data( $a['feed'] );

        if ( ! empty( $d['error'] ) ) {
            return '<p>' . sprintf( esc_html__( 'No description available (%s).', 'guestify-podcast-feeds' ), esc_html( $d['error'] ) ) . '</p>';
        }

        $raw_description = $d['description'] ?? '';
        if ( empty( trim( $raw_description ) ) ) {
            return '<p>' . esc_html__( 'No description found for this podcast.', 'guestify-podcast-feeds' ) . '</p>';
        }

        // Sanitize the description
        $sanitized_description = wp_kses_post( $raw_description );

        // Generate snippet (wp_trim_words strips tags, so we use the sanitized version without wpautop first)
        $excerpt_length = absint( $a['excerpt_length'] );
        $ellipsis = apply_filters('excerpt_more', ' ' . '[&hellip;]'); // Standard WordPress ellipsis
        $snippet_text = wp_trim_words( $sanitized_description, $excerpt_length, $ellipsis );
        
        // Check if the full description is longer than the snippet
        // Count words in the sanitized description (stripping tags for a more accurate word count)
        $full_description_word_count = str_word_count( strip_tags( $sanitized_description ) );
        
        $show_toggle = $full_description_word_count > $excerpt_length;

        $snippet_html = '';
        if (!empty($snippet_text)) {
            $snippet_html = wpautop( $snippet_text ); // Add paragraphs to the snippet
        }

        $full_description_html = wpautop( $sanitized_description ); // Add paragraphs to the full description

        static $desc_cnt = 0;
        $desc_cnt++;
        $toggle_id = 'gpf-podcast-desc-toggle-' . $desc_cnt;

        $output = '<div class="gpf-podcast-description-wrapper shared-expand copyblock">'; // Keep shared-expand and copyblock if styled by theme

        if ( $show_toggle ) {
            $output .= '<input id="' . esc_attr( $toggle_id ) . '" type="checkbox" class="gpf-desc-toggle-checkbox" style="display:none;">';
            
            // Snippet: Visible when checkbox is NOT checked
            $output .= '<div class="gpf-podcast-description-snippet">' . $snippet_html . '</div>';
            
            // Full description: Visible when checkbox IS checked (uses 'expandcontent' if theme styles it)
            $output .= '<div class="gpf-podcast-description-full expandcontent">' . $full_description_html . '</div>';
            
            // Toggle Label
            $output .= sprintf(
                '<label for="%s" class="expand-toggle"><span class="more-text">%s</span><span class="less-text">%s</span></label>',
                esc_attr( $toggle_id ),
                esc_html( $a['read_more_text'] ),
                esc_html( $a['show_less_text'] )
            );
        } else {
            // If no toggle is needed (full description is short enough), just show the full content.
            $output .= '<div class="gpf-podcast-description-full">' . $full_description_html . '</div>';
        }

        $output .= '</div>';

        return $output;
    }

    public static function link( $atts ) { $a = shortcode_atts( [ 'feed' => '', 'output' => 'url', 'text' => '', 'class' => '', 'target' => '_blank' ], $atts ); if ( empty( $a['feed'] ) ) return 'Error: Feed URL missing.'; $d = GPF_Core::get_channel_data( $a['feed'] ); if ( !empty( $d['error'] ) ) return $a['output']==='link'?'Cannot generate link ('.esc_html($d['error']).')':'No link URL ('.esc_html($d['error']).')'; $url = $d['link']; if ( empty( $url ) ) return $a['output']==='link'?'Cannot generate link: Link URL not found':'No link URL found.'; if ( $a['output'] === 'link' ) { $txt = $a['text'] ?: ( $d['title'] ?: $url ); $cls = $a['class'] ? ' class="'.esc_attr(preg_replace('/[^a-zA-Z0-9 _-]/','',$a['class'])).'"' : ''; $tgt = in_array($a['target'],['_blank','_self','_parent','_top'],true)?' target="'.esc_attr($a['target']).'"':''; $rel = ($a['target']==='_blank')?' rel="noopener noreferrer"':''; return sprintf('<a href="%s"%s%s%s>%s</a>', esc_url($url), $cls, $tgt, $rel, esc_html($txt)); } return esc_url( $url ); }
    public static function language( $atts ) { $a = shortcode_atts( [ 'feed' => '' ], $atts ); if ( empty( $a['feed'] ) ) return 'Error: Feed URL missing.'; $d = GPF_Core::get_channel_data( $a['feed'] ); if ( !empty( $d['error'] ) ) return 'No language info (' . esc_html( $d['error'] ) . ')'; $code = strtolower(trim($d['language'])); if (!$code) return 'No language specified.'; $map = ['en'=>'English','es'=>'Spanish','fr'=>'French','de'=>'German','it'=>'Italian','pt'=>'Portuguese']; if (isset($map[$code])) return esc_html($map[$code]); list($primary) = preg_split('/[-_]/', $code); return isset($map[$primary]) ? esc_html($map[$primary]) : esc_html($d['language']); }
    public static function show_image( $atts ) { $a = shortcode_atts( [ 'feed' => '', 'placeholder' => '', 'width' => '', 'height' => '', 'class' => '' ], $atts ); if ( empty( $a['feed'] ) ) return 'Error: Feed URL missing.'; $d = GPF_Core::get_channel_data( $a['feed'] ); $ph = esc_url($a['placeholder']?:'https://via.placeholder.com/150/FFFFFF/CCCCCC?text=No+Image'); $img_attrs = []; if (is_numeric($a['width'])&&$a['width']>0) $img_attrs['width'] = 'width="'.(int)$a['width'].'"'; if (is_numeric($a['height'])&&$a['height']>0) $img_attrs['height'] = 'height="'.(int)$a['height'].'"'; if ($a['class']) { $san = trim(preg_replace('/\s+/',' ',preg_replace('/[^a-zA-Z0-9 _-]/','',$a['class']))); if ($san) $img_attrs['class'] = 'class="'.esc_attr($san).'"'; } if (!isset($img_attrs['width'])&&!isset($img_attrs['height'])) $img_attrs['style'] = 'style="max-width:100%;height:auto;"'; $img_attrs['decoding'] = 'decoding="async"'; $img_attrs['loading'] = 'loading="lazy"'; if (!empty($d['error'])) { $img_attrs['src'] = 'src="'.$ph.'"'; $img_attrs['alt'] = 'alt="No image: '.esc_attr($d['error']).'"'; return '<img '.implode(' ',$img_attrs).' />'; } $src = $d['image_url'] ?: $d['itunes_image_url'] ?: ''; $alt = 'Podcast Image'; if (!$src) { $src = $ph; $alt = 'Placeholder'; } else { $img_attrs['onerror'] = 'onerror="this.onerror=null;this.src=\''.$ph.'\';"'; } $img_attrs['src'] = 'src="'.esc_url($src).'"'; $img_attrs['alt'] = 'alt="'.esc_attr($alt).'"'; ksort($img_attrs); return '<img '.implode(' ',$img_attrs).' />'; }
    public static function atom_link_self( $atts ) { $a=shortcode_atts(['feed'=>''],$atts); if(empty($a['feed']))return 'Err:NoFeed'; $d=GPF_Core::get_channel_data($a['feed']); if(!empty($d['error']))return 'NoAtomLinkErr'; return $d['atom_link_self']?esc_url($d['atom_link_self']):'NoAtomLink'; }
    public static function itunes_type( $atts ) { $a=shortcode_atts(['feed'=>''],$atts); if(empty($a['feed']))return 'Err:NoFeed'; $d=GPF_Core::get_channel_data($a['feed']); if(!empty($d['error']))return 'NoTypeErr'; return $d['itunes_type']?esc_html($d['itunes_type']):'NoiTunesType'; }
    public static function itunes_author( $atts ) { $a=shortcode_atts(['feed'=>''],$atts); if(empty($a['feed']))return 'Err:NoFeed'; $d=GPF_Core::get_channel_data($a['feed']); if(!empty($d['error']))return 'NoAuthorErr'; return $d['itunes_author']?esc_html($d['itunes_author']):'NoiTunesAuthor'; }
    public static function itunes_explicit( $atts ) { $a=shortcode_atts(['feed'=>''],$atts); if(empty($a['feed']))return 'Err:NoFeed'; $d=GPF_Core::get_channel_data($a['feed']); if(!empty($d['error']))return 'NoExplicitErr'; $val=strtolower($d['itunes_explicit']); return($val==='yes'||$val==='true')?'Explicit':'Not Explicit'; }
    public static function itunes_owner_name( $atts ) { $a=shortcode_atts(['feed'=>''],$atts); if(empty($a['feed']))return 'Err:NoFeed'; $d=GPF_Core::get_channel_data($a['feed']); if(!empty($d['error']))return 'NoOwnerNameErr'; return $d['itunes_owner_name']?esc_html($d['itunes_owner_name']):'NoiTunesOwnerName'; }
    public static function itunes_owner_email( $atts ) { $a=shortcode_atts(['feed'=>''],$atts); if(empty($a['feed']))return 'Err:NoFeed'; $d=GPF_Core::get_channel_data($a['feed']); if(!empty($d['error']))return 'NoOwnerEmailErr'; return $d['itunes_owner_email']?esc_html($d['itunes_owner_email']):'NoiTunesOwnerEmail'; }
    public static function itunes_categories( $atts ) { $a=shortcode_atts(['feed'=>'','separator'=>', ','item_class'=>''],$atts); if(empty($a['feed']))return 'Err:NoFeed'; $d=GPF_Core::get_channel_data($a['feed']); if(!empty($d['error']))return 'NoCatsErr'; if(empty($d['itunes_categories']))return 'NoiTunesCats'; $cls=$a['item_class']?' class="'.esc_attr(preg_replace('/[^a-zA-Z0-9 _-]/','',$a['item_class'])).'"':''; $out=[]; foreach($d['itunes_categories'] as $cat)if(trim((string)$cat)!=='') $out[]='<span'.$cls.'>'.esc_html(trim((string)$cat)).'</span>'; return $out?implode(esc_html($a['separator']),$out):'NoValidiTunesCats'; }
    public static function locked( $atts ) { $a=shortcode_atts(['feed'=>''],$atts); if(empty($a['feed']))return 'Err:NoFeed'; $d=GPF_Core::get_channel_data($a['feed']); if(!empty($d['error']))return 'NoLockStatusErr'; return isset($d['podcast_locked'])&&$d['podcast_locked']!==''?esc_html($d['podcast_locked']):'LockStatusNotFound'; }

    // --- Calculated Statistics ---
    public static function episode_count($atts){$a=shortcode_atts(['feed'=>'','period'=>'all'],$atts);if(empty($a['feed']))return 'Err:NoFeed';include_once ABSPATH.WPINC.'/feed.php';$rss=fetch_feed(esc_url_raw($a['feed']));if(is_wp_error($rss))return 'N/A';$items=$rss->get_items();if($a['period']!=='all'){$since=strtotime('-'.$a['period']);$c=0;foreach($items as $it)if($since===false||$it->get_date('U')>=$since)$c++;return $c;}return count($items);}
    public static function founded_date($atts){$a=shortcode_atts(['feed'=>'','format'=>'F j, Y'],$atts);if(empty($a['feed']))return 'Err:NoFeed';include_once ABSPATH.WPINC.'/feed.php';$rss=fetch_feed(esc_url_raw($a['feed']));if(is_wp_error($rss))return 'N/A';$items=$rss->get_items();if(empty($items))return 'NoEps';$ts=array_filter(array_map(function($it){return $it->get_date('U');},$items),'is_numeric');return empty($ts)?'NoDates':date(esc_attr($a['format']),min($ts));}
    public static function last_episode_date($atts){$a=shortcode_atts(['feed'=>'','format'=>'F j, Y'],$atts);if(empty($a['feed']))return 'Err:NoFeed';include_once ABSPATH.WPINC.'/feed.php';$rss=fetch_feed(esc_url_raw($a['feed']));if(is_wp_error($rss))return 'N/A';$items=$rss->get_items(0,1);if(empty($items))return 'NoEps';$ts=$items[0]->get_date('U');return is_numeric($ts)?date(esc_attr($a['format']),$ts):'NoDate';}
    public static function average_episode_length($atts){$a=shortcode_atts(['feed'=>'','period'=>'1 year','format'=>'auto'],$atts);if(empty($a['feed']))return 'Err:NoFeed';include_once ABSPATH.WPINC.'/feed.php';$rss=fetch_feed(esc_url_raw($a['feed']));if(is_wp_error($rss))return 'N/A';$since=($a['period']!=='all')?(strtotime('-'.$a['period'])?:strtotime('-1 year')):null;$durs=[];foreach($rss->get_items()as $it){$tags=$it->get_item_tags(SIMPLEPIE_NAMESPACE_ITUNES,'duration');$sec=self::duration_to_seconds(isset($tags[0]['data'])?trim($tags[0]['data']):'');if($sec!==false&&$sec>0&&($since===null||$it->get_date('U')>=$since))$durs[]=$sec;}if(empty($durs))return 'NoData';$avg=array_sum($durs)/count($durs);if($a['format']==='seconds')return round($avg);if($a['format']==='minutes')return round($avg/60);$h=floor($avg/3600);$m=floor(($avg%3600)/60);$s=round($avg%60);return($h>0)?sprintf('%d:%02d:%02d',$h,$m,$s):sprintf('%d:%02d',$m,$s);}
    public static function is_active($atts){$a=shortcode_atts(['feed'=>'','threshold'=>'3 months','yes'=>'Yes','no'=>'No'],$atts);if(empty($a['feed']))return 'Err:NoFeed';include_once ABSPATH.WPINC.'/feed.php';$rss=fetch_feed(esc_url_raw($a['feed']));if(is_wp_error($rss))return 'N/A';$items=$rss->get_items(0,1);if(empty($items))return 'NoEps';$ts=$items[0]->get_date('U');$thr=strtotime('-'.$a['threshold']);if(!is_numeric($ts)||!$thr)return 'Err';return($ts>=$thr)?esc_html($a['yes']):esc_html($a['no']);}
    public static function publishing_frequency($atts){$a=shortcode_atts(['feed'=>'','period'=>'3 months','min_eps'=>5],$atts);if(empty($a['feed']))return 'Err:NoFeed';include_once ABSPATH.WPINC.'/feed.php';$since=strtotime('-'.$a['period'])?:strtotime('-3 months');$rss=fetch_feed(esc_url_raw($a['feed']));if(is_wp_error($rss))return 'N/A';$items=$rss->get_items(0,$a['min_eps']+10);$t=[];foreach($items as $it){$ts=$it->get_date('U');if(is_numeric($ts)&&$ts>=$since)$t[]=$ts;}if(count($t)<$a['min_eps'])return 'NoData';rsort($t);$diffs=[];for($i=0;$i<count($t)-1;$i++){$d=$t[$i]-$t[$i+1];if($d>60)$diffs[]=$d;}if(empty($diffs))return 'Err';$avg=array_sum($diffs)/count($diffs);$day=86400;if($avg<=$day*1.5)return'Daily';if($avg<=$day*4)return'MultiWeekly';if($avg<=$day*9)return'Weekly';if($avg<=$day*18)return'BiWeekly';if($avg<=$day*45)return'Monthly';return'Irregularly';}

    // --- Specific Episode Finder (Handles single terms and comma-separated lists for relevant 'contains' attributes) ---
    public static function specific_episode( $atts ) {
        $a = shortcode_atts( [
            'feed'                      => '',
            'guid'                      => null,
            'date'                      => null, 
            'episode_number'            => null,
            'season_number'             => null, 
            'title_exact'               => null,
            'title_contains'            => null, // For single term
            'description_contains'      => null, // For single term
            'title_contains_any'        => null, // For comma-separated list
            'description_contains_any'  => null, // For comma-separated list
            'order_if_multiple'         => 'newest', 
        ], $atts, 'podcast_specific_episode' );

        if ( empty( $a['feed'] ) ) {
            return '<p>Error: Feed URL missing for specific episode shortcode.</p>';
        }

        $search_criteria_provided = false;
        $search_attribute_keys = ['guid', 'date', 'episode_number', 'title_exact', 'title_contains', 'description_contains', 'title_contains_any', 'description_contains_any'];
        foreach ( $search_attribute_keys as $key ) {
            if ( ! is_null( $a[ $key ] ) ) {
                $search_criteria_provided = true;
                break;
            }
        }
        if ( ! $search_criteria_provided ) {
            return '<p>Error: Please provide at least one search criterion for the specific episode.</p>';
        }

        include_once ABSPATH . WPINC . '/feed.php';
        $rss = fetch_feed( esc_url_raw( $a['feed'] ) );

        if ( is_wp_error( $rss ) ) {
            return '<p>Error fetching feed: ' . esc_html( $rss->get_error_message() ) . '</p>';
        }

        $all_feed_items = $rss->get_items();
        if ( empty( $all_feed_items ) ) {
            return '<p>No episodes found in the feed.</p>';
        }

        $attribute_precedence = ['guid', 'date', 'episode_number', 'title_exact', 'title_contains', 'title_contains_any', 'description_contains', 'description_contains_any'];
        $output_html = '';

        foreach ( $attribute_precedence as $attribute_key ) {
            if ( is_null( $a[ $attribute_key ] ) ) {
                continue; 
            }

            $episodes_matching_this_criterion = [];
            $search_terms_list = [];

            if ( $attribute_key === 'title_contains_any' && !empty(trim($a['title_contains_any'])) ) {
                $search_terms_list = array_map('trim', explode(',', $a['title_contains_any']));
            } elseif ( $attribute_key === 'description_contains_any' && !empty(trim($a['description_contains_any'])) ) {
                $search_terms_list = array_map('trim', explode(',', $a['description_contains_any']));
            }

            foreach ( $all_feed_items as $item ) {
                $match_found_for_current_item = false;
                switch ( $attribute_key ) {
                    case 'guid':
                        if ( strtolower( trim( $item->get_id() ) ) === strtolower( trim( $a['guid'] ) ) ) $match_found_for_current_item = true;
                        break;
                    case 'date':
                        if ( $item->get_date('Y-m-d') === trim( $a['date'] ) ) $match_found_for_current_item = true;
                        break;
                    case 'episode_number':
                        $itunes_ep_tags = $item->get_item_tags( SIMPLEPIE_NAMESPACE_ITUNES, 'episode' );
                        $item_ep_num = isset($itunes_ep_tags[0]['data']) ? trim($itunes_ep_tags[0]['data']) : null;
                        if ( !is_null($item_ep_num) && $item_ep_num === trim( (string) $a['episode_number'] ) ) {
                            if ( !is_null( $a['season_number'] ) ) {
                                $itunes_season_tags = $item->get_item_tags( SIMPLEPIE_NAMESPACE_ITUNES, 'season' );
                                $item_season_num = isset($itunes_season_tags[0]['data']) ? trim($itunes_season_tags[0]['data']) : null;
                                if ( !is_null($item_season_num) && $item_season_num === trim( (string) $a['season_number'] ) ) {
                                    $match_found_for_current_item = true;
                                }
                            } else { 
                                $match_found_for_current_item = true;
                            }
                        }
                        break;
                    case 'title_exact':
                        if ( strtolower( trim( $item->get_title() ) ) === strtolower( trim( $a['title_exact'] ) ) ) $match_found_for_current_item = true;
                        break;
                    case 'title_contains': 
                        if ( stripos( $item->get_title(), $a['title_contains'] ) !== false ) $match_found_for_current_item = true;
                        break;
                    case 'title_contains_any': 
                        if (!empty($search_terms_list)) {
                            foreach ($search_terms_list as $term) {
                                if (!empty($term) && stripos( $item->get_title(), $term ) !== false) {
                                    $match_found_for_current_item = true;
                                    break; 
                                }
                            }
                        }
                        break;
                    case 'description_contains': 
                        if ( stripos( $item->get_description() . ' ' . $item->get_content(), $a['description_contains'] ) !== false ) $match_found_for_current_item = true;
                        break;
                    case 'description_contains_any': 
                         if (!empty($search_terms_list)) {
                            foreach ($search_terms_list as $term) {
                                 if (!empty($term) && stripos( $item->get_description() . ' ' . $item->get_content(), $term ) !== false) {
                                    $match_found_for_current_item = true;
                                    break; 
                                }
                            }
                        }
                        break;
                }
                if ( $match_found_for_current_item ) {
                    $episodes_matching_this_criterion[] = $item;
                }
            }

            if ( ! empty( $episodes_matching_this_criterion ) ) {
                if ( strtolower( $a['order_if_multiple'] ) === 'oldest' ) {
                    $episodes_matching_this_criterion = array_reverse( $episodes_matching_this_criterion );
                }
                
                $output_html .= '<div class="gpf-specific-episodes-group">'; 
                foreach ( $episodes_matching_this_criterion as $matched_item ) {
                    $output_html .= self::render_episode_card( $matched_item, 'interview' );
                }
                $output_html .= '</div>';
                return $output_html; 
            }
        }

        return empty($output_html) ? '<p>Episode not found matching any of the specified criteria.</p>' : $output_html;
    }

    /**
     * Helper to render a SimplePie_Item via episode-card.php template.
     */
    private static function render_episode_card( $item, $card_type = 'recent' ) {
        ob_start();
        $template = GPF_PLUGIN_DIR . 'includes/templates/episode-card.php'; 
        if ( file_exists( $template ) ) {
            include $template;
        } else {
            return '';
        }
        return ob_get_clean();
    }

    // --- Episodes List + AJAX “Load More” ---
    public static function episodes( $atts ) {
        $a = shortcode_atts( [
            'feed'           => '',
            'initial_posts'  => 5,
            'posts_per_page' => 5,
        ], $atts, 'podcast_episodes' );

        if ( empty( $a['feed'] ) ) return '<p>Error: Feed URL missing.</p>';

        include_once ABSPATH . WPINC . '/feed.php';
        $rss = fetch_feed( esc_url_raw( $a['feed'] ) );
        if ( is_wp_error( $rss ) ) return '<p>Feed Error: '.esc_html($rss->get_error_message()).'</p>';

        $initial = absint( $a['initial_posts'] );
        $perpage = absint( $a['posts_per_page'] );
        $items   = $rss->get_items( 0, $initial );
        $output  = '<div class="gpf-episodes-list">';

        if ( empty( $items ) && $initial > 0 ) {
            $output .= '<p>No episodes found in this feed.</p>';
        } else {
            foreach ( $items as $item ) {
                $output .= self::render_episode_card( $item, 'recent' );
            }
        }
        $output .= '</div>';

        $total_feed_items = $rss->get_item_quantity();
        if ( ($initial > 0 && $total_feed_items > $initial) || ($initial === 0 && $total_feed_items > 0) ) {
            $output .= '<div id="load-more-container" class="load-more" style="text-align:center;margin-top:20px;">';
            $button_text = ($initial === 0 && $total_feed_items > 0) ? 
                           esc_html__( 'Load Episodes', 'guestify-podcast-feeds' ) : 
                           esc_html__( 'Load More Episodes', 'guestify-podcast-feeds' );
            $output .= sprintf(
                '<button id="load-more-button" class="button outline-button" data-page="1" data-feed-url="%s" data-initial-posts="%d" data-posts-per-page="%d">%s</button>',
                esc_attr( $a['feed'] ), $initial, $perpage, $button_text
            );
            $output .= '</div>';
        }
        return $output;
    }
}
