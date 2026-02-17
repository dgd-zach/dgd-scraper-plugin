<?php
/**
 * WP-CLI Shim for Cron Execution
 *
 * Provides stub implementations of WP_CLI methods so the scraper command
 * can run during cron events (when real WP-CLI is not available).
 */

// Don't load if real WP-CLI is present
if (defined('WP_CLI') && WP_CLI) {
    return;
}

// Define WP_CLI constant so checks pass
define('WP_CLI', true);

/**
 * Stub WP_CLI class
 */
class WP_CLI {

    /**
     * Display a success message
     */
    public static function success($message) {
        error_log("WP_CLI SUCCESS: {$message}");
    }

    /**
     * Display an error message and optionally exit
     */
    public static function error($message, $exit = true) {
        error_log("WP_CLI ERROR: {$message}");
        if ($exit) {
            throw new Exception($message);
        }
    }

    /**
     * Display a warning message
     */
    public static function warning($message) {
        error_log("WP_CLI WARNING: {$message}");
    }

    /**
     * Display an informational message
     */
    public static function log($message) {
        error_log("WP_CLI LOG: {$message}");
    }

    /**
     * Display a line of text
     */
    public static function line($message = '') {
        error_log("WP_CLI: {$message}");
    }

    /**
     * Display debug information
     */
    public static function debug($message) {
        error_log("WP_CLI DEBUG: {$message}");
    }

    /**
     * Colorize a string (no-op in shim)
     */
    public static function colorize($string) {
        return $string;
    }

    /**
     * Add a command (no-op in shim)
     */
    public static function add_command($name, $callable) {
        // No-op
    }
}

/**
 * Stub progress bar class
 */
class WP_CLI_Progress_Bar {

    public function tick() {
        // No-op
    }

    public function finish() {
        // No-op
    }
}

/**
 * Stub WP_CLI\Utils class (defined in global namespace, aliased below)
 */
class WP_CLI_Utils_Shim {

    /**
     * Create a progress bar
     */
    public static function make_progress_bar($message, $count) {
        return new WP_CLI_Progress_Bar();
    }

    /**
     * Format items as table (stub - just return count)
     */
    public static function format_items($format, $items, $fields) {
        error_log("WP_CLI Table: " . count($items) . " items");
    }

    /**
     * Get a value from associative args with a default fallback
     *
     * @param array $assoc_args Associative arguments array
     * @param string $key Key to retrieve
     * @param mixed $default Default value if key doesn't exist
     * @return mixed The value or default
     */
    public static function get_flag_value($assoc_args, $key, $default = null) {
        return isset($assoc_args[$key]) ? $assoc_args[$key] : $default;
    }
}

// Create the WP_CLI\Utils namespace class using eval
// This is needed because namespace declarations must be at the top of the file
eval('
namespace WP_CLI {
    class Utils extends \WP_CLI_Utils_Shim {}
}
');
