# Mega Agent Bridge

**One plugin. Any WordPress site. Full AI control.**

Part of the [MEGA](https://mega.management) ecosystem — the tool that lets anyone one-shot a WordPress site from a terminal using plain English and Claude.

---

## What It Does

Installs a private, authenticated REST API on any WordPress site so an AI agent (Claude) can:

- **Read** the actual rendered HTML of any page — bypassing host-level page cache
- **Write** Kadence theme mods correctly (as PHP arrays, never as JSON strings)
- **Update** post/page content directly
- **Flush** every major caching system in one call (Hostinger, SiteGround, WP Rocket, W3TC, WP Engine, Bluehost, and more)
- **Inspect** all Kadence settings, active plugins, and site info

No more "try it and refresh and tell me if it worked." The agent checks its own work.

---

## Installation

1. Download or clone this repo
2. Upload `mega-agent-bridge.php` to `/wp-content/plugins/mega-agent-bridge/`
3. Activate in **Plugins → Installed Plugins**
4. Get your API key — visit: `https://yoursite.com/wp-json/mega-bridge/v1/key` (while logged in as admin)
5. Store the key securely

Or via WP-CLI:
```bash
wp plugin install https://github.com/mega-management/mega-agent-bridge/archive/main.zip --activate
wp eval "echo get_option('mega_bridge_api_key');"
```

---

## API Reference

All requests require the header:
```
X-Mega-Bridge-Key: your-api-key
```

Or pass `?_key=your-api-key` as a query param.

### Status
```
GET /wp-json/mega-bridge/v1/status
```
Returns version, site URL, theme, WP + PHP versions.

### Render Page (cache-bypassed)
```
GET /wp-json/mega-bridge/v1/render?path=/
```
Returns the live HTML of any page, fetched server-side with cache-bypass headers. Includes extracted body classes and header classes for quick verification.

### Get All Theme Mods
```
GET /wp-json/mega-bridge/v1/theme-mods
```

### Get Single Theme Mod
```
GET /wp-json/mega-bridge/v1/theme-mods/header_main_layout
```

### Set Theme Mod (stored as PHP array — Kadence-safe)
```
POST /wp-json/mega-bridge/v1/theme-mods/header_main_layout
Content-Type: application/json

{
  "value": {
    "layout": "left-logo",
    "itemsLayout": "left-logo",
    "desktop": "contained",
    "tablet": "",
    "mobile": ""
  }
}
```
Returns `{ "verified": true }` confirming the value was stored and reads back correctly.

### Get Kadence Custom CSS
```
GET /wp-json/mega-bridge/v1/kadence/css
```

### Set Kadence Custom CSS
```
POST /wp-json/mega-bridge/v1/kadence/css
Content-Type: application/json

{ "css": "/* your styles */" }
```

### Get All Kadence Settings (full dump)
```
GET /wp-json/mega-bridge/v1/kadence/settings
```
Returns theme mods, kadence_theme_settings, header layout, pro header builder, and custom CSS in one call.

### Bulk Update Kadence Settings
```
POST /wp-json/mega-bridge/v1/kadence/settings
Content-Type: application/json

{
  "theme_mods": {
    "header_main_layout": { "desktop": "contained", "layout": "left-logo", "itemsLayout": "left-logo", "tablet": "", "mobile": "" }
  }
}
```

### Find Post by Path or Slug
```
GET /wp-json/mega-bridge/v1/posts/find?path=/
GET /wp-json/mega-bridge/v1/posts/find?slug=home
```

### Get Post Content
```
GET /wp-json/mega-bridge/v1/posts/5
```

### Update Post Content
```
POST /wp-json/mega-bridge/v1/posts/5
Content-Type: application/json

{ "content": "<!-- wp:paragraph -->..." }
```

### Flush All Caches
```
POST /wp-json/mega-bridge/v1/cache/flush
```
Hits: WP object cache, transients, LiteSpeed, WP Rocket, W3TC, WP Super Cache, SiteGround, WP Engine, Bluehost, Kadence CSS cache.

### Site Info
```
GET /wp-json/mega-bridge/v1/site-info
```
Returns theme, plugins list, front page ID, permalink structure.

---

## Security

- A unique 48-character secret key is auto-generated on activation
- All endpoints (except `/key`) require the key — no exceptions
- `/key` requires being logged in as admin
- Use HTTPS. Always.
- To rotate the key: `wp option delete mega_bridge_api_key` then deactivate/reactivate the plugin

---

## The MEGA Vision

WordPress overwhelms people. The setup, the settings, the plugins, the theme options — it's too much.

MEGA's mission is to change that. Install this plugin, open a terminal, talk to Claude in plain English, and walk away with a live store. No tutorials. No YouTube rabbit holes. No expensive agencies.

This plugin is the bridge between human intent and a finished WordPress site.

---

## License

GPL-2.0+ — free forever, for everyone.
