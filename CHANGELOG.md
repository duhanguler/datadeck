# Changelog

All notable changes to DataDeck are documented in this file.

## [1.1.0] - 2026-11-22

### Added
- **Smart CSV / JSON Import Wizard (`CsvImportEngine` + lazy `CsvImportModal`):** Automatic delimiter detection (comma, semicolon, tab), UTF-8 BOM stripping, automatic column header mapping, single-query batch record matching (`ID`, `SKU`, `Slug`, `Email`), Dry-Run Before/After diff preview, chunked application, full One-Click Rollback support, and `wp datadeck import` WP-CLI command.
- **Virtual Computed AST Formula Columns (`ComputedFieldProvider`):** Built-in WooCommerce `Discount %` and `Inventory Value` virtual columns plus custom user-defined AST formula columns that evaluate live without writing to the database and auto-hydrate hidden dependency columns.
- **Excel-Grade Grid Ergonomics:**
  - Drag-to-Fill Handle (`.rc-fill-handle`) with smart series inference (`SKU-001` → `SKU-002`, numeric arithmetic progressions, and repeat fill).
  - Right-Click Context Menu (`Filter by this value (=)`, `Exclude this value (!=)`, `Copy cell value`, `Apply to selected rows`, `View cell audit history`, `Duplicate row`, `Open in Classic Editor`).
  - Live Sticky Aggregation Footer Bar (`SUM`, `AVG`, `MIN`, `MAX`, `COUNT`) over selected ranges or active numeric columns with zero extra SQL queries.
  - Native Drag-and-Drop Column Header Reordering directly on the grid header.
- **Optimistic Concurrency Guard (`_modifiedAt`):** Detects concurrent edits made by other admins or background jobs before overwriting, presenting server vs. local diffs with **Reload Server Values** or **Force Overwrite** controls.
- **Cell-Level Audit History & Single-Cell Rollback:** New **Audit History** tab inside the Inspector drawer showing chronological field changes with 1-click single-cell restoration.
- **WordPress Comments & WooCommerce Reviews Entity:** Full inline moderation (`Approved`, `Pending`, `Spam`, `Trash`), product star ratings, built-in moderation Audit Lenses, and native `edit-comments.php` switcher pill.
- **Expanded SEO Auto-Detection:** Added **SEOPress** and **All in One SEO (AIOSEO)** alongside Yoast SEO and Rank Math.
- **Static Analysis & Frontend Test Gates:** Added PHPStan Level 6 (`0` errors) and Node frontend utility unit tests (`npm run test:js`) to GitHub Actions CI.

### Fixed
- Prevented direct price overwrites on WooCommerce Variable parent products with a clear inline error pointing users to expand variation rows.
- Unified native `<select>` appearance across Chromium/WordPress `forms.css` to eliminate double-chevron rendering in modals and filters.

## [1.0.0] - 2026-11-22

### Added
- Universal Entity Engine supporting Posts, Pages, Custom Post Types, Media Library, Users, WooCommerce Products (with inline expandable Variations), and HPOS Orders.
- Zero-paywall field providers: Core, Native Meta auto-discovery, ACF & Secure Custom Fields (SCF), Meta Box, WooCommerce CRUD, Yoast SEO, and Rank Math.
- Virtualized 60 FPS spreadsheet grid built on `--wpds-*` tokens with `0px` layout-shift inline cell editing, 3 density modes (`S / M / L`), pinned primary column, resizable columns, and `⌘K` Command Palette.
- Multi-cell range selection (`Shift+Click` / `Shift+Arrows`), Excel/Google Sheets TSV copy-paste (`Ctrl/Cmd+C`, `Ctrl/Cmd+V`), and Fill Down (`Cmd/Ctrl+D`).
- Safe recursive-descent AST formula engine (zero `eval()`), Dry-Run Before/After diff preview, adaptive chunked bulk execution, and Scheduled Auto-Reverting Campaigns.
- Delta-snapshot audit history (`{prefix}_datadeck_batches` & `{prefix}_datadeck_changes`) with One-Click Rollback.
- Built-in zero-setup Audit Lenses (Pricing & SKU Audit, Inventory Risk, Missing Images, SEO Audit, Content Hygiene, Missing Alt Text).
- `SmartQueryPlanner` (`wc_product_meta_lookup` routing), `SelectiveHydrator` (constant 6–8 SQL queries, skips heavy `_elementor_data` blobs), and O(1) memory `KeysetStreamExporter`.
- Role-scoped Saved Views with Git-syncable `datadeck-json/`, WP-CLI commands (`wp datadeck ...`), and WordPress 6.9+ Abilities API integration.
