=== DataDeck – Admin Columns, Inline & Bulk Editor ===
Contributors: duhanguler
Tags: admin columns, bulk edit, inline edit, spreadsheet, custom fields
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

High-performance admin columns, zero-shift inline spreadsheet editor, safe AST bulk formulas, and one-click rollback for WordPress & WooCommerce.

== Description ==

**100% Free & Open Source. No Pro Version. No Paywalls. No Dashboard Ads.**

DataDeck turns slow, multi-click WordPress admin lists into a high-precision, virtualized spreadsheet workspace aligned with WordPress Core's modern `--wpds-*` design system—without locking ACF, WooCommerce, inline editing, bulk formulas, or rollback behind annual subscriptions.

### What Is Unlocked for Free

* **Universal Entity Engine:** Manage **Posts, Pages, Custom Post Types (CPTs), Media Library, Comments & Reviews, Users, WooCommerce Products, Inline Variations, and HPOS Orders** from a single workspace—or switch cleanly from any native list screen via the `[ Classic List | DataDeck Grid ]` header toggle.
* **Zero-Shift Inline Spreadsheet Editing:** Click or press `Enter` to edit cells with **0px layout shift**. Navigate with `Tab`, `Shift+Tab`, `Enter`, and Arrow keys. Drag the blue corner **Fill Handle** down to auto-increment SKUs (`SKU-001` → `SKU-002`) or numeric series, reorder columns via drag-and-drop, right-click any cell to **Filter by this value (=)**, and view real-time **SUM / AVG / MIN / MAX / COUNT** column summaries.
* **Smart CSV / JSON Import + Dry-Run Diff + Rollback:** Upload or paste supplier CSV/JSON sheets, match by **ID, SKU, Slug, or Email**, preview every `old -> new` diff before writing, apply in safe chunks, and undo the entire import in one click.
* **Computed AST Formula Columns:** Built-in *Discount %* and *Inventory Value* virtual columns plus custom user-defined formula columns calculated live without cluttering your database.
* **Optimistic Concurrency Guard & Cell-Level Rollback:** Detects concurrent edits from other admins before overwriting, and lets you inspect and restore individual cell history entries directly from the Inspector drawer.
* **Excel & Google Sheets Clipboard Power:** Select vertical cell ranges (`Shift+Click` or `Shift+Arrows`), paste multi-cell matrices directly from Excel or Google Sheets (`Ctrl/Cmd+V`), copy ranges (`Ctrl/Cmd+C`), and Fill Down (`Cmd/Ctrl+D`).
* **ACF, Secure Custom Fields (SCF), Meta Box & Native Meta:** Automatically discovers field schemas and lets you sort, filter, inline-edit, and bulk-edit custom fields for free.
* **WooCommerce Catalog & HPOS Power:** Edit SKUs, Regular/Sale Prices, Stock Qty, Stock Status, Categories, and **expand Variable Product variations inline** without switching pages. Supports HPOS Orders natively.
* **SEO Bulk Editing (Yoast SEO, Rank Math, SEOPress & AIOSEO):** Edit SEO Titles, Meta Descriptions, Focus Keywords, Canonical URLs, and `noindex` toggles with live character count meters (`60/60`, `160/160`).
* **Media Library Alt-Text Hygiene:** Audit and bulk-edit image `alt` text, captions, titles, dimensions, and file sizes in seconds.
* **Safe AST Formula Bulk Engine (Zero `eval()`):** Run arithmetic, conditional, and string formulas across thousands of filtered records with a **Dry-Run Before/After Diff Preview** before saving:
  * `if(regular_price > 0, round(regular_price * 0.85, 0) - 0.01, '')`
  * `if(stock_quantity == 0, 'outofstock', 'instock')`
  * Dynamic templates: `{title} - {sku} | {site_name}`
* **One-Click Batch Rollback & Audit History:** Every inline commit and bulk operation records compact delta snapshots so accidental edits can be reverted in one click.
* **Scheduled & Auto-Reverting Bulk Campaigns:** Schedule flash-sale price adjustments or status changes with an optional automatic revert timestamp powered by Action Scheduler / WP-Cron.
* **Built-In Audit Lenses:** Zero-configuration tabs for *Pricing & SKU Audit*, *Inventory Risk*, *Missing Images*, *SEO Audit*, *Content Hygiene*, and *Missing Alt Text*.
* **Developer & Agency Workflow:** Save role-scoped column & filter views, automatically mirror them to **`datadeck-json/`** inside your active theme for Git deployments, automate via **WP-CLI**, and connect safely through the **WordPress 6.9+ Abilities API**.

### Engineered for Extreme Performance (100k+ Catalog Scale)

