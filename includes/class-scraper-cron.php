<?php
/**
 * Scraper Cron Scheduling
 *
 * Handles automatic scheduling of scraper profiles using WordPress WP-Cron
 */

class Scraper_Cron {

    /**
     * Constructor - Register hooks
     */
    public function __construct() {
        // Register custom cron intervals
        add_filter('cron_schedules', [$this, 'register_intervals']);

        // Register dynamic action hooks for all profiles
        $this->register_profile_hooks();
    }

    /**
     * Register action hooks for all profile cron events
     */
    private function register_profile_hooks() {
        $profiles = get_option('scraper_profiles', []);

        foreach ($profiles as $profile_key => $profile) {
            // Only register hooks for profiles with cron enabled
            if (!empty($profile['cron_enabled']) && $profile['cron_enabled'] == '1') {
                $hook_name = "dgd_scraper_cron_{$profile_key}";
                add_action($hook_name, function() use ($profile_key) {
                    self::run_profile($profile_key);
                });
            }
        }
    }

    /**
     * Execute a scraper profile
     *
     * This method is called when a cron event fires.
     * It loads the scraper command and executes it.
     */
    public static function run_profile($profile_key) {
        // Load the WP-CLI shim to make WP_CLI functions available
        require_once GWS_PLUGIN_DIR . 'includes/wp-cli-shim.php';

        // Load the scraper command class
        require_once GWS_PLUGIN_DIR . 'includes/class-scraper-command.php';

        // Create command instance
        $command = new Generic_Scraper_Command();

        // Build arguments
        $args = [];
        $assoc_args = [
            'profile' => $profile_key,
            'live' => true,
        ];

        try {
            // Execute the scraper
            $command->run($args, $assoc_args);
        } catch (Exception $e) {
            error_log("Cron scraper error for profile '{$profile_key}': " . $e->getMessage());
        }
    }

    /**
     * Register custom cron intervals
     *
     * WordPress by default only has hourly, twicedaily, daily.
     * We need to support custom frequencies like "2 times per day" or "3 times per week"
     */
    public function register_intervals($schedules) {
        // Generate intervals for various frequencies
        // Format: every_X_per_Y (e.g., every_2_per_day, every_3_per_week)

        $periods = [
            'hour' => HOUR_IN_SECONDS,
            'day' => DAY_IN_SECONDS,
            'week' => WEEK_IN_SECONDS,
        ];

        foreach ($periods as $period_name => $period_seconds) {
            // Support 1-24 times per period
            for ($frequency = 1; $frequency <= 24; $frequency++) {
                $interval_seconds = intval($period_seconds / $frequency);
                $interval_key = "every_{$frequency}_per_{$period_name}";

                $schedules[$interval_key] = [
                    'interval' => $interval_seconds,
                    'display' => sprintf(
                        __('%d time(s) per %s', 'generic-web-scraper'),
                        $frequency,
                        $period_name
                    ),
                ];
            }
        }

        return $schedules;
    }

    /**
     * Update cron schedules based on profile settings
     *
     * This is called whenever profiles are saved or deleted.
     * It synchronizes WordPress cron events with profile configurations.
     */
    public static function update_schedules() {
        $profiles = get_option('scraper_profiles', []);

        // Get list of currently scheduled events for this plugin
        $existing_hooks = self::get_scheduled_hooks();

        foreach ($profiles as $profile_key => $profile) {
            $hook_name = "dgd_scraper_cron_{$profile_key}";

            // Remove from existing list (so we know what's left to clean up)
            if (($key = array_search($hook_name, $existing_hooks)) !== false) {
                unset($existing_hooks[$key]);
            }

            // Check if cron is enabled for this profile
            // Use loose comparison to handle both string '1' and integer 1
            if (empty($profile['cron_enabled']) || $profile['cron_enabled'] != '1') {
                // Cron disabled - unschedule if it exists
                wp_clear_scheduled_hook($hook_name);
                continue;
            }

            // Cron is enabled - calculate interval
            $frequency = max(1, min(24, intval($profile['cron_frequency'] ?? 1)));
            $period = in_array($profile['cron_period'] ?? 'day', ['hour', 'day', 'week'])
                ? $profile['cron_period']
                : 'day';

            $interval_key = "every_{$frequency}_per_{$period}";

            // Check if already scheduled with the same interval
            $timestamp = wp_next_scheduled($hook_name);
            if ($timestamp) {
                $event = wp_get_scheduled_event($hook_name);
                if ($event && $event->schedule === $interval_key) {
                    // Already scheduled correctly - no changes needed
                    continue;
                }
                // Schedule changed - unschedule and reschedule
                wp_clear_scheduled_hook($hook_name);
            }

            // Calculate when the first run should be (one interval from now)
            $schedules = wp_get_schedules();
            $interval_seconds = isset($schedules[$interval_key]) ? $schedules[$interval_key]['interval'] : DAY_IN_SECONDS;
            $first_run = time() + $interval_seconds;

            // Schedule the event
            wp_schedule_event($first_run, $interval_key, $hook_name);
        }

        // Clean up any orphaned schedules (profiles that were deleted)
        foreach ($existing_hooks as $orphaned_hook) {
            wp_clear_scheduled_hook($orphaned_hook);
        }
    }

    /**
     * Get all currently scheduled hooks for this plugin
     *
     * @return array Array of hook names
     */
    private static function get_scheduled_hooks() {
        $hooks = [];
        $crons = _get_cron_array();

        if (empty($crons)) {
            return $hooks;
        }

        foreach ($crons as $timestamp => $cron) {
            foreach ($cron as $hook => $details) {
                // Only return hooks that belong to this plugin
                if (strpos($hook, 'dgd_scraper_cron_') === 0) {
                    $hooks[] = $hook;
                }
            }
        }

        return array_unique($hooks);
    }

    /**
     * Clear all scheduled events for this plugin
     *
     * Called during plugin deactivation
     */
    public static function clear_all_schedules() {
        $hooks = self::get_scheduled_hooks();

        foreach ($hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }
}
