<p align="center">
  <img src=".wordpress-org/banner-1544x500.svg" alt="DataDeck — Admin Columns, Inline & Bulk Editor for WordPress & WooCommerce" width="100%" />
</p>

<h1 align="center">DataDeck</h1>

<p align="center">
  <strong>High-performance admin columns, zero-shift inline spreadsheet editor, safe AST bulk formula engine, and one-click rollback for WordPress &amp; WooCommerce.</strong>
</p>

<p align="center">
  <a href="https://github.com/duhanguler/datadeck/actions/workflows/ci.yml"><img src="https://github.com/duhanguler/datadeck/actions/workflows/ci.yml/badge.svg" alt="CI" /></a>
  <a href="https://github.com/duhanguler/datadeck/actions/workflows/plugin-check.yml"><img src="https://github.com/duhanguler/datadeck/actions/workflows/plugin-check.yml/badge.svg" alt="WordPress Plugin Check" /></a>
  <a href="https://github.com/duhanguler/datadeck/releases/latest"><img src="https://img.shields.io/github/v/release/duhanguler/datadeck?style=flat&color=3858e9" alt="Latest Release" /></a>
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat" alt="PHP 8.1+" />
  <img src="https://img.shields.io/badge/PHPStan-Level%206-2563eb?style=flat" alt="PHPStan Level 6" />
  <img src="https://img.shields.io/badge/WordPress-6.6%20%E2%80%93%207.1-21759B?style=flat" alt="WordPress 6.6 - 7.1" />
  <img src="https://img.shields.io/badge/Initial%20Bundle-29.2%20KB%20gzip-1a7f37?style=flat" alt="29.2 KB gzip" />
  <img src="https://img.shields.io/badge/Frontend%20Footprint-0%20Bytes-1a7f37?style=flat" alt="0 Bytes Frontend" />
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-GPLv2%2B-18181b?style=flat" alt="GPLv2+ License" /></a>
</p>

<p align="center">
  <a href="https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/duhanguler/datadeck/main/.wordpress-org/blueprints/blueprint.json"><strong>Try Live in Browser (WordPress Playground)</strong></a>
  &nbsp;&middot;&nbsp;
  <a href="https://github.com/duhanguler/datadeck/releases/latest/download/datadeck.zip"><strong>Download datadeck.zip</strong></a>
  &nbsp;&middot;&nbsp;
  <a href="#why-datadeck-vs-paid-alternatives"><strong>Comparison</strong></a>
  &nbsp;&middot;&nbsp;
  <a href="#performance--architecture"><strong>Benchmarks</strong></a>
  &nbsp;&middot;&nbsp;
  <a href="#local-development-docker"><strong>Docker Setup</strong></a>
</p>

---

## Interface Preview

> **Design Philosophy:** Built natively on WordPress 7.0+ `--wpds-*` design tokens with `0px` layout-shift inline cells, `tabular-nums` alignment, pinned primary columns, and `⌘K` keyboard control. No decorative gradients, no fake KPI bloat, no dashboard nags, no upsell walls.

<p align="center">
  <img src=".wordpress-org/screenshot-1.png" alt="DataDeck WooCommerce Products Workspace with inline variation expansion and SQL performance telemetry" width="100%" />
</p>

<table>
  <tr>
    <td width="50%">
      <img src=".wordpress-org/screenshot-2.png" alt="Safe AST Bulk Formula Engine with Dry-Run Before/After diff preview" />
      <p align="center"><sub><strong>Safe AST Formula Engine &amp; Dry-Run Diff Preview</strong> — Preview exact <code>Before → After</code> values before touching the database.</sub></p>
    </td>
    <td width="50%">
      <img src=".wordpress-org/screenshot-3.png" alt="Built-in Posts SEO Audit Lens with 60/160 character meters" />
      <p align="center"><sub><strong>Built-in SEO Audit Lens</strong> — Instant character count meters (<code>60</code> / <code>160</code>) for Yoast SEO &amp; Rank Math.</sub></p>
    </td>
  </tr>
</table>

---

## Why DataDeck vs Paid Alternatives

Essential WordPress admin workflows—editing ACF/custom fields inline, adjusting WooCommerce prices in bulk, auditing SEO metadata, and safely rolling back mistakes—are typically locked behind expensive annual subscriptions or split into per-post-type add-ons.

**DataDeck ships all of them 100% free and GPLv2+ open source.**

