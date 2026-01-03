<?php
/**
 * Default Email Notification Template
 *
 * Available variables:
 * - $name: Recipient display name
 * - $title: Notification title
 * - $message: Notification message
 * - $action_url: CTA button URL
 * - $action_label: CTA button text
 * - $meta: Metadata array (podcast_name, due_date, record_date, priority)
 * - $type: Notification type (task_reminder, overdue_task, interview_reminder)
 *
 * @package Podcast_Influence_Tracker
 * @since 3.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Priority color mapping
$priority_colors = [
    'urgent' => '#ef4444',
    'high'   => '#f97316',
    'medium' => '#22c55e',
    'low'    => '#0ea5e9',
];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; border-radius: 10px 10px 0 0;">
        <h1 style="color: white; margin: 0; font-size: 24px;">Guestify</h1>
    </div>
    <div style="background: #ffffff; padding: 30px; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 10px 10px;">
        <p style="margin-top: 0;">Hi <?php echo esc_html($name); ?>,</p>
        <h2 style="color: #1e293b; margin-top: 20px;"><?php echo esc_html($title); ?></h2>
        <p style="color: #64748b;"><?php echo esc_html($message); ?></p>

        <?php if (!empty($meta)) : ?>
            <?php if (!empty($meta['podcast_name'])) : ?>
                <p><strong>Podcast:</strong> <?php echo esc_html($meta['podcast_name']); ?></p>
            <?php endif; ?>

            <?php if (!empty($meta['due_date'])) : ?>
                <p><strong>Due Date:</strong> <?php echo esc_html($meta['due_date']); ?></p>
            <?php endif; ?>

            <?php if (!empty($meta['record_date'])) : ?>
                <p><strong>Interview Date:</strong> <?php echo esc_html($meta['record_date']); ?></p>
            <?php endif; ?>

            <?php if (!empty($meta['priority'])) :
                $color = $priority_colors[$meta['priority']] ?? '#6b7280';
            ?>
                <p><strong>Priority:</strong> <span style="color: <?php echo esc_attr($color); ?>; font-weight: 600;"><?php echo esc_html(ucfirst($meta['priority'])); ?></span></p>
            <?php endif; ?>
        <?php endif; ?>

        <div style="margin-top: 30px;">
            <a href="<?php echo esc_url($action_url); ?>" style="display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: 600;"><?php echo esc_html($action_label); ?></a>
        </div>
        <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 30px 0;">
        <p style="color: #94a3b8; font-size: 12px; margin-bottom: 0;">
            You're receiving this email because you have notifications enabled in Guestify.
            <a href="<?php echo esc_url(home_url('/app/settings/')); ?>" style="color: #667eea;">Manage notification settings</a>
        </p>
    </div>
</body>
</html>