* **Zero Frontend Footprint:** `0 bytes` of CSS, `0 bytes` of JS, and `0` database queries on public site visits.
* **Deterministic 6–8 SQL Queries per Page:** Uses a two-step lean ID planner, automatic **`wc_product_meta_lookup`** SQL routing, and a **Selective Meta Hydrator** that fetches only visible column meta keys—skipping multi-megabyte page builder blobs like `_elementor_data`.
* **60 FPS Virtualized Grid & ~25 KB Gzipped Initial Bundle:** Heavy modals and drawers are lazily loaded on demand; row virtualization renders smoothly even at 500 rows per page.
* **O(1) Memory Streaming CSV/JSON Export:** Keyset-paginated streaming exports 50,000+ rows with constant memory usage and CSV formula-injection neutralization.

== Installation ==

1. Upload the `datadeck` directory to `/wp-content/plugins/`, or install directly through the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Click **DataDeck** in the WordPress admin sidebar, or click **DataDeck Grid** at the top of your Posts, Pages, Products, Orders, Media, or Users screen.

== Frequently Asked Questions ==

= Is there a Pro version or paywall? =
No. Every feature—including ACF/SCF, WooCommerce Products/Variations/HPOS Orders, Yoast/Rank Math SEO, AST Bulk Formulas, Scheduled Auto-Revert Campaigns, Local JSON Sync, and One-Click Rollback—is 100% free and GPLv2+ open source.

= Does DataDeck slow down my public website? =
Not at all. DataDeck performs an early context exit on non-admin, non-REST, non-CLI, non-Cron requests—adding literally 0 bytes of assets and 0 database queries to your public pages.

= Is bulk editing safe if I make a mistake? =
Yes. Every bulk rule offers a **Dry-Run Diff Preview** before executing, and every committed inline or bulk batch records exact `old -> new` delta snapshots in a lightweight history table with **One-Click Rollback**.

= How does local JSON sync (`datadeck-json/`) work? =
Create a writable `datadeck-json/` folder inside your active theme directory. Whenever you save a View in DataDeck, a formatted `view-{slug}.json` file is written automatically so you can commit and sync column presets across environments via Git.

= Does DataDeck support WP-CLI? =
Yes! Run `wp datadeck status`, `wp datadeck benchmark product`, `wp datadeck bulk ... --dry-run`, `wp datadeck rollback <uuid>`, or `wp datadeck sync-json`.

== Screenshots ==

1. High-precision Products spreadsheet grid with inline price editing, pinned Title column, variation expansion, and SQL performance telemetry.
2. Safe AST Formula Bulk Engine with live syntax validation and Dry-Run Before/After diff preview.
3. Built-in Posts SEO Audit Lens with live 60/160 character count meters and red over-limit warnings.
4. Change History & One-Click Rollback drawer showing field-level snapshots and scheduled campaigns.
5. Media Library Missing Alt Text Audit Lens for rapid accessibility updates.

== Changelog ==

= 1.1.0 =
* Added Smart CSV / JSON Import Wizard with auto-mapping, ID/SKU/Slug/Email matching, Dry-Run Diff preview, chunked application, One-Click Rollback, and `wp datadeck import` CLI command.
* Added Virtual Computed AST Formula Columns (built-in WooCommerce Discount % & Inventory Value + custom user-defined formulas).
* Added Excel-style Drag-to-Fill Handle with series inference, Right-Click Context Menu (`Filter by value`, `Exclude value`, `Cell audit history`), Sticky Aggregation Footer Bar (`SUM/AVG/MIN/MAX/COUNT`), and Drag-and-Drop Column Header Reordering.
* Added Optimistic Concurrency Guard (`_modifiedAt` conflict detection) and Cell-Level Audit History with Single-Cell Rollback.
* Added WordPress Comments & WooCommerce Reviews entity adapter with inline moderation lenses, plus SEOPress and AIOSEO auto-detection.

= 1.0.0 =
* Initial public release.
* Universal Entity Engine: Posts, Pages, CPTs, Media Library, Users, WooCommerce Products/Variations, and HPOS Orders.
* Zero-paywall field providers for Core, Native Meta, ACF/SCF, Meta Box, WooCommerce, Yoast SEO, and Rank Math.
* Zero-shift inline cell editing, multi-cell range selection, Excel/Google Sheets paste, Fill Down (`Cmd/Ctrl+D`), and Command Palette (`Cmd/Ctrl+K`).
* Safe recursive-descent AST formula engine, adaptive chunked bulk processor, scheduled auto-reverting campaigns, and One-Click Rollback history.
* SmartQueryPlanner (`wc_product_meta_lookup`), SelectiveHydrator, O(1) memory Keyset Streaming CSV/JSON exporter, `datadeck-json/` Git sync, WP-CLI commands, and WordPress 6.9+ Abilities API integration.
