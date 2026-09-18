=== EditTrace ===
Contributors: edittrace
Tags: inspector, elementor, acf, gutenberg, editing
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Click anything on your WordPress site. See exactly where to edit it.

== Description ==

EditTrace removes the hunting. Activate the inspector from the toolbar on any frontend page, click the heading, button, image or phone number you want to change, and EditTrace tells you where it comes from and gives you an Edit button.

It understands:

* **Gutenberg** – pages, posts and custom post types; nested blocks; block templates, template parts, synced patterns and navigation menus.
* **Elementor** – pages, containers, sections, columns, widgets and nested widgets; saved templates; Theme Builder headers, footers and other global templates.
* **Advanced Custom Fields** – fields on posts, pages and custom post types; Options Page fields; text, textarea, WYSIWYG, URL, link, image, number, select and more; repeater and flexible content sub fields (best effort).
* **WordPress core** – Media Library attachments, classic menus, site settings, and a targeted fallback search of posts, custom fields and options.

Every answer carries a confidence (Exact, High, Possible, Unknown). Global content (options, template parts, theme builder templates, synced patterns, menus) is clearly flagged because editing it affects more than one page.

EditTrace is only available to logged-in users with an allowed role (administrators by default), and it stays off for each user until they switch it on from the toolbar. Logged-out visitors receive nothing – no scripts, no markup, no data.

== Installation ==

1. Upload the plugin ZIP via Plugins → Add New → Upload Plugin, or unzip it into `wp-content/plugins/`.
2. Activate EditTrace.
3. Visit your site's frontend while logged in and click **EditTrace** in the toolbar to switch it on for your account; click it again to inspect. Use the toolbar submenu to turn it off.

== Frequently Asked Questions ==

= Does it slow down my site? =

No. Nothing runs for visitors. For allowed users a lightweight trace of the page render is stored server-side for 20 minutes; database searches only happen when you click something EditTrace could not trace, and they are strictly bounded.

= Why does a result say "Possible" instead of "Exact"? =

EditTrace only says Exact when it can prove the element came from that source. When two fields hold the same value, or when only a text search matched, it says so.

= Does it need Elementor Pro or ACF PRO? =

No. EditTrace works with the free versions and uses public APIs. Pro-only features (Theme Builder locations, options page links, repeaters) are detected when present.

== Changelog ==

= 1.0.0 =
* Initial release: Gutenberg, Elementor, ACF, media, navigation and fallback search providers; inspector UI; REST API; settings page.
