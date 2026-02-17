<?php
/**
 * Debug script to check cron scheduling
 *
 * USAGE: wp eval-file debug-schedule.php
 */

require_once __DIR__ . '/includes/class-scraper-cron.php';

echo "=== CHECKING CRON SCHEDULES ===\n\n";

// Get all registered schedules
$schedules = wp_get_schedules();

echo "Looking for 'every_1_per_day' interval:\n";
if (isset($schedules['every_1_per_day'])) {
    echo "✓ Found! Interval: " . $schedules['every_1_per_day']['interval'] . " seconds (" .
         ($schedules['every_1_per_day']['interval'] / 3600) . " hours)\n";
    echo "  Display: " . $schedules['every_1_per_day']['display'] . "\n";
} else {
    echo "✗ NOT FOUND - This is the problem!\n";
}

echo "\nLooking for 'every_2_per_day' interval:\n";
if (isset($schedules['every_2_per_day'])) {
    echo "✓ Found! Interval: " . $schedules['every_2_per_day']['interval'] . " seconds (" .
         ($schedules['every_2_per_day']['interval'] / 3600) . " hours)\n";
} else {
    echo "✗ NOT FOUND\n";
}

echo "\nLooking for 'every_1_per_week' interval:\n";
if (isset($schedules['every_1_per_week'])) {
    echo "✓ Found! Interval: " . $schedules['every_1_per_week']['interval'] . " seconds (" .
         ($schedules['every_1_per_week']['interval'] / 86400) . " days)\n";
} else {
    echo "✗ NOT FOUND\n";
}

echo "\n=== ALL CUSTOM INTERVALS (every_*_per_*) ===\n";
foreach ($schedules as $key => $schedule) {
    if (strpos($key, 'every_') === 0 && strpos($key, '_per_') !== false) {
        echo "  $key: {$schedule['interval']} seconds ({$schedule['display']})\n";
    }
}

echo "\n=== CHECKING SCHEDULED EVENTS ===\n";
$profiles = get_option('scraper_profiles', []);
foreach ($profiles as $profile_key => $profile) {
    if (!empty($profile['cron_enabled']) && $profile['cron_enabled'] == '1') {
        $hook_name = "dgd_scraper_cron_{$profile_key}";
        $next_run = wp_next_scheduled($hook_name);
        $event = wp_get_scheduled_event($hook_name);

        echo "\nProfile: {$profile['profile_name']} ($profile_key)\n";
        echo "  Configured: {$profile['cron_frequency']} time(s) per {$profile['cron_period']}\n";
        echo "  Expected interval key: every_{$profile['cron_frequency']}_per_{$profile['cron_period']}\n";

        if ($next_run && $event) {
            echo "  Actual schedule: {$event->schedule}\n";
            echo "  Next run: " . date('Y-m-d H:i:s', $next_run) . "\n";
            echo "  Time until: " . human_time_diff($next_run, time()) . "\n";

            // Check if interval matches
            $expected = "every_{$profile['cron_frequency']}_per_{$profile['cron_period']}";
            if ($event->schedule === $expected) {
                echo "  ✓ Schedule matches configuration\n";
            } else {
                echo "  ✗ MISMATCH! Expected '$expected', got '{$event->schedule}'\n";
            }
        } else {
            echo "  ✗ NOT SCHEDULED\n";
        }
    }
}

echo "\n";
