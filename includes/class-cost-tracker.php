<?php
/**
 * Cost Tracker
 *
 * Tracks and analyzes API costs
 * Provides budget management and cost optimization insights
 */

if (!defined('ABSPATH')) {
    exit;
}

class PIT_Cost_Tracker {

    /**
     * Get cost breakdown by period
     *
     * @param string $period Period (day/week/month/year)
     * @return array Cost breakdown
     */
    public static function get_breakdown($period = 'month') {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_cost_log';

        $date_condition = self::get_date_condition($period);

        // By platform
        $by_platform = $wpdb->get_results(
            "SELECT platform, SUM(cost_usd) as total_cost, COUNT(*) as api_calls
            FROM $table
            WHERE $date_condition
            GROUP BY platform
            ORDER BY total_cost DESC"
        );

        // By action type
        $by_action = $wpdb->get_results(
            "SELECT action_type, SUM(cost_usd) as total_cost, COUNT(*) as api_calls
            FROM $table
            WHERE $date_condition
            GROUP BY action_type
            ORDER BY total_cost DESC"
        );

        // By provider
        $by_provider = $wpdb->get_results(
            "SELECT api_provider, SUM(cost_usd) as total_cost, COUNT(*) as api_calls
            FROM $table
            WHERE $date_condition
            GROUP BY api_provider
            ORDER BY total_cost DESC"
        );

        // Daily trend
        $daily_trend = $wpdb->get_results(
            "SELECT DATE(logged_at) as date, SUM(cost_usd) as total_cost
            FROM $table
            WHERE $date_condition
            GROUP BY DATE(logged_at)
            ORDER BY date ASC"
        );

        return [
            'by_platform' => $by_platform,
            'by_action' => $by_action,
            'by_provider' => $by_provider,
            'daily_trend' => $daily_trend,
        ];
    }

    /**
     * Get budget status
     *
     * @return array Budget status
     */
    public static function get_budget_status() {
        $settings = PIT_Settings::get_all();

        $week_spent = PIT_Database::get_total_costs('week');
        $month_spent = PIT_Database::get_total_costs('month');

        $weekly_budget = $settings['weekly_budget'] ?? 50;
        $monthly_budget = $settings['monthly_budget'] ?? 200;

        return [
            'weekly' => [
                'budget' => $weekly_budget,
                'spent' => $week_spent,
                'remaining' => max(0, $weekly_budget - $week_spent),
                'percentage' => $weekly_budget > 0 ? round(($week_spent / $weekly_budget) * 100, 2) : 0,
                'status' => self::get_budget_health($week_spent, $weekly_budget),
            ],
            'monthly' => [
                'budget' => $monthly_budget,
                'spent' => $month_spent,
                'remaining' => max(0, $monthly_budget - $month_spent),
                'percentage' => $monthly_budget > 0 ? round(($month_spent / $monthly_budget) * 100, 2) : 0,
                'status' => self::get_budget_health($month_spent, $monthly_budget),
            ],
        ];
    }

    /**
     * Get budget health status
     *
     * @param float $spent Amount spent
     * @param float $budget Budget limit
     * @return string Status (healthy/warning/critical/exceeded)
     */
    private static function get_budget_health($spent, $budget) {
        if ($budget <= 0) {
            return 'unlimited';
        }

        $percentage = ($spent / $budget) * 100;

        if ($percentage >= 100) {
            return 'exceeded';
        } elseif ($percentage >= 90) {
            return 'critical';
        } elseif ($percentage >= 75) {
            return 'warning';
        } else {
            return 'healthy';
        }
    }

    /**
     * Get cost forecast
     *
     * @return array Forecast data
     */
    public static function get_forecast() {
        global $wpdb;
        $table = $wpdb->prefix . 'pit_cost_log';

        // Get daily average for last 7 days
        $daily_avg = $wpdb->get_var(
            "SELECT AVG(daily_cost) FROM (
                SELECT DATE(logged_at) as date, SUM(cost_usd) as daily_cost
                FROM $table
                WHERE logged_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY DATE(logged_at)
            ) as daily_costs"
        );

        $daily_avg = (float) $daily_avg;

        // Calculate forecasts
        $days_left_in_week = 7 - (int) date('N');
        $days_left_in_month = (int) date('t') - (int) date('j');

        $week_spent = PIT_Database::get_total_costs('week');
        $month_spent = PIT_Database::get_total_costs('month');

