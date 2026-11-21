# Changelog

All notable changes to DataDeck are documented in this file.

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
