# Pixel Solution — WordPress Plugin

WordPress plugin that sends events to Meta via browser Pixel and server-side Conversions API (CAPI).

## Features

- **PageView** — fired on every page load (browser + CAPI)
- **ViewContent** — fired on single posts/pages (browser + CAPI)
- **Lead** — fired on Contact Form 7 submission with automatic deduplication via shared `event_id`
- Event deduplication between browser Pixel and CAPI to avoid double-counting
- Works correctly on LiteSpeed/full-page cached sites (Pixel + CAPI call moved to JS/AJAX)

## Requirements

- WordPress 5.8+
- PHP 7.4+
- Contact Form 7 (for Lead events)
- Meta Pixel ID
- Meta Conversions API access token

## Installation

1. Upload the `pixel-solution` folder to `/wp-content/plugins/`
2. Activate the plugin in **Plugins → Installed Plugins**
3. Go to **Settings → PixelSolution** and enter:
   - Pixel ID
   - CAPI Access Token
   - (optional) Test Event Code for Meta Events Manager testing

## Project Structure

```
pixel-solution/
├── pixel-solution.php       # Plugin entry point, hooks registration
├── admin/
│   └── settings-page.php    # Admin settings UI
└── includes/
    ├── class-pixel.php      # Browser Pixel JS injection
    ├── class-capi.php       # Server-side CAPI calls (wp_remote_post)
    └── class-events.php     # Event logic: PageView, ViewContent, Lead
```

## Author

Tomasz Kalinowski — [mindcloudsiedlce.pl](https://mindcloudsiedlce.pl)
