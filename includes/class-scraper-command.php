<?php
/**
 * Generic Web Content Scraper - WP-CLI Command with Profiles Support
 * 
 * INSTALLATION:
 * 
 * 1. If you don't currently have WP-CLI installed on your machine, follow the install guide here:
 * https://make.wordpress.org/cli/handbook/guides/installing/
 * 
 * 2. Place this file in: wp-content/mu-plugins/wp-scaper-tool.php
 *    (create the mu-plugins directory if it doesn't exist)
 * 
 * USAGE WITH PROFILES:
 * 
 * # Use a saved profile
 * wp scraper run --profile=profile-name [--live]
 * 
 * # Examples
 * wp scraper run --profile=press-releases --live
 * wp scraper run --profile=blog-posts
 * 
 * # List all available profiles
 * wp scraper list-profiles
 * 
 * USAGE WITH COMMAND-LINE PARAMETERS (Original method still works):
 * 
 * wp scraper run \
 *   --source-url=<url> \
 *   --post-type=<type> \
 *   --listing-xpath=<xpath> \
 *   --title-xpath=<xpath> \
 *   --content-xpath=<xpath> \
 *   --date-xpath=<xpath> \
 *   [--live]
 * 
 * EXAMPLES:
 * 
 * # Use a profile (dry run)
 * wp scraper run --profile=news-articles
 * 
 * # Use a profile (live run)
 * wp scraper run --profile=press-releases --live
 * 
 * # Override profile settings
 * wp scraper run --profile=blog-posts --max-pages=10 --live
 * 
 * # List all profiles
 * wp scraper list-profiles
 */

if (!class_exists('WP_CLI')) {
    return;
}

class Generic_Scraper_Command {
    
    private $config = [];
    private $stats = [
        'processed' => 0,
        'created' => 0,
        'skipped' => 0,
        'errors' => 0
    ];
    private $is_dry_run = true; // Default to dry run

    private function make_progress_bar($message, $count) {
        if (class_exists('WP_CLI\Utils') && method_exists('WP_CLI\Utils', 'make_progress_bar')) {
            return \WP_CLI\Utils::make_progress_bar($message, $count);
        }
        return new class {
            public function tick() {}
            public function finish() {}
        };
    }

    private function get_flag_value($assoc_args, $key, $default = null) {
        if (class_exists('WP_CLI\Utils') && method_exists('WP_CLI\Utils', 'get_flag_value')) {
            return \WP_CLI\Utils::get_flag_value($assoc_args, $key, $default);
        }
        return isset($assoc_args[$key]) ? $assoc_args[$key] : $default;
    }

    private function format_items($format, $items, $fields) {
        if (class_exists('WP_CLI\Utils') && method_exists('WP_CLI\Utils', 'format_items')) {
            \WP_CLI\Utils::format_items($format, $items, $fields);
        }
    }