        return [
            'daily_average' => $daily_avg,
            'week_forecast' => $week_spent + ($daily_avg * $days_left_in_week),
            'month_forecast' => $month_spent + ($daily_avg * $days_left_in_month),
        ];
    }

    /**
     * Get top spending podcasts
     *
     * @param int $limit Number of results
     * @return array Top podcasts
     */
    public static function get_top_spenders($limit = 10) {
        global $wpdb;
        $table_costs = $wpdb->prefix . 'pit_cost_log';
        $table_podcasts = $wpdb->prefix . 'pit_podcasts';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT p.id, p.podcast_name, SUM(c.cost_usd) as total_cost, COUNT(*) as api_calls
            FROM $table_costs c
            JOIN $table_podcasts p ON c.podcast_id = p.id
            GROUP BY p.id
            ORDER BY total_cost DESC
            LIMIT %d",
            $limit
        ));

        return $results;
    }

    /**
     * Get cost efficiency metrics
     *
     * @return array Efficiency metrics
     */
    public static function get_efficiency_metrics() {
        global $wpdb;
        $table_costs = $wpdb->prefix . 'pit_cost_log';
        $table_podcasts = $wpdb->prefix . 'pit_podcasts';

        $stats = [
            'total_podcasts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_podcasts"),
            'tracked_podcasts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_podcasts WHERE is_tracked = 1"),
            'total_spent' => (float) $wpdb->get_var("SELECT COALESCE(SUM(cost_usd), 0) FROM $table_costs"),
            'total_api_calls' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_costs"),
        ];

        $stats['avg_cost_per_podcast'] = $stats['tracked_podcasts'] > 0
            ? $stats['total_spent'] / $stats['tracked_podcasts']
            : 0;

        $stats['avg_cost_per_call'] = $stats['total_api_calls'] > 0
            ? $stats['total_spent'] / $stats['total_api_calls']
            : 0;

        // Calculate savings vs "scrape everything" approach
        $total_podcasts = $stats['total_podcasts'];
        $tracked_podcasts = $stats['tracked_podcasts'];

        // Assume $0.20 per podcast if we scraped everything
        $would_have_spent = $total_podcasts * 0.20;
        $actual_spent = $stats['total_spent'];
        $savings = max(0, $would_have_spent - $actual_spent);
        $savings_percent = $would_have_spent > 0 ? ($savings / $would_have_spent) * 100 : 0;

        $stats['savings'] = [
            'amount' => $savings,
            'percentage' => round($savings_percent, 2),
            'would_have_spent' => $would_have_spent,
        ];

        return $stats;
    }

    /**
     * Get date condition for SQL queries
     *
     * @param string $period Period
     * @return string SQL condition
     */
    private static function get_date_condition($period) {
        switch ($period) {
            case 'day':
                return 'DATE(logged_at) = CURDATE()';
            case 'week':
                return 'YEARWEEK(logged_at) = YEARWEEK(NOW())';
            case 'month':
                return 'YEAR(logged_at) = YEAR(NOW()) AND MONTH(logged_at) = MONTH(NOW())';
            case 'year':
                return 'YEAR(logged_at) = YEAR(NOW())';
            default:
                return '1=1';
        }
    }

    /**
     * Export cost data as CSV
     *
     * @param string $period Period
     * @return string CSV content
     */
    public static function export_csv($period = 'month') {
        global $wpdb;
        $table_costs = $wpdb->prefix . 'pit_cost_log';
        $table_podcasts = $wpdb->prefix . 'pit_podcasts';

        $date_condition = self::get_date_condition($period);

        $results = $wpdb->get_results(
            "SELECT c.*, p.podcast_name
            FROM $table_costs c
            LEFT JOIN $table_podcasts p ON c.podcast_id = p.id
            WHERE $date_condition
            ORDER BY c.logged_at DESC"
        );

        $csv = "Date,Podcast,Action,Platform,Provider,Cost (USD),Success\n";

        foreach ($results as $row) {
            $csv .= sprintf(
                "%s,%s,%s,%s,%s,%.4f,%s\n",
                $row->logged_at,
                str_replace(',', ' ', $row->podcast_name ?? 'N/A'),
                $row->action_type,
                $row->platform ?? 'N/A',
                $row->api_provider ?? 'N/A',
                $row->cost_usd,
                $row->success ? 'Yes' : 'No'
            );
        }

        return $csv;
    }
}
