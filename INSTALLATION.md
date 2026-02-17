# Installation Guide - DGD Web Scraper

## Method 1: Regular Plugin (Recommended)

### Step 1: Upload Plugin

**Option A: Via WordPress Admin**
1. Go to **Plugins → Add New**
2. Click **Upload Plugin**
3. Choose the `dgd-web-scraper.zip` file
4. Click **Install Now**
5. Click **Activate Plugin**

**Option B: Via FTP/SSH**
1. Upload the `dgd-web-scraper` folder to `/wp-content/plugins/`
2. Go to **Plugins** in WordPress admin
3. Find **DGD Web Scraper**
4. Click **Activate**

### Step 2: Verify Installation

1. Go to **Tools** in WordPress admin
2. You should see **Scraper Settings** menu item
3. Click it to access the settings page

### Step 3: Verify WP-CLI (Optional)

If you want to use WP-CLI commands:

```bash
wp scraper --help
```

Should display available commands.

---

## Method 2: Must-Use Plugin

If you prefer mu-plugins (always active, can't be deactivated):

### Step 1: Create mu-plugins Directory

```bash
mkdir -p wp-content/mu-plugins
```

### Step 2: Copy Files

```bash
# Copy admin interface
cp dgd-web-scraper/includes/class-scraper-admin.php wp-content/mu-plugins/scraper-admin.php

# Copy WP-CLI command
cp dgd-web-scraper/includes/class-scraper-command.php wp-content/mu-plugins/wp-scraper-tool.php
```

### Step 3: Verify

1. Go to **Tools → Scraper Settings** (should appear automatically)
2. Run `wp scraper --help` to verify CLI

**Note:** mu-plugins don't show in the Plugins page - they're always active.

---

## Method 3: Single-File Installation (Legacy)

For backward compatibility with older installations:

### As MU-Plugin

```bash
# Create directory
mkdir -p wp-content/mu-plugins

# Copy files
cp wp-scraper-tool.php wp-content/mu-plugins/
cp scraper-admin.php wp-content/mu-plugins/
```

Both files will be loaded automatically.

---

## Uninstallation

### Regular Plugin

1. Go to **Plugins**
2. Deactivate **DGD Web Scraper**
3. Click **Delete**

**Note:** This will NOT delete your profiles. To remove profiles:

```bash
wp option delete scraper_profiles
```

### Must-Use Plugin

```bash
rm wp-content/mu-plugins/scraper-admin.php
rm wp-content/mu-plugins/wp-scraper-tool.php
```

---

## Troubleshooting Installation

### Plugin doesn't appear in menu

**Check:**
1. Is the plugin activated?
2. Do you have admin permissions?
3. Check error logs for PHP errors

**Fix:**
```bash
# Enable WordPress debug mode
# In wp-config.php:
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);

# Check the log
tail -f wp-content/debug.log
```

### WP-CLI commands not working

**Check:**
1. Is WP-CLI installed? `wp --version`
2. Are you in WordPress root directory?
3. Is the plugin/mu-plugin loaded?

**Fix:**
```bash
# Verify files exist
ls -la wp-content/mu-plugins/
# or
ls -la wp-content/plugins/dgd-web-scraper/

# Test basic WP-CLI
wp plugin list
```

### Admin page shows errors

**Common issues:**
- PHP version too old (need 7.4+)
- Missing PHP extensions (DOM, libxml)
- File permissions

**Fix:**
```bash
# Check PHP version
php -v

# Check required extensions
php -m | grep -i dom
php -m | grep -i libxml

# Fix permissions
chmod 644 wp-content/plugins/dgd-web-scraper/*.php
chmod 644 wp-content/plugins/dgd-web-scraper/includes/*.php
```

---

## Post-Installation Steps

### 1. Install WP-CLI (if needed)

```bash
# Download
curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar

# Make executable
chmod +x wp-cli.phar

# Move to PATH
sudo mv wp-cli.phar /usr/local/bin/wp

# Test
wp --version
```

### 2. Install FIFU Plugin (optional)

For external featured images:

```bash
wp plugin install featured-image-from-url --activate
```

Or via admin:
1. Go to **Plugins → Add New**
2. Search for "Featured Image from URL"
3. Install and activate

### 3. Create Your First Profile

1. Go to **Tools → Scraper Settings**
2. Click **New Profile**
3. Fill in required fields:
   - Profile Name
   - Source URL
   - Listing XPath
   - Title XPath
   - Content XPath
   - Date XPath
4. Click **Save Profile**

### 4. Test Your Profile

```bash
# Dry run (preview only)
wp scraper run --profile=your-profile

# Live run (creates posts)
wp scraper run --profile=your-profile --live
```

---

## File Structure

```
dgd-web-scraper/
├── dgd-web-scraper.php          # Main plugin file
├── README.md                         # Documentation
├── INSTALLATION.md                   # This file
├── includes/
│   ├── class-scraper-admin.php      # Admin interface
│   └── class-scraper-command.php    # WP-CLI command
└── docs/                             # Additional documentation
    ├── USER-DOCUMENTATION.html
    ├── PAGINATION-GUIDE.md
    ├── REMOVE-XPATHS-GUIDE.md
    └── FEATURED-IMAGE-GUIDE.md
```

---

## Migration from MU-Plugin

If you're currently using the mu-plugin version:

### Step 1: Export Profiles (backup)

```bash
wp option get scraper_profiles --format=json > profiles-backup.json
```

### Step 2: Remove MU-Plugin Files

```bash
rm wp-content/mu-plugins/scraper-admin.php
rm wp-content/mu-plugins/wp-scraper-tool.php
```

### Step 3: Install Regular Plugin

Follow **Method 1** above.

### Step 4: Verify Profiles Still Exist

```bash
wp scraper list-profiles
```

Should show your existing profiles (they're stored in the database, not in the files).

---

## Requirements Checklist

Before installing, verify:

- [ ] WordPress 5.0 or higher
- [ ] PHP 7.4 or higher
- [ ] PHP DOM extension enabled
- [ ] PHP libxml extension enabled
- [ ] cURL enabled OR allow_url_fopen = On
- [ ] write permissions on wp-content/plugins/ (for regular plugin)
- [ ] write permissions on wp-content/mu-plugins/ (for mu-plugin)

**Check requirements:**

```bash
# WordPress version
wp core version

# PHP version
php -v

# PHP extensions
php -m | grep -i dom
php -m | grep -i libxml
php -m | grep -i curl

# PHP settings
php -i | grep allow_url_fopen
```

---

## Getting Help

If you run into issues:

1. Check the error logs: `wp-content/debug.log`
2. Enable debug mode in `wp-config.php`
3. Try the debug command: `wp scraper run --profile=test --debug`
4. Review documentation in `/docs` folder

---

## Next Steps

After installation:

1. Read [USER-DOCUMENTATION.html](docs/USER-DOCUMENTATION.html)
2. Learn about [Pagination](docs/PAGINATION-GUIDE.md)
3. Configure [Remove Elements](docs/REMOVE-XPATHS-GUIDE.md)
4. Set up [Featured Images](docs/FEATURED-IMAGE-GUIDE.md)

Happy scraping! 🚀