| Capability | Admin Columns Pro (\$79–\$199/yr) | WP Sheet Editor (\$79+/module) | SwiftGrid Pro (\$69/yr) | **DataDeck (Free · GPLv2+)** |
| :--- | :---: | :---: | :---: | :---: |
| **Posts, Pages, CPTs, Media, Comments &amp; Users** | Paid for Inline/Bulk | Separate Paid Plugins | Products Only | **Included** |
| **WooCommerce Products + Inline Variations** | Paid Add-on | Separate Paid Plugin | Partial Free | **Included** |
| **WooCommerce HPOS Orders** | Paid Add-on | Separate Paid Plugin | Not Supported | **Included** |
| **ACF / SCF / Meta Box / Native Meta** | Business Tier+ | Paid | Paid | **Included** |
| **Yoast, Rank Math, SEOPress &amp; AIOSEO Inline/Bulk** | Paid Add-on | Paid | Not Supported | **Included** |
| **Excel Paste (`Ctrl+V`), Fill Handle &amp; Live `SUM/AVG`** | Not Supported | Paid | Partial | **Included** |
| **Smart CSV / JSON Import + Dry-Run Diff + Rollback** | Not Supported | Paid | Paid | **Included** |
| **Computed AST Formula Columns (`fx`)** | Not Supported | Not Supported | Not Supported | **Included** |
| **Safe AST Bulk Formulas (`if`, `round`, `.99`) + Diff** | Not Supported | Paid | Price Only | **Included** |
| **Scheduled &amp; Auto-Reverting Bulk Campaigns** | Not Supported | Not Supported | Paid | **Included** |
| **Optimistic Concurrency Guard &amp; Cell/Batch Rollback** | Not Supported | Not Supported | Partial | **Included** |
| **Git-Syncable Views (`datadeck-json/`)** | Developer Tier | Not Supported | Not Supported | **Included** |
| **60 FPS Virtualized Grid + Constant `6–8 SQL`/Page** | Legacy `WP_List_Table` | Heavy DOM | Standard | **Included** |

---

## Core Features

* **Universal Entity Engine:** Switch seamlessly across **Posts, Pages, Custom Post Types, Media Library, Comments &amp; Reviews, Users, WooCommerce Products (with inline expandable Variations), and HPOS Orders**—or open directly from any native list screen via the `[ Classic List | DataDeck Grid ]` header toggle.
* **True Excel/Sheets Spreadsheet Ergonomics:** `0px` layout-shift cell editing, 3 density modes (`Compact 30px` / `Default 36px` / `Comfortable 48px`), pinned sticky Title column, drag-to-resize &amp; **drag-and-drop column header reordering**, **corner Fill Handle** with smart series inference (`SKU-001` → `SKU-002`, numeric progressions), **Right-Click Context Menu** (*Filter by value*, *Exclude value*, *Copy*, *Apply to selected*, *Cell audit history*), **Live Aggregation Footer Bar** (`SUM`, `AVG`, `MIN`, `MAX`, `COUNT`), **Excel/Google Sheets `Ctrl+C`/`Ctrl+V` matrix paste**, **Fill Down (`Cmd/Ctrl+D`)**, and **`Cmd/Ctrl+K` Command Palette**.
* **Smart CSV / JSON Import Wizard:** Upload or paste CSV/JSON spreadsheets, match existing records by **ID, SKU, Slug, or Email** in a single batch SQL lookup, auto-map headers, preview exact `old → new` row diffs before committing, apply in safe chunks, and undo the entire import in one click.
* **Virtual Computed AST Formula Columns (`fx`):** Built-in WooCommerce *Discount %* and *Inventory Value* columns plus custom user-defined AST formulas evaluated live without writing to the database—automatically hydrating hidden dependency fields.
* **Optimistic Concurrency Guard &amp; Cell-Level Rollback:** Detects concurrent edits from other admins before overwriting (showing server vs. local diffs with **Reload** or **Force Overwrite**), and tracks field-level history with **Single-Cell Restore** inside the Inspector drawer as well as full **One-Click Batch Rollback**.
* **Staged Save &amp; Instant Save:** Stage multiple edits across rows with a subtle top-right cell indicator and `Cmd/Ctrl+Z` client undo before committing in one atomic batch—or switch to Instant Save for rapid single-field updates.
* **Built-In Zero-Setup Audit Lenses:**
  * **WooCommerce:** *Pricing &amp; SKU Audit* (catches `sale_price >= regular_price`, missing prices/SKUs), *Inventory Risk* (out-of-stock &amp; low stock `<= 5`), *Missing Images*.
  * **Content &amp; SEO:** *SEO Audit* (Yoast, Rank Math, SEOPress &amp; AIOSEO — titles `> 60` chars, missing thumbnails/excerpts), *Content Hygiene*.
  * **Media &amp; Comments:** *Missing Alt Text*, *Pending Moderation*, *Product Reviews*, *Spam Queue*.
* **Safe AST Formula Bulk Engine (Zero `eval()`):** Compiles expressions once into a reusable AST using a strict recursive-descent parser with an explicit pure-function allowlist:
  * `if(regular_price > 0, round(regular_price * 0.85, 0) - 0.01, '')`
  * `if(stock_quantity == 0, 'outofstock', 'instock')`
  * `concat(upper(trim(sku)), ' - ', title)`
  * Templates: `{title} — {site_name} ({year})`
