<?php
/**
 * Template for displaying a single podcast episode.
 *
 * Available variables:
 * @var SimplePie_Item $item The feed item.
 * @var string $card_type Type of card ('interview' or 'recent') for styling. Passed from shortcode.
 */

if ( ! isset( $item ) ) {
    return;
}

// Ensure $card_type has a default value and is escaped.
$card_type = isset($card_type) ? esc_attr($card_type) : 'recent';

$title        = esc_html( $item->get_title() );
$link         = esc_url( $item->get_permalink() );
$date_string  = $item->get_date( 'j F Y' ); 
$date_formatted = !empty($date_string) ? esc_html($date_string) : '';

$enclosure    = $item->get_enclosure(0); // Get the first enclosure
$audio_url    = '';
$audio_type   = '';
$duration_raw = ''; 
$duration_formatted = ''; // User-friendly duration like '42 min'

if ( $enclosure && str_starts_with( strtolower( (string) $enclosure->get_type() ), 'audio/' ) ) {
    $audio_url  = esc_url( $enclosure->get_link() );
    $audio_type = esc_attr( $enclosure->get_type() );
    
    // Attempt to get duration from iTunes namespace first, then enclosure
    $itunes_tags = $item->get_item_tags( SIMPLEPIE_NAMESPACE_ITUNES, 'duration' );
    // CRITICAL FIX: Check array and key existence before accessing
    if ( !empty($itunes_tags) && isset($itunes_tags[0]['data']) && !empty(trim($itunes_tags[0]['data'])) ) {
        $duration_raw = trim($itunes_tags[0]['data']);
    } elseif ( method_exists($enclosure, 'get_duration') && $enclosure->get_duration() ) {
        // SimplePie's get_duration() often returns seconds.
        $duration_raw = $enclosure->get_duration();
    }

    if ($duration_raw) {
        $seconds = 0;
        if (is_numeric($duration_raw)) {
            $seconds = (int) $duration_raw;
        } elseif (count($parts = explode(':', $duration_raw)) >= 2) { // HH:MM:SS or MM:SS
            if (count($parts) === 3) { // HH:MM:SS
                $seconds = ((int)$parts[0] * 3600) + ((int)$parts[1] * 60) + (int)$parts[2];
            } elseif (count($parts) === 2) { // MM:SS
                $seconds = ((int)$parts[0] * 60) + ((int)$parts[1]);
            }
        }
        
        if ($seconds > 0) {
            $minutes = floor($seconds / 60);
            $duration_formatted = $minutes . ' min';
        } elseif (!empty($duration_raw) && !is_numeric($duration_raw) && stripos($duration_raw, 'min') !== false) {
            // If it's already a string like "42 min" (case-insensitive check for "min")
            $duration_formatted = esc_html($duration_raw);
        } elseif (!empty($duration_raw) && !is_numeric($duration_raw)) {
             // Fallback for unparsed non-numeric, non-second durations (e.g. "0:42:00")
            $parts = explode(':', $duration_raw); // Re-explode or use previously exploded $parts if still in scope and valid
            if (count($parts) >= 2) { 
                 $m_index = count($parts) - 2; 
                 $s_index = count($parts) - 1; 
                 $h_index = count($parts) - 3; 

                 $m = (int) $parts[$m_index];
                 $h = (count($parts) === 3 && $h_index >=0) ? (int) $parts[$h_index] : 0;
                 $total_minutes = ($h * 60) + $m;
                 if ($total_minutes > 0) {
                    $duration_formatted = $total_minutes . ' min';
                 }
            }
        }
        // Final fallback if formatted is still empty but raw numeric seconds exist
         if(empty($duration_formatted) && is_numeric($duration_raw) && (int)$duration_raw > 0){
            $duration_formatted = floor((int)$duration_raw / 60) . ' min';
        }
    }
}

$item_image_url = '';
$img_tags = $item->get_item_tags( SIMPLEPIE_NAMESPACE_ITUNES, 'image' );
// CRITICAL FIX: Check array and key existence before accessing
if ( !empty($img_tags) && isset($img_tags[0]['attribs']['']['href']) && !empty(trim($img_tags[0]['attribs']['']['href'])) ) {
    $item_image_url = esc_url( trim($img_tags[0]['attribs']['']['href']) );
} elseif ($enclosure && $enclosure->get_thumbnail()) { 
    $item_image_url = esc_url( $enclosure->get_thumbnail() );
}

$card_specific_class = 'episode-card-' . $card_type;

?>
<div class="<?php echo esc_attr( $card_specific_class ); ?> episode-card">
    <?php if ( $item_image_url ): ?>
    <div class="episode-thumbnail-wrapper">
        <img class="episode-thumbnail" src="<?php echo $item_image_url; ?>" alt="<?php echo $title; ?>" loading="lazy" onerror="this.style.display='none'; this.parentElement.style.display='none';">
    </div>
    <?php else: ?>
    <div class="episode-thumbnail-wrapper episode-thumbnail-placeholder">
        <?php // Intentionally empty or add placeholder SVG/icon here ?>
    </div>
    <?php endif; ?>

    <div class="episode-info">
        <?php if ( $date_formatted ): ?>
            <div class="episode-date"><?php echo $date_formatted; ?></div>
        <?php endif; ?>

        <h3 class="episode-title">
            <a href="<?php echo $link; ?>" target="_blank" rel="noopener noreferrer"><?php echo $title; ?></a>
        </h3>

        <?php 
        $description = $item->get_description();
        if ( $description ): 
            $trimmed_description_for_check = trim(strip_tags($description));
            if (!empty($trimmed_description_for_check)) :
        ?>
        <div class="episode-description-toggle shared-expand copyblock"> 
            <?php $uniq_id = 'gpf-desc-' . uniqid(); ?>
            <input id="<?php echo $uniq_id; ?>" type="checkbox" class="episode-description-checkbox" style="display:none;">
            <label for="<?php echo $uniq_id; ?>" class="expand-toggle">
                <span class="more-text"><?php esc_html_e( 'Expand to View Full Description', 'guestify-podcast-feeds' ); ?></span>
                <span class="less-text"><?php esc_html_e( 'Show Less', 'guestify-podcast-feeds' ); ?></span>
            </label>
            <div class="expandcontent episode-description-content">
                <?php echo wp_kses_post( wpautop( $description ) ); ?>
            </div>
        </div>
        <?php 
            endif; 
        endif; 
        ?>

        <?php if ( $audio_url ): ?>
        <div class="episode-player">
            <audio controls preload="none" style="width:100%;">
                <source src="<?php echo $audio_url; ?>" type="<?php echo $audio_type ?: 'audio/mpeg'; ?>">
                <?php esc_html_e( 'Your browser does not support the audio element.', 'guestify-podcast-feeds' ); ?>
            </audio>
        </div>
        <?php endif; ?>

        <?php if ( $duration_formatted ): ?>
        <div class="episode-duration">
            <svg class="episode-duration-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
            <?php echo esc_html( $duration_formatted ); ?>
        </div>
        <?php endif; ?>
    </div>
</div>
