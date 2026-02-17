# DGD Web Scraper - WordPress Plugin

Import content from any website into WordPress using XPath selectors.

## Features

- ✅ Multiple scraper profiles
- ✅ XPath-based content extraction
- ✅ Path-based and query parameter pagination
- ✅ Remove unwanted elements (ads, scripts, etc.)
- ✅ Featured image handling (Media Library + FIFU)
- ✅ Content-only image extraction
- ✅ Category and tag assignment
- ✅ Duplicate detection
- ✅ Batch processing
- ✅ WP-CLI support

## Installation

### As Regular Plugin

1. Download or clone this repository
2. Upload the `dgd-web-scraper` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to **Tools → Scraper Settings** to create your first profile

### As Must-Use Plugin (Alternative)

If you prefer mu-plugin installation:

1. Create `/wp-content/mu-plugins/` if it doesn't exist
2. Copy these files:
   - `includes/class-scraper-admin.php` → `mu-plugins/scraper-admin.php`
   - `includes/class-scraper-command.php` → `mu-plugins/wp-scraper-tool.php`

## Usage

### Via Admin Interface

1. Go to **Tools → Scraper Settings**
2. Click **New Profile**
3. Configure your XPath selectors and options
4. Save profile

### Via WP-CLI

```bash
# List profiles
wp scraper list-profiles

# Run scraper (dry run)
wp scraper run --profile=your-profile

# Run scraper (live - creates posts)
wp scraper run --profile=your-profile --live

# With custom settings
wp scraper run --profile=your-profile --max-pages=5 --live
```

## Configuration

### Profile Settings

- **Profile Name** - Unique identifier
- **Source URL** - Archive page to scrape
- **Post Type** - post, page, or custom post type
- **Listing XPath** - Find article links on archive
- **Title XPath** - Extract post title
- **Content XPath** - Extract post content
- **Date XPath** - Extract publication date
- **Excerpt XPath** - (Optional) Extract excerpt
- **Featured Image XPath** - (Optional) Target specific image
- **Remove Elements XPath** - (Optional) Remove unwanted content
- **Categories** - Comma-separated category names
- **Tags** - Comma-separated tag names
- **Pagination Format** - Query parameter or path-based
- **Post Status** - Publish or Draft
- **Start Page** - Which page to start from
- **Max Pages** - How many pages to scrape
- **Posts Per Batch** - Limit per run (0 = all)
- **Fallback Image ID** - Default featured image

## Pagination Formats

### Query Parameter
```
Format: ?page=2
Example: https://example.com/blog?page=2
Setting: Select "Query Parameter (?page=2)"
```

### Path-Based
```
Format: /page/2/
Example: https://example.com/blog/page/2/
Setting: Select "Path-Based (/blog/page/2/)"
```

### Custom
```
Format: Your own pattern
Examples: ?p=2, /{page}/, /posts/{page}/
Setting: Select "Custom Format..." and enter pattern
```

## Finding XPath Selectors

1. Open target website in Chrome/Firefox
2. Right-click element → Inspect
3. In DevTools, right-click HTML element
4. Select **Copy → Copy XPath**
5. Paste into profile field

**Test in browser console:**
```javascript
$x('//article//h1')  // Should return matching elements
```

## Removing Unwanted Elements

Add XPath selectors (one per line) to remove ads, scripts, social buttons, etc:

```
//div[@class="advertisement"]
//div[contains(@class, "social-share")]
//script
//iframe
//div[@id="comments"]
```

## Requirements

- WordPress 5.0+
- PHP 7.4+
- WP-CLI (for command-line usage)
- cURL or allow_url_fopen enabled

## Optional

- [Featured Image from URL (FIFU)](https://wordpress.org/plugins/featured-image-from-url/) - For external image URLs

## Documentation

See `/docs` folder for detailed guides:
- PAGINATION-GUIDE.md
- REMOVE-XPATHS-GUIDE.md
- FEATURED-IMAGE-GUIDE.md
- USER-DOCUMENTATION.html

## Troubleshooting

### No content found
- Test Listing XPath in browser console
- Check if page loads via JavaScript
- Verify source URL is accessible

### Images not importing
- Check if image URLs are valid
- Install FIFU plugin for external URLs
- Set a fallback image ID

### Pagination not working
- Verify pagination format setting
- Test page 2 URL manually in browser
- Use `--debug` flag to see generated URLs

## Support

For issues and questions:
1. Check documentation in `/docs`
2. Use debug mode: `wp scraper run --profile=test --debug`
3. Review error logs

## License

GPL v2 or later

## Changelog

### 2.0.0
- Multiple profile support
- Path-based pagination
- Remove elements feature
- Content-only image extraction
- Featured image improvements
- Admin interface enhancements

### 1.0.0
- Initial release
- Basic scraping functionality
- XPath-based extraction
- Duplicate detection