    /**
     * Scrape and import content from a website
     *
     * @when after_wp_load
     */
    public function run($args, $assoc_args) {
        // Validate and load configuration
        if (!$this->load_config($assoc_args)) {
            WP_CLI::error('Configuration validation failed. Check required parameters.');
        }
        
        // Display configuration
        $this->display_config();

        // Get or create categories if specified
        $category_ids = [];
        if (!empty($this->config['categories'])) {
            $categories = array_map('trim', explode(',', $this->config['categories']));
            foreach ($categories as $category_name) {
                if (!empty($category_name)) {
                    $cat_id = $this->get_or_create_category($category_name);
                    $category_ids[] = $cat_id;
                }
            }
            WP_CLI::line('Category IDs: ' . implode(', ', $category_ids));
        }
        
        // Get or create tags if specified
        $tag_ids = [];
        if (!empty($this->config['tags'])) {
            $tags = array_map('trim', explode(',', $this->config['tags']));
            foreach ($tags as $tag_name) {
                if (!empty($tag_name)) {
                    $tag_id = $this->get_or_create_tag($tag_name);
                    if ($tag_id) {
                        $tag_ids[] = $tag_id;
                    }
                }
            }
            if (!empty($tag_ids)) {
                WP_CLI::line('Tag IDs: ' . implode(', ', $tag_ids));
            }
        }
        WP_CLI::line('');
        
        // Get all content URLs
        WP_CLI::line('Fetching content URLs...');
        $content_urls = $this->get_all_content_urls();
        
        if (empty($content_urls)) {
            WP_CLI::error('No content found!');
        }
        
        WP_CLI::success('Found ' . count($content_urls) . ' items');
        WP_CLI::line('');
        WP_CLI::line(WP_CLI::colorize('%YTip: Press Ctrl+C at any time to stop and see results%n'));
        WP_CLI::line('');
        
        // Set up signal handler for graceful shutdown
        $interrupted = false;
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function() use (&$interrupted) {
                $interrupted = true;
                WP_CLI::line('');
                WP_CLI::line(WP_CLI::colorize('%Y⚠ Interrupt received - finishing current item then stopping...%n'));
            });
        }
        
        // Determine batch size (0 means process all)
        $batch_limit = $this->config['posts_per_batch'];
        if ($batch_limit <= 0) {
            $batch_limit = count($content_urls); // Process all
            WP_CLI::line(WP_CLI::colorize('%YProcessing ALL items found (' . $batch_limit . ' total)%n'));
            WP_CLI::line('');
        }
        
        // Process content with progress bar
        $batch_size = min($batch_limit, count($content_urls));
        // $progress = \WP_CLI\Utils::make_progress_bar('Processing content', $batch_size);
        $progress = $this->make_progress_bar('Processing content', $batch_size);
        
        
        $processed_count = 0;
        foreach ($content_urls as $url) {
            // Check for interrupt signal
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            
            if ($interrupted) {
                WP_CLI::line('');
                WP_CLI::warning('Stopped by user interrupt');
                break;
            }
            
            if ($processed_count >= $batch_limit) {
                WP_CLI::warning('Batch limit reached (' . $batch_limit . ' items). Run again to continue.');
                break;
            }
            
            try {
                $this->process_content($url, $category_ids, $tag_ids);
            } catch (Exception $e) {
                WP_CLI::warning('Exception: ' . $e->getMessage());
                $this->stats['errors']++;
            }
            
            $processed_count++;
            $progress->tick();
            
            // Small delay to be respectful
            usleep(500000); // 0.5 seconds
        }

        $progress->finish();

        // Display results
        $this->display_results();
    }

    /**
     * List all available scraper profiles
     * 
     * ## EXAMPLES
     * 
     *     wp scraper list-profiles
     * 
     * @when after_wp_load
     */
    public function list_profiles($args, $assoc_args) {
        $profiles = get_option('scraper_profiles', []);
        
        if (empty($profiles)) {
            WP_CLI::warning('No profiles found. Create profiles in Tools → Scraper Settings.');
            return;
        }
        
        WP_CLI::line('');
        WP_CLI::line(WP_CLI::colorize('%G=== Available Scraper Profiles ===%n'));
        WP_CLI::line('');
        
        $table_data = [];
        foreach ($profiles as $key => $profile) {
            $table_data[] = [
                'Key' => $key,
                'Name' => $profile['profile_name'],
                'Source' => $profile['source_url'],
                'Post Type' => $profile['post_type'],
                'Command' => 'wp scraper run --profile=' . $key
            ];
        }
        
        // WP_CLI\Utils::format_items('table', $table_data, ['Key', 'Name', 'Source', 'Post Type', 'Command']);
        $this->format_items('table', $table_data, ['Key', 'Name', 'Source', 'Post Type', 'Command']);
        
        WP_CLI::line('');
        WP_CLI::line('Usage: wp scraper run --profile=<key> [--live]');
        WP_CLI::line('');
    }

    /**
     * Load and validate configuration from command arguments
     */
    private function load_config($assoc_args) {
        // Check if profile flag is provided
        if (isset($assoc_args['profile'])) {
            return $this->load_profile_config($assoc_args);
        }
        
        // Check if all required CLI parameters are provided
        $required = ['source-url', 'post-type', 'listing-xpath', 'title-xpath', 'content-xpath', 'date-xpath'];
        $has_cli_params = true;
        foreach ($required as $param) {
            if (empty($assoc_args[$param])) {
                $has_cli_params = false;
                break;
            }
        }
        
        if ($has_cli_params) {
            // Use command-line parameters
            WP_CLI::line('');
            WP_CLI::line(WP_CLI::colorize('%G✓ Using command-line parameters%n'));
            WP_CLI::line('');
            return $this->load_cli_config($assoc_args);
        }
        
        // No valid configuration found
        WP_CLI::error(
            "No configuration found. Either:\n" .
            "  1. Use a profile: wp scraper run --profile=profile-name --live\n" .
            "  2. Provide all required parameters in command line\n" .
            "  3. Configure settings in Tools → Scraper Settings\n\n" .
            "Run 'wp scraper list-profiles' to see available profiles."
        );
        return false;
    }
    
    /**
     * Load configuration from a saved profile
     */
    private function load_profile_config($assoc_args) {
        $profile_key = sanitize_key($assoc_args['profile']);
        $profiles = get_option('scraper_profiles', []);
        
        if (!isset($profiles[$profile_key])) {
            WP_CLI::error(
                "Profile '{$profile_key}' not found.\n\n" .
                "Run 'wp scraper list-profiles' to see available profiles."
            );
            return false;
        }
        
        $profile = $profiles[$profile_key];
        
        // Map profile data to config
        $this->config = [
            'source_url' => rtrim($profile['source_url'], '/'),
            'post_type' => $profile['post_type'],
            'listing_xpath' => $profile['listing_xpath'],
            'title_xpath' => $profile['title_xpath'],
            'content_xpath' => $profile['content_xpath'],
            'date_xpath' => $profile['date_xpath'],
            'excerpt_xpath' => $profile['excerpt_xpath'] ?? '',
            'image_xpath' => $profile['image_xpath'] ?? '',
            'start_page' => max(1, intval($profile['start_page'] ?? 1)),
            'max_pages' => max(1, intval($profile['max_pages'] ?? 1)),
            'posts_per_batch' => intval($profile['posts_per_batch'] ?? 0),
            'post_status' => $profile['post_status'] ?? 'publish',
            'categories' => $profile['categories'] ?? '',
            'tags' => $profile['tags'] ?? '',
            'page_param' => $profile['page_param'] ?? 'page',
            'content_filter_mode' => $profile['content_filter_mode'] ?? 'exclude',
            'include_xpaths' => [],
            'remove_xpaths' => [],
            'fallback_image_id' => intval($profile['fallback_image_id'] ?? 0),
            'custom_css' => $profile['custom_css'] ?? '',
        ];

        // Parse include_xpaths from string (one per line) to array
        if (!empty($profile['include_xpaths'])) {
            $include_lines = explode("\n", $profile['include_xpaths']);
            $this->config['include_xpaths'] = array_filter(array_map('trim', $include_lines));
        }

        // Parse remove_xpaths from string (one per line) to array
        if (!empty($profile['remove_xpaths'])) {
            $remove_lines = explode("\n", $profile['remove_xpaths']);
            $this->config['remove_xpaths'] = array_filter(array_map('trim', $remove_lines));
        }
        
        // Allow command-line overrides
        if (isset($assoc_args['start-page'])) {
            $this->config['start_page'] = max(1, intval($assoc_args['start-page']));
        }
        if (isset($assoc_args['max-pages'])) {
            $this->config['max_pages'] = max(1, intval($assoc_args['max-pages']));
        }
        if (isset($assoc_args['posts-per-batch'])) {
            $this->config['posts_per_batch'] = intval($assoc_args['posts-per-batch']);
        }
        if (isset($assoc_args['status'])) {
            $this->config['post_status'] = $assoc_args['status'];
        }
        
        // Check for live flag
        // $this->is_dry_run = !WP_CLI\Utils::get_flag_value($assoc_args, 'live', false);
        // $this->is_dry_run = empty($assoc_args['live']);
        // $this->is_dry_run = !$this->get_flag_value($assoc_args, 'live', false);
        $this->is_dry_run = empty($assoc_args['live']);
        
        WP_CLI::line('');
        WP_CLI::line(WP_CLI::colorize('%G✓ Loaded profile: ' . $profile['profile_name'] . '%n'));
        WP_CLI::line('');
        
        return true;
    }
    
    /**
     * Load configuration from command-line arguments
     */
    private function load_cli_config($assoc_args) {
        // Parse and validate configuration
        $this->config = [
            'source_url' => rtrim($assoc_args['source-url'], '/'),
            'post_type' => $assoc_args['post-type'],
            'listing_xpath' => $assoc_args['listing-xpath'],
            'title_xpath' => $assoc_args['title-xpath'],
            'content_xpath' => $assoc_args['content-xpath'],
            'date_xpath' => $assoc_args['date-xpath'],
            'excerpt_xpath' => $this->get_flag_value($assoc_args, 'excerpt-xpath', ''),
            'image_xpath' => $this->get_flag_value($assoc_args, 'image-xpath', ''),
            'start_page' => max(1, intval($this->get_flag_value($assoc_args, 'start-page', 1))),
            'max_pages' => max(1, intval($this->get_flag_value($assoc_args, 'max-pages', 1))),
            'posts_per_batch' => intval($this->get_flag_value($assoc_args, 'posts-per-batch', 0)),
            'post_status' => $this->get_flag_value($assoc_args, 'status', 'publish'),
            'categories' => $this->get_flag_value($assoc_args, 'categories', ''),
            'tags' => $this->get_flag_value($assoc_args, 'tags', ''),
            'page_param' => $this->get_flag_value($assoc_args, 'page-param', 'page'),
            'remove_xpaths' => [],
            'fallback_image_id' => intval($this->get_flag_value($assoc_args, 'fallback-image-id', 0)),
        ];
        
        // Handle multiple remove-xpath parameters
        if (isset($assoc_args['remove-xpath'])) {
            if (is_array($assoc_args['remove-xpath'])) {
                $this->config['remove_xpaths'] = $assoc_args['remove-xpath'];
            } else {
                $this->config['remove_xpaths'] = [$assoc_args['remove-xpath']];
            }
        }
        
        // Dry run is default - must explicitly add --live flag to create posts
        // $this->is_dry_run = !WP_CLI\Utils::get_flag_value($assoc_args, 'live', false);
        $this->is_dry_run = empty($assoc_args['live']);
        
        // Validate post status
        if (!in_array($this->config['post_status'], ['publish', 'draft'])) {
            WP_CLI::error("Invalid status. Use 'publish' or 'draft'");
            return false;
        }
        
        return true;
    }

    /**
     * Display current configuration
     */
    private function display_config() {
        WP_CLI::line('');
        WP_CLI::line(WP_CLI::colorize('%G=== Generic Web Scraper ===%n'));
        WP_CLI::line('');
        WP_CLI::line('Configuration:');
        WP_CLI::line('  Site URL: ' . get_site_url());
        WP_CLI::line('  Database: ' . DB_NAME . '@' . DB_HOST);
        WP_CLI::line('  Source URL: ' . $this->config['source_url']);
        WP_CLI::line('  Post Type: ' . $this->config['post_type']);
        
        // Show current user info
        $current_user = wp_get_current_user();
        if ($current_user->ID) {
            WP_CLI::line('  Post Author: ' . $current_user->user_login . ' (ID: ' . $current_user->ID . ')');
        }
        
        WP_CLI::line('  Start Page: ' . $this->config['start_page']);
        WP_CLI::line('  Max Pages: ' . $this->config['max_pages']);
        $batch_display = ($this->config['posts_per_batch'] <= 0) ? 'All' : $this->config['posts_per_batch'];
        WP_CLI::line('  Posts Per Batch: ' . $batch_display);
        WP_CLI::line('  Post Status: ' . $this->config['post_status']);
        if (!empty($this->config['categories'])) {
            WP_CLI::line('  Categories: ' . $this->config['categories']);
        }
        if (!empty($this->config['tags'])) {
            WP_CLI::line('  Tags: ' . $this->config['tags']);
        }
        WP_CLI::line('  Mode: ' . ($this->is_dry_run ? WP_CLI::colorize('%YDRY RUN%n (no posts will be created)') : WP_CLI::colorize('%GLIVE%n (posts will be created)')));
        if ($this->is_dry_run) {
            WP_CLI::line('  ' . WP_CLI::colorize('%YAdd --live flag to actually create posts%n'));
        }
        WP_CLI::line('');
    }

    /**
     * Get all content URLs from paginated listing pages
     */
    private function get_all_content_urls() {
        $urls = [];
        $start_page = $this->config['start_page'];
        $max_pages = $this->config['max_pages'];
        $batch_limit = $this->config['posts_per_batch'];
        
        // If batch_limit is 0, get all URLs from the page range
        if ($batch_limit <= 0) {
            for ($page = $start_page; $page < $start_page + $max_pages; $page++) {
                $page_url = $this->build_page_url($page);
                
                WP_CLI::debug("Fetching page $page: $page_url");
                
                $html = $this->fetch_url($page_url);
                if (!$html) {
                    WP_CLI::warning("Failed to fetch page $page");
                    break;
                }
                
                $page_urls = $this->extract_content_urls($html);
                
                if (empty($page_urls)) {
                    WP_CLI::debug("No content found on page $page");
                    break;
                }
                
                $urls = array_merge($urls, $page_urls);
                WP_CLI::debug("Found " . count($page_urls) . " items on page $page");
            }
            
            return array_unique($urls);
        }
        
        // If batch_limit is set, filter out already-imported posts and keep fetching
        // until we have enough new posts or run out of pages
        $new_urls = [];
        $page = $start_page;
        $pages_checked = 0;
        $max_pages_to_check = $max_pages * 3; // Safety limit to prevent infinite loops
        
        while (count($new_urls) < $batch_limit && $pages_checked < $max_pages_to_check) {
            $page_url = $this->build_page_url($page);
            
            WP_CLI::debug("Fetching page $page: $page_url (looking for " . ($batch_limit - count($new_urls)) . " more new posts)");
            
            $html = $this->fetch_url($page_url);
            if (!$html) {
                WP_CLI::warning("Failed to fetch page $page");
                break;
            }
            
            $page_urls = $this->extract_content_urls($html);
            
            if (empty($page_urls)) {
                WP_CLI::debug("No content found on page $page");
                break;
            }
            
            // Filter out already-imported posts
            foreach ($page_urls as $url) {
                if (count($new_urls) >= $batch_limit) {
                    break;
                }
                
                // Check if this URL has already been imported
                if (!$this->post_exists_by_source_url($url)) {
                    $new_urls[] = $url;
                } else {
                    WP_CLI::debug("Skipping already imported: $url");
                }
            }
            
            WP_CLI::debug("Page $page: Found " . count($page_urls) . " total, " . count($new_urls) . " new so far");
            
            $page++;
            $pages_checked++;
        }
        
        return array_unique($new_urls);
    }

    /**
     * Build URL for a specific page number
     * Supports both query parameter and path-based pagination
     */
    private function build_page_url($page) {
        if ($page === 1) {
            return $this->config['source_url'];
        }
        
        $param = $this->config['page_param'];
        
        // Check if page_param contains a "/" - indicates path-based pagination
        if (strpos($param, '/') !== false) {
            // Path-based pagination (e.g., /page/2/)
            // Replace placeholder with actual page number
            $pagination_path = str_replace('{page}', $page, $param);
            return rtrim($this->config['source_url'], '/') . $pagination_path;
        }
        
        // Query parameter pagination (e.g., ?page=2)
        $separator = (strpos($this->config['source_url'], '?') !== false) ? '&' : '?';
        return $this->config['source_url'] . $separator . $param . '=' . $page;
    }

    /**
     * Extract content URLs from listing page HTML
     */
    private function extract_content_urls($html) {
        $urls = [];
        
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        
        // Debug: Show the XPath being used
        WP_CLI::debug('Listing XPath: ' . $this->config['listing_xpath']);
        
        // Suppress warnings and catch errors
        $links = @$xpath->query($this->config['listing_xpath']);
        
        // Check if query failed
        if ($links === false) {
            WP_CLI::error(
                "Invalid XPath expression for listing_xpath.\n" .
                "XPath provided: " . $this->config['listing_xpath'] . "\n\n" .
                "Common XPath syntax errors:\n" .
                "  - Missing quotes: [@class=test] should be [@class=\"test\"]\n" .
                "  - Unmatched brackets: //div[@class=\"test\" should be //div[@class=\"test\"]\n" .
                "  - Invalid characters in the expression\n\n" .
                "Please check your 'Listing XPath' setting in Tools → Scraper Settings"
            );
            return [];
        }
        
        WP_CLI::debug('XPath query returned ' . $links->length . ' results');
        
        foreach ($links as $link) {
            $href = trim($link->getAttribute('href'));
            if ($href) {
                // Make URL absolute if relative
                if (strpos($href, 'http') !== 0) {
                    // Extract base URL (scheme + host only) from source_url
                    $parsed = parse_url($this->config['source_url']);
                    $base_url = $parsed['scheme'] . '://' . $parsed['host'];
                    
                    if ($href[0] !== '/') { 
                        $href = '/' . $href; 
                    }
                    $href = $base_url . $href;
                }
                $urls[] = $href;
                WP_CLI::debug('Found URL: ' . $href);
            }
        }
        
        return array_values(array_unique($urls));
    }

    /**
     * Process individual content item
     */
    private function process_content($url, $category_ids, $tag_ids) {
        $this->stats['processed']++;
        
        WP_CLI::debug('Processing: ' . $url);
        
        $html = $this->fetch_url($url);
        if (!$html) {
            WP_CLI::warning('Failed to fetch URL: ' . $url);
            $this->stats['errors']++;
            return;
        }
        
        $data = $this->extract_content_data($html, $url);
        
        if (!$data) {
            WP_CLI::warning('Failed to extract data from: ' . $url);
            $this->stats['errors']++;
            return;
        }
        
        // Store full HTML for image extraction
        $data['full_html'] = $html;
        
        // Check if already exists
        $existing_id = $this->post_exists_by_source_url($data['source_url']);
        if ($existing_id) {
            $status = get_post_status($existing_id);
            WP_CLI::debug("Already imported as post ID $existing_id (status: $status)");
            $this->stats['skipped']++;
            return;
        }
        
        // Create post
        $post_id = $this->create_post($data, $category_ids, $tag_ids);
        
        if ($post_id) {
            if ($this->is_dry_run) {
                WP_CLI::line('');
                WP_CLI::line(WP_CLI::colorize('%Y[DRY RUN]%n Would create: ' . $data['title']));
            } else {
                WP_CLI::line('');
                WP_CLI::line(WP_CLI::colorize('%G✓%n Created post ID ' . $post_id . ': ' . $data['title']));
            }
            $this->stats['created']++;
        } else {
            WP_CLI::warning('Failed to create post: ' . $data['title']);
            $this->stats['errors']++;
        }
    }

    /**
     * Extract content data from HTML using configured XPath selectors
     */
    private function extract_content_data($html, $url) {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($dom);
        
        $data = [
            'title' => '',
            'content' => '',
            'date' => '',
            'excerpt' => '',
            'source_url' => $url
        ];
        
        // Extract title
        $title_node = $xpath->query($this->config['title_xpath'])->item(0);
        if ($title_node) { 
            $data['title'] = trim($title_node->textContent); 
        }
        
        // Extract date
        WP_CLI::debug('Date XPath: ' . $this->config['date_xpath']);
        $date_nodes = @$xpath->query($this->config['date_xpath']);
        $date_node = $date_nodes ? $date_nodes->item(0) : null;
        if ($date_node) {
            $date_text = $this->normalize_date_text($date_node->textContent);
            WP_CLI::debug('Raw date text: "' . $date_text . '"');
            // Skip placeholder values like "-" that indicate JS-rendered dates
            if ($date_text && $date_text !== '-') {
                $data['date'] = $this->parse_date($date_text);
                WP_CLI::debug('Parsed date: ' . ($data['date'] ?: '(empty - parse failed)'));
            } else {
                WP_CLI::debug('Date XPath matched but text is a placeholder ("' . $date_text . '"), trying JSON-LD fallback');
            }
        } else {
            WP_CLI::debug('Date XPath returned no results');
        }

        // Fallback: extract date from JSON-LD structured data
        if (empty($data['date'])) {
            $json_ld_result = $this->extract_date_from_json_ld($xpath);
            if ($json_ld_result) {
                $data['date'] = $json_ld_result['date'];
                $data['timezone'] = $json_ld_result['timezone'];
                WP_CLI::debug('Date from JSON-LD: ' . $data['date'] . ' ' . $data['timezone']);
            }
        }

        if (empty($data['date'])) {
            $data['date'] = current_time('mysql');
            WP_CLI::debug('Using fallback date (current time): ' . $data['date']);
        }
        
        // Extract content
        $content_node = $xpath->query($this->config['content_xpath'])->item(0);

        if ($content_node) {
            $content_clone = $content_node->cloneNode(true);
            $temp_dom = new DOMDocument();
            @$temp_dom->loadHTML('<?xml encoding="UTF-8">' . $dom->saveHTML($content_clone));
            $temp_xpath = new DOMXPath($temp_dom);

            $filter_mode = $this->config['content_filter_mode'] ?? 'exclude';
            WP_CLI::debug('Content filter mode: ' . $filter_mode);

            if ($filter_mode === 'include' && !empty($this->config['include_xpaths'])) {
                // INCLUDE MODE: Only keep elements matching the include XPaths
                // Supports optional wrapping HTML via pipe syntax:
                //   //xpath | <opening> | </closing>
                WP_CLI::debug('Include XPaths: ' . count($this->config['include_xpaths']) . ' selector(s) configured');
                $content_html = '';
                foreach ($this->config['include_xpaths'] as $include_line) {
                    // Raw HTML injection: lines starting with "html:" output literal HTML
                    if (strpos($include_line, 'html:') === 0) {
                        $raw_html = trim(substr($include_line, 5));
                        WP_CLI::debug('Injecting raw HTML: ' . $raw_html);
                        $content_html .= $raw_html;
                        continue;
                    }

                    // Parse pipe syntax: xpath | open_html | close_html
                    $parts = array_map('trim', explode('|', $include_line));
                    $include_xpath = $parts[0];
                    $wrap_open = isset($parts[1]) && $parts[1] !== '' ? $parts[1] : '';
                    $wrap_close = isset($parts[2]) && $parts[2] !== '' ? $parts[2] : '';

                    WP_CLI::debug('Include XPath: "' . $include_xpath . '"' . ($wrap_open ? ' wrapped in ' . $wrap_open . '...' . $wrap_close : ''));
                    $included_nodes = @$temp_xpath->query($include_xpath);
                    if ($included_nodes === false) {
                        WP_CLI::debug('  ✗ Invalid XPath expression');
                        continue;
                    }
                    WP_CLI::debug('  Matched ' . $included_nodes->length . ' node(s)');
                    foreach ($included_nodes as $node) {
                        WP_CLI::debug('  Including <' . $node->nodeName . '> (' . substr(trim($node->textContent), 0, 80) . ')');
                        $node_html = $temp_dom->saveHTML($node);
                        $content_html .= $wrap_open . $node_html . $wrap_close;
                    }
                }
                $data['content'] = $this->clean_content($content_html);
            } else {
                // EXCLUDE MODE: Remove matching elements (default behavior)
                if (empty($this->config['remove_xpaths'])) {
                    WP_CLI::debug('Remove XPaths: none configured');
                } else {
                    WP_CLI::debug('Remove XPaths: ' . count($this->config['remove_xpaths']) . ' selector(s) configured');
                }
                foreach ($this->config['remove_xpaths'] as $remove_xpath) {
                    WP_CLI::debug('Remove XPath: "' . $remove_xpath . '"');
                    $nodes_to_remove = @$temp_xpath->query($remove_xpath);
                    if ($nodes_to_remove === false) {
                        WP_CLI::debug('  ✗ Invalid XPath expression');
                        continue;
                    }
                    WP_CLI::debug('  Matched ' . $nodes_to_remove->length . ' node(s)');
                    foreach ($nodes_to_remove as $node) {
                        if ($node->parentNode) {
                            WP_CLI::debug('  Removing <' . $node->nodeName . '> (' . substr(trim($node->textContent), 0, 80) . ')');
                            $node->parentNode->removeChild($node);
                        }
                    }
                }

                // Get cleaned content
                $temp_content_node = $temp_xpath->query('//*')->item(0);
                if ($temp_content_node) {
                    $content_html = '';
                    foreach ($temp_content_node->childNodes as $child) {
                        $content_html .= $temp_dom->saveHTML($child);
                    }
                    $data['content'] = $this->clean_content($content_html);
                }
            }
        }
        
        // Extract excerpt (use custom xpath or generate from content)
        if (!empty($this->config['excerpt_xpath'])) {
            $excerpt_node = $xpath->query($this->config['excerpt_xpath'])->item(0);
            if ($excerpt_node) {
                $data['excerpt'] = wp_trim_words(trim($excerpt_node->textContent), 55, '...');
            }
        } elseif (!empty($data['content'])) {
            // Generate excerpt from first substantial paragraph
            $first_p = $xpath->query('//p[string-length(normalize-space()) > 50][1]')->item(0);
            if ($first_p) {
                $excerpt_text = trim($first_p->textContent);
                $data['excerpt'] = wp_trim_words($excerpt_text, 55, '...');
            }
        }
        
        // Validate
        if (empty($data['title']) || empty($data['content'])) {
            return false;
        }
        
        return $data;
    }

    /**
     * Clean up content HTML
     */
    private function clean_content($content_html) {
        if (empty($content_html)) {
            return '';
        }
        
        // Extract and preserve <pre> blocks
        $pre_blocks = [];
        $pre_count = 0;
        
        $content_html = preg_replace_callback(
            '/<pre[^>]*>.*?<\/pre>/is',
            function($matches) use (&$pre_blocks, &$pre_count) {
                $placeholder = '<!--PRE_BLOCK_' . $pre_count . '-->';
                $pre_blocks[$placeholder] = $matches[0];
                $pre_count++;
                return $placeholder;
            },
            $content_html
        );
        
        // Clean up whitespace and formatting
        $content_html = str_replace(["\r\n", "\r", "\n"], " ", $content_html);
        $content_html = preg_replace('/\s\s+/', ' ', $content_html);
        $content_html = preg_replace('/<br\s*\/?>\s*<br\s*\/?>/i', '<br>', $content_html);
        $content_html = preg_replace('/<p([^>]*)>\s*<br\s*\/?>/i', '<p$1>', $content_html);
        $content_html = preg_replace('/<br\s*\/?>\s*<\/p>/i', '</p>', $content_html);
        $content_html = preg_replace('/>\s+</', '><', $content_html);
        
        // Restore <pre> blocks
        foreach ($pre_blocks as $placeholder => $pre_content) {
            $content_html = str_replace($placeholder, $pre_content, $content_html);
        }
        
        return $content_html;
    }

    
    /**
     * Create WordPress post
     */
    private function create_post($data, $category_ids, $tag_ids) {
        // Get current user ID (the user running the WP-CLI command)
        $current_user_id = get_current_user_id();
        
        // If no user is set (shouldn't happen in WP-CLI context), fallback to admin
        if (!$current_user_id) {
            $admin_users = get_users(['role' => 'administrator', 'number' => 1]);
            $current_user_id = !empty($admin_users) ? $admin_users[0]->ID : 1;
        }
        
        // Prepend custom CSS to content if configured
        $post_content = $data['content'];
        if (!empty($this->config['custom_css'])) {
            $post_content = '<style>' . $this->config['custom_css'] . '</style>' . "\n" . $post_content;
            WP_CLI::debug('Prepended custom CSS to content');
        }

        $post_data = [
            'post_title'    => wp_strip_all_tags($data['title']),
            'post_content'  => $post_content,
            'post_excerpt'  => $data['excerpt'],
            'post_status'   => $this->config['post_status'],
            'post_type'     => $this->config['post_type'],
            'post_date'     => $data['date'],
            'post_author'   => $current_user_id,
        ];
        
        // Test image extraction even in dry run mode (for debugging)
        // Use content only (not full HTML) to avoid grabbing header logos/navigation images
        $html_for_image = $data['content'];
        $full_html = isset($data['full_html']) ? $data['full_html'] : null;
        
        WP_CLI::debug('Testing image extraction...');
        $test_image_url = $this->extract_first_image_url($html_for_image, $full_html);
        
        if ($test_image_url) {
            WP_CLI::debug('✓ Would use image: ' . $test_image_url);
            if ($this->is_valid_image_url($test_image_url)) {
                WP_CLI::debug('✓ Image URL is valid');
            } else {
                WP_CLI::debug('✗ Image URL is not valid (no image extension)');
            }
        } else {
            WP_CLI::debug('✗ No image found - would use fallback (ID: ' . $this->config['fallback_image_id'] . ')');
        }
        
        if ($this->is_dry_run) {
            return rand(10000, 99999);
        }
        
        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id) || !$post_id) {
            return false;
        }
        
        // Set categories after post creation
        if (!empty($category_ids)) {
            wp_set_post_terms($post_id, $category_ids, 'category', false);
            WP_CLI::debug('✓ Set category IDs: ' . implode(', ', $category_ids));
        }
        
        // Set tags after post creation
        if (!empty($tag_ids)) {
            wp_set_post_terms($post_id, $tag_ids, 'post_tag', false);
            WP_CLI::debug('✓ Set tag IDs: ' . implode(', ', $tag_ids));
        }
        
        // Handle featured image - extracts from content only (not full page HTML)
        // Unless custom image_xpath is set, then searches full page
        $this->set_featured_image($post_id, $html_for_image, $full_html);
        
        // Store metadata
        $norm = $this->normalize_url($data['source_url']);
        update_post_meta($post_id, 'source_url', $norm);
        update_post_meta($post_id, 'imported_from', 'scraper');
        update_post_meta($post_id, 'import_date', current_time('mysql'));
        if (!empty($data['timezone'])) {
            update_post_meta($post_id, 'source_timezone', $data['timezone']);
        }
        
        return $post_id;
    }

    /**
     * Set featured image for post
     */
    private function set_featured_image($post_id, $content, $full_html = null) {
        $image_url = $this->extract_first_image_url($content, $full_html);
        
        if (empty($image_url)) {
            WP_CLI::debug('No image found in content');
            $this->use_fallback_image($post_id);
            return;
        }
        
        if (!$this->is_valid_image_url($image_url)) {
            WP_CLI::debug('Image URL invalid or not an image file: ' . $image_url);
            $this->use_fallback_image($post_id);
            return;
        }
        
        WP_CLI::debug('Found image URL: ' . $image_url);
        
        // Priority 1: Try to import image to media library (proper WordPress way)
        WP_CLI::debug('Attempting to import image to media library...');
        $attachment_id = $this->import_image_to_media_library($image_url, $post_id);
        
        if ($attachment_id) {
            set_post_thumbnail($post_id, $attachment_id);
            WP_CLI::debug('✓ SUCCESS: Featured image imported to media library (ID: ' . $attachment_id . ')');
            return;
        }
        
        WP_CLI::debug('Media library import failed');
        
        // Priority 2: Try to use FIFU plugin for external URL
        if (function_exists('fifu_dev_set_image')) {
            WP_CLI::debug('Attempting to set image via FIFU plugin...');
            fifu_dev_set_image($post_id, $image_url);
            WP_CLI::debug('✓ SUCCESS: Featured image set via FIFU (external URL): ' . $image_url);
            return;
        }
        
        WP_CLI::debug('FIFU plugin not available');
        
        // Priority 3: Use fallback image if configured
        $this->use_fallback_image($post_id);
    }
    
    /**
     * Set fallback featured image
     */
    private function use_fallback_image($post_id) {
        if ($this->config['fallback_image_id'] > 0) {
            set_post_thumbnail($post_id, $this->config['fallback_image_id']);
            update_post_meta($post_id, 'used_fallback_featured_image', 'true');
            WP_CLI::debug('✓ Using fallback image (ID: ' . $this->config['fallback_image_id'] . ')');
        } else {
            WP_CLI::debug('No fallback image configured - post will have no featured image');
        }
    }
    
    /**
     * Import image from URL to WordPress media library
     */
    private function import_image_to_media_library($image_url, $post_id = 0) {
        // Validate URL
        if (empty($image_url) || !filter_var($image_url, FILTER_VALIDATE_URL)) {
            WP_CLI::debug('Invalid image URL: ' . $image_url);
            return false;
        }
        
        // Check if image was already imported
        $existing_attachment = $this->get_attachment_by_url($image_url);
        if ($existing_attachment) {
            WP_CLI::debug('Image already in media library (ID: ' . $existing_attachment . ')');
            return $existing_attachment;
        }
        
        // Download image
        $tmp_file = download_url($image_url);
        if (is_wp_error($tmp_file)) {
            WP_CLI::debug('Failed to download image: ' . $tmp_file->get_error_message());
            return false;
        }
        
        // Get file info
        $file_array = [
            'name' => basename(parse_url($image_url, PHP_URL_PATH)),
            'tmp_name' => $tmp_file
        ];
        
        // If no file extension, try to get from content type
        if (!pathinfo($file_array['name'], PATHINFO_EXTENSION)) {
            $response = wp_remote_head($image_url);
            if (!is_wp_error($response)) {
                $content_type = wp_remote_retrieve_header($response, 'content-type');
                $ext = $this->get_extension_from_mime($content_type);
                if ($ext) {
                    $file_array['name'] .= '.' . $ext;
                }
            }
        }
        
        // Import to media library
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        $attachment_id = media_handle_sideload($file_array, $post_id);
        
        // Clean up temp file
        if (file_exists($tmp_file)) {
            @unlink($tmp_file);
        }
        
        if (is_wp_error($attachment_id)) {
            WP_CLI::debug('Failed to import to media library: ' . $attachment_id->get_error_message());
            return false;
        }
        
        // Store source URL as meta for future reference
        update_post_meta($attachment_id, 'source_image_url', $image_url);
        
        return $attachment_id;
    }
    
    /**
     * Check if image URL already exists in media library
     */
    private function get_attachment_by_url($url) {
        global $wpdb;
        
        // Try to find by source URL meta
        $attachment_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = 'source_image_url' 
                AND meta_value = %s 
                LIMIT 1",
                $url
            )
        );
        
        if ($attachment_id) {
            return (int)$attachment_id;
        }
        
        // Try to find by filename
        $filename = basename(parse_url($url, PHP_URL_PATH));
        if ($filename) {
            $attachment_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} 
                    WHERE post_type = 'attachment' 
                    AND guid LIKE %s 
                    LIMIT 1",
                    '%' . $wpdb->esc_like($filename)
                )
            );
            
            if ($attachment_id) {
                return (int)$attachment_id;
            }
        }
        
        return false;
    }
    
    /**
     * Get file extension from MIME type
     */
    private function get_extension_from_mime($mime_type) {
        $mime_map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/svg+xml' => 'svg'
        ];
        
        return isset($mime_map[$mime_type]) ? $mime_map[$mime_type] : false;
    }

    /**
     * Extract first image URL from content HTML
     */
    private function extract_first_image_url($content_html, $full_html = null) {
        if (empty($content_html)) {
            WP_CLI::debug('extract_first_image_url: Content is empty');
            return false;
        }
        
        WP_CLI::debug('extract_first_image_url: Searching for images in ' . strlen($content_html) . ' bytes of HTML');
        
        // If custom image XPath is provided, search in FULL page HTML (not just content)
        // This allows absolute XPath selectors to work
        $search_html = $content_html;
        if (!empty($this->config['image_xpath']) && !empty($full_html)) {
            WP_CLI::debug('Custom image XPath provided - searching full page HTML instead of content only');
            $search_html = $full_html;
        }
        
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $search_html);
        $xpath = new DOMXPath($dom);
        
        $img_node = null;
        
        // If custom image XPath is provided, use it FIRST
        if (!empty($this->config['image_xpath'])) {
            WP_CLI::debug('Trying custom image XPath: ' . $this->config['image_xpath']);
            $img_result = @$xpath->query($this->config['image_xpath']);
            $img_node = $img_result ? $img_result->item(0) : null;

            if ($img_node) {
                WP_CLI::debug('✓ Found image using custom XPath');
            } else {
                if ($img_result === false) {
                    WP_CLI::debug('✗ Invalid image XPath expression');
                } else {
                    WP_CLI::debug('Custom image XPath returned no results');
                }
                WP_CLI::debug('Trying default //img in content');
                // Fall back to searching in content only
                $dom = new DOMDocument();
                @$dom->loadHTML('<?xml encoding="UTF-8">' . $content_html);
                $xpath = new DOMXPath($dom);
            }
        }
        
        // Fallback: find first img tag in the content
        if (!$img_node) {
            $all_images = $xpath->query('//img');
            WP_CLI::debug('Found ' . $all_images->length . ' total <img> tags in content');
            
            if ($all_images->length > 0) {
                $img_node = $all_images->item(0);
                WP_CLI::debug('Using first <img> tag found');
            }
        }
        
        if ($img_node) {
            // Determine the right attribute based on element type
            // <img> -> src, <a> -> href, anything else -> try src then href
            $tag = strtolower($img_node->nodeName);
            if ($tag === 'a') {
                $src = $img_node->getAttribute('href');
                WP_CLI::debug('Image from <a> href: ' . ($src ?: '(empty)'));
            } elseif ($tag === 'img') {
                $src = $img_node->getAttribute('src');
                WP_CLI::debug('Image from <img> src: ' . ($src ?: '(empty)'));
            } else {
                $src = $img_node->getAttribute('src') ?: $img_node->getAttribute('href');
                WP_CLI::debug('Image from <' . $tag . '> src/href: ' . ($src ?: '(empty)'));
            }

            // Strip query parameters that aren't part of the image path (e.g. tracking params)
            // but keep ?download=1 style params that some CDNs need

            if (!empty($src)) {
                // Make URL absolute if relative
                if (strpos($src, 'http') !== 0) {
                    // Extract base URL (scheme + host only) from source_url
                    $parsed = parse_url($this->config['source_url']);
                    $base_url = $parsed['scheme'] . '://' . $parsed['host'];
                    
                    if ($src[0] === '/') {
                        $src = $base_url . $src;
                    } else {
                        $src = $base_url . '/' . $src;
                    }
                    WP_CLI::debug('Converted relative URL to absolute: ' . $src);
                }
                return $src;
            } else {
                WP_CLI::debug('Image node found but src attribute is empty');
            }
        } else {
            WP_CLI::debug('No <img> tags found in content');
        }
        
        return false;
    }

    /**
     * Validate if URL has a valid image file extension
     */
    private function is_valid_image_url($url) {
        if (empty($url)) {
            return false;
        }
        
        $parsed_url = parse_url($url);
        if (!$parsed_url || !isset($parsed_url['path'])) {
            return false;
        }
        
        $path = $parsed_url['path'];
        $valid_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        
        return in_array($extension, $valid_extensions);
    }

    /**
     * Date parsing and normalization helpers
     */
    private function normalize_date_text($s) {
        $s = preg_replace('/[\x{00A0}\x{2022}\x{2027}\x{2219}]/u', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    private function parse_date($date_string) {
        $raw = $this->normalize_date_text($date_string);
        
        // Try common date format with optional time and timezone
        $re = '/\b([A-Za-z]{3,9})\s+(\d{1,2}),\s*(\d{4})(?:\D+(\d{1,2}:\d{2})(?:\s*([ap]m))?)?(?:\s*([A-Za-z]{2,4}))?/i';
        if (preg_match($re, $raw, $m)) {
            $month = $m[1];
            $day   = $m[2];
            $year  = $m[3];
            $time  = isset($m[4]) && $m[4] ? $m[4] : '00:00';
            $ampm  = isset($m[5]) && $m[5] ? $m[5] : '';
            
            $norm = sprintf('%s %d, %d %s%s', $month, (int)$day, (int)$year, $time, $ampm ? ' '.$ampm : '');
            
            $site_tz = wp_timezone_string() ?: 'UTC';
            
            $dt = DateTime::createFromFormat('M j, Y g:i a', $norm, new DateTimeZone($site_tz));
            if (!$dt) {
                $dt = DateTime::createFromFormat('F j, Y g:i a', $norm, new DateTimeZone($site_tz));
            }
            if (!$dt) {
                $dt = DateTime::createFromFormat('M j, Y H:i', $norm, new DateTimeZone($site_tz));
                if (!$dt) $dt = DateTime::createFromFormat('F j, Y H:i', $norm, new DateTimeZone($site_tz));
            }
            if ($dt) {
                return $dt->format('Y-m-d H:i:s');
            }
        }
        
        // Fallback: try strtotime
        try {
            $dt = new DateTime($raw, new DateTimeZone('UTC'));
            $site_tz = wp_timezone_string() ?: 'UTC';
            $dt->setTimezone(new DateTimeZone($site_tz));
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {}

        $ts = strtotime($raw);
        if ($ts) {
            return date('Y-m-d H:i:s', $ts);
        }
        
        return current_time('mysql');
    }

    /**
     * Extract date from JSON-LD structured data (script type="application/ld+json")
     * Many sites render dates via JavaScript but include them in JSON-LD for SEO
     * Converts to America/New_York (Eastern) to match BusinessWire's displayed times
     * Returns array with 'date' and 'timezone' keys, or false
     */
    private function extract_date_from_json_ld($xpath) {
        $scripts = $xpath->query('//script[@type="application/ld+json"]');

        foreach ($scripts as $script) {
            $json = @json_decode($script->textContent, true);
            if (!$json) continue;

            $iso_date = $json['datePublished'] ?? $json['dateCreated'] ?? null;
            if ($iso_date) {
                try {
                    $dt = new DateTime($iso_date);
                    $dt->setTimezone(new DateTimeZone('America/New_York'));
                    // Get short timezone abbreviation: EST/EDT -> ET
                    $tz_abbr = $dt->format('T'); // e.g. EST, EDT
                    $short_tz = preg_replace('/^([A-Z])[SD]([A-Z])$/', '$1$2', $tz_abbr);
                    return [
                        'date' => $dt->format('Y-m-d H:i:s'),
                        'timezone' => $short_tz,
                    ];
                } catch (Exception $e) {
                    WP_CLI::debug('Failed to parse JSON-LD date "' . $iso_date . '": ' . $e->getMessage());
                }
            }
        }

        return false;
    }

    /**
     * Database and taxonomy helpers
     */
    private function normalize_url($url) {
        $url = trim($url);
        $url = strtolower($url);
        $url = rtrim($url, '/');
        return $url;
    }

    private function post_exists_by_source_url($url) {
        global $wpdb;
        
        $norm = $this->normalize_url($url);
        
        $post_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT p.ID 
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                WHERE p.post_type = %s
                AND pm.meta_key = 'source_url'
                AND pm.meta_value = %s
                LIMIT 1",
                $this->config['post_type'],
                $norm
            )
        );
        
        return $post_id ? (int)$post_id : 0;
    }

    private function get_or_create_category($category_name) {
        $category = get_term_by('name', $category_name, 'category');
        
        if ($category) {
            return $category->term_id;
        }
        
        $result = wp_insert_term($category_name, 'category');
        
        if (is_wp_error($result)) {
            return 1; // Default category
        }
        
        return $result['term_id'];
    }

    private function get_or_create_tag($tag_name) {
        $tag = get_term_by('name', $tag_name, 'post_tag');
        
        if ($tag) {
            return $tag->term_id;
        }
        
        $result = wp_insert_term($tag_name, 'post_tag');
        
        if (is_wp_error($result)) {
            return false;
        }
        
        return $result['term_id'];
    }

    private function fetch_url($url) {
        $args = [            
            'timeout'     => 30,
            'redirection' => 5,
            'httpversion' => '1.1',
            'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'sslverify'   => true,
        ];
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            error_log('fetch_url WP_Error: ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        error_log('fetch_url HTTP ' . $code . ' for: ' . substr($url, 0, 100));
        
        if ($code !== 200) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        error_log('fetch_url body length: ' . strlen($body));
        
        return $body;
    }

    private function display_results() {
        WP_CLI::line('');
        WP_CLI::line(WP_CLI::colorize('%G=== Import Complete ===%n'));
        WP_CLI::line('');
        WP_CLI::line('Results:');
        WP_CLI::line('  Total Processed: ' . $this->stats['processed']);
        WP_CLI::line(WP_CLI::colorize('  %G✓ Created:%n ' . $this->stats['created']));
        WP_CLI::line(WP_CLI::colorize('  %Y⊘ Skipped:%n ' . $this->stats['skipped']));
        WP_CLI::line(WP_CLI::colorize('  %R✗ Errors:%n ' . $this->stats['errors']));
        WP_CLI::line('');
        
        if ($this->is_dry_run) {
            WP_CLI::line(WP_CLI::colorize('%YThis was a dry run. Add --live flag to actually create posts.%n'));
            WP_CLI::line('');
        }
    }

    /**
     * Get scraper statistics
     * Used by AJAX handlers to retrieve results
     */
    public function get_stats() {
        return $this->stats;
    }
}

// Register the command (only under real WP-CLI, not the cron shim)
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('scraper', 'Generic_Scraper_Command');
}
