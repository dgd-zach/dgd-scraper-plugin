<?php
/**
 * Debug Tool: View Saved Scraper Profiles
 * 
 * USAGE:
 * 1. Upload this file to your WordPress root directory
 * 2. Visit: https://your-site.com/debug-profiles.php
 * 3. Delete this file after debugging
 */

// Load WordPress
require_once('wp-load.php');

// Check if user is logged in and is admin
if (!is_user_logged_in() || !current_user_can('manage_options')) {
    die('Access denied. You must be logged in as an administrator.');
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Debug Scraper Profiles</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 20px; background: #f0f0f1; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #2563eb; border-bottom: 3px solid #2563eb; padding-bottom: 10px; }
        h2 { color: #1e40af; margin-top: 30px; }
        .profile-box { background: #f9fafb; border: 2px solid #e5e7eb; border-radius: 6px; padding: 20px; margin: 20px 0; }
        .profile-box.active { border-color: #2563eb; background: #eff6ff; }
        .profile-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 2px solid #ddd; padding-bottom: 10px; }
        .profile-name { font-size: 20px; font-weight: bold; color: #1f2937; }
        .profile-key { font-family: monospace; background: #fbbf24; padding: 4px 8px; border-radius: 4px; font-size: 14px; }
        .field-list { display: grid; grid-template-columns: 200px 1fr; gap: 10px; margin-top: 15px; }
        .field-label { font-weight: 600; color: #6b7280; }
        .field-value { font-family: monospace; background: white; padding: 8px; border-radius: 4px; border: 1px solid #d1d5db; word-break: break-all; }
        .field-value.empty { color: #9ca3af; font-style: italic; }
        .count-badge { background: #10b981; color: white; padding: 4px 12px; border-radius: 20px; font-size: 14px; font-weight: bold; }
        .warning { background: #fef3c7; border: 2px solid #f59e0b; padding: 15px; border-radius: 6px; margin: 20px 0; }
        .success { background: #d1fae5; border: 2px solid #10b981; padding: 15px; border-radius: 6px; margin: 20px 0; }
        .error { background: #fee2e2; border: 2px solid #ef4444; padding: 15px; border-radius: 6px; margin: 20px 0; }
        pre { background: #1f2937; color: #f3f4f6; padding: 15px; border-radius: 6px; overflow-x: auto; }
        .btn { display: inline-block; padding: 10px 20px; background: #2563eb; color: white; text-decoration: none; border-radius: 6px; margin: 10px 10px 10px 0; }
        .btn:hover { background: #1e40af; }
        .btn-danger { background: #ef4444; }
        .btn-danger:hover { background: #dc2626; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Scraper Profiles Debug Tool</h1>
        
        <?php
        // Get profiles
        $profiles = get_option('scraper_profiles', []);
        $profile_count = is_array($profiles) ? count($profiles) : 0;
        
        echo '<p><strong>Database Option Name:</strong> <code>scraper_profiles</code></p>';
        echo '<p><strong>Total Profiles Found:</strong> <span class="count-badge">' . $profile_count . '</span></p>';
        
        if (!is_array($profiles)) {
            echo '<div class="error">';
            echo '<strong>⚠️ ERROR:</strong> Profiles data is corrupted (not an array)!<br>';
            echo '<strong>Data Type:</strong> ' . gettype($profiles) . '<br>';
            echo '<a href="?action=reset" class="btn btn-danger">Reset Profiles</a>';
            echo '</div>';
        } elseif ($profile_count === 0) {
            echo '<div class="warning">';
            echo '<strong>📭 No profiles found.</strong><br><br>';
            echo 'This is normal if you haven\'t created any profiles yet.<br>';
            echo 'Go to <a href="' . admin_url('tools.php?page=scraper-settings') . '">Scraper Settings</a> to create your first profile.';
            echo '</div>';
        } else {
            echo '<div class="success">';
            echo '<strong>✓ Found ' . $profile_count . ' profile(s) in database</strong>';
            echo '</div>';
            
            echo '<h2>📋 Profile Details</h2>';
            
            foreach ($profiles as $key => $profile) {
                $is_complete = !empty($profile['profile_name']) && !empty($profile['source_url']);
                
                echo '<div class="profile-box' . ($is_complete ? ' active' : '') . '">';
                echo '<div class="profile-header">';
                echo '<div>';
                echo '<div class="profile-name">' . htmlspecialchars($profile['profile_name'] ?? 'Unnamed') . '</div>';
                echo '<div style="margin-top: 5px;">Key: <span class="profile-key">' . htmlspecialchars($key) . '</span></div>';
                echo '</div>';
                
                if ($is_complete) {
                    echo '<div style="color: #10b981; font-weight: bold;">✓ Complete</div>';
                } else {
                    echo '<div style="color: #f59e0b; font-weight: bold;">⚠ Incomplete</div>';
                }
                echo '</div>';
                
                echo '<div class="field-list">';
                
                $fields = [
                    'Profile Name' => 'profile_name',
                    'Source URL' => 'source_url',
                    'Post Type' => 'post_type',
                    'Listing XPath' => 'listing_xpath',
                    'Title XPath' => 'title_xpath',
                    'Content XPath' => 'content_xpath',
                    'Date XPath' => 'date_xpath',
                    'Excerpt XPath' => 'excerpt_xpath',
                    'Image XPath' => 'image_xpath',
                    'Categories' => 'categories',
                    'Tags' => 'tags',
                    'Post Status' => 'post_status',
                    'Start Page' => 'start_page',
                    'Max Pages' => 'max_pages',
                    'Posts Per Batch' => 'posts_per_batch',
                ];
                
                foreach ($fields as $label => $field) {
                    $value = $profile[$field] ?? '';
                    $is_empty = empty($value);
                    
                    echo '<div class="field-label">' . $label . ':</div>';
                    echo '<div class="field-value' . ($is_empty ? ' empty' : '') . '">';
                    echo $is_empty ? '(empty)' : htmlspecialchars($value);
                    echo '</div>';
                }
                
                echo '</div>';
                
                // Show command to use this profile
                echo '<div style="margin-top: 15px; padding: 10px; background: #1f2937; color: #f3f4f6; border-radius: 4px; font-family: monospace;">';
                echo 'wp scraper run --profile=' . htmlspecialchars($key) . ' --live';
                echo '</div>';
                
                echo '</div>';
            }
        }
        
        // Handle reset action
        if (isset($_GET['action']) && $_GET['action'] === 'reset') {
            update_option('scraper_profiles', []);
            echo '<div class="success"><strong>✓ Profiles have been reset.</strong> <a href="?">Refresh</a></div>';
        }
        ?>
        
        <h2>🔧 Raw Data (JSON)</h2>
        <pre><?php echo json_encode($profiles, JSON_PRETTY_PRINT); ?></pre>
        
        <h2>🛠️ Actions</h2>
        <a href="<?php echo admin_url('tools.php?page=scraper-settings'); ?>" class="btn">Go to Scraper Settings</a>
        <a href="?action=reset" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete ALL profiles? This cannot be undone!')">Reset All Profiles</a>
        
        <hr style="margin: 40px 0;">
        <p style="color: #6b7280; font-size: 14px;">
            <strong>Important:</strong> Delete this file (debug-profiles.php) after debugging for security.
        </p>
    </div>
</body>
</html>