* **Scheduled Auto-Revert Campaigns &amp; Developer Workflow:** Schedule weekend sales that automatically revert to exact pre-campaign prices, sync role-scoped Saved Views via Git (`datadeck-json/`), automate via **WP-CLI**, and connect safely through the **WordPress 6.9+ Abilities API**.

---

## Performance &amp; Architecture

Engineered for **100,000+ SKU catalogs** and enforced by automated budgets in CI:

| Metric | Measured Result | Engineering Mechanism |
| :--- | :--- | :--- |
| **Public Frontend Footprint** | **`0 B` JS · `0 B` CSS · `0` SQL** | Early-exit context guard in `datadeck.php` registers zero hooks on public requests |
| **Initial Workspace Bundle** | **`29.2 KB` gzip** *(CI gate `<= 45 KB`)* | WP Core externalization + `React.lazy()` code-splitting for all 6 modals &amp; drawers |
| **Native List Switcher** | **`0.66 KB` gzip** | Zero-dependency toggle loaded only on supported `wp-admin` list screens |
| **Static Analysis &amp; Standards** | **PHPStan L6 (`0`) · WPCS (`0`) · PCP (`0`)** | Enforced across PHP 8.1 / 8.2 / 8.3 in GitHub Actions CI |
| **50 Products + ACF + Terms** | **`8 SQL` · `~2.0 ms`** *(constant O(1))* | `SmartQueryPlanner` routes price/SKU/stock to indexed `wc_product_meta_lookup`; `SelectiveHydrator` fetches only visible meta keys &amp; skips multi-MB `_elementor_data` blobs |
| **50 Posts + SEO + Terms** | **`5–6 SQL` · `~1.2 ms`** | Two-step ID planner (`no_found_rows`) + single-query selective term hydration |
| **50,000-Row CSV Export** | **Constant `~15 MB` PHP RAM** | Keyset cursor (`WHERE ID > $last_id`) streaming directly to `php://output` with formula-injection neutralization |

```mermaid
flowchart LR
    UI["React 18 + TS Virtualized Grid (--wpds-*)"] --> REST["REST /datadeck/v1/ (Field Projection + AbortController)"]
    REST --> Planner["SmartQueryPlanner (wc_product_meta_lookup + Two-Step IDs)"]
    REST --> Hydrator["SelectiveHydrator (Visible Meta & Terms Only)"]
    REST --> Bulk["BulkOperationEngine + Safe AST Parser (Zero eval)"]
    Bulk --> Audit["Delta Snapshot History & One-Click Rollback"]
```

---

## Installation

### Quick Install (No Build Required)
1. Download [`datadeck.zip`](https://github.com/duhanguler/datadeck/releases/latest/download/datadeck.zip) from the latest GitHub Release.
2. In WordPress Admin, go to **Plugins → Add New → Upload Plugin**, select `datadeck.zip`, and click **Activate**.
3. Open **DataDeck** from the admin sidebar—or click **DataDeck Grid** at the top of your Posts, Products, Orders, Media, or Users screen.

### Local Development (Docker)

```bash
git clone https://github.com/duhanguler/datadeck.git
cd datadeck
npm install
npm run build
npm run check:budget

# Start WordPress (PHP 8.2) + MariaDB + WP-CLI on http://localhost:8888
docker compose up -d

# Install WordPress, WooCommerce, Secure Custom Fields, DataDeck & demo dataset
docker compose exec -T wpcli wp core install --url=http://localhost:8888 --title="DataDeck Dev" --admin_user=admin --admin_password=password --admin_email=admin@datadeck.local --skip-email
docker compose exec -T wpcli wp plugin install woocommerce secure-custom-fields plugin-check --activate
docker compose exec -T wpcli wp plugin activate datadeck
docker compose exec -T wpcli wp eval-file /var/www/html/wp-content/plugins/datadeck/scripts/seed-demo-data.php --user=admin
```

Visit `http://localhost:8888/wp-admin/admin.php?page=datadeck` (`admin` / `password`).

---

## WP-CLI &amp; Quality Verification

```bash
# WP-CLI automation & live query benchmarks
wp datadeck status --user=admin
wp datadeck benchmark product --per-page=100 --user=admin
wp datadeck bulk product --field=sale_price --action=formula --value="if(regular_price > 0, round(regular_price * 0.85, 0) - 0.01, '')" --dry-run --user=admin
wp datadeck import product supplier-prices.csv --match=sku --dry-run --user=admin
wp datadeck rollback <batch-uuid> --user=admin
wp datadeck sync-json --user=admin

# Quality gates
npm run typecheck && npm run test:js && npm run build && npm run check:budget
composer test:unit && composer lint:php && composer stan
node scripts/build-release-zip.mjs
```

---

## Contributing &amp; License

Contributions are welcome—see [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md).

Licensed under **GPL-2.0-or-later**. Built by [Duhan Güler](https://github.com/duhanguler).
