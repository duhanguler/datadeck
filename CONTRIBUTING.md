# Contributing to DataDeck

Thank you for considering contributing to **DataDeck**. Every pull request—from new field providers and bulk operators to translations and performance improvements—is welcome.

## Development Setup

```bash
git clone https://github.com/duhanguler/datadeck.git
cd datadeck
npm install
npm run build
docker compose up -d
```

Provision WordPress, WooCommerce, Secure Custom Fields, and demo data:

```bash
docker compose exec -T wpcli wp core install --url=http://localhost:8888 --title="DataDeck Dev" --admin_user=admin --admin_password=password --admin_email=admin@datadeck.local --skip-email
docker compose exec -T wpcli wp plugin install woocommerce secure-custom-fields plugin-check --activate
docker compose exec -T wpcli wp plugin activate datadeck
docker compose exec -T wpcli wp eval-file /var/www/html/wp-content/plugins/datadeck/scripts/seed-demo-data.php --user=admin
```

## Non-Negotiable Engineering Standards

1. **Zero Frontend Footprint:** Never hook into public front-facing requests.
2. **Query Budget SLA:** Hydrating 50–500 rows must remain O(1) in SQL count (`<= 8 SQL` queries). Never introduce N+1 meta or taxonomy loops.
3. **Bundle Size Gate:** Keep `datadeck-app.js` under `45 KB` gzipped (`npm run check:budget`). Lazy-load non-critical modals/drawers.
4. **Design Discipline:** Use `--wpds-*` WordPress admin tokens, `0px` layout-shift cells, `tabular-nums` for numbers, and zero emojis or decorative gradients in UI chrome.
5. **Coding Standards & Tests:** Every PR must pass:
   - `npm run typecheck && npm run build && npm run check:budget`
   - `composer test:unit` & `composer lint:php`
   - `wp plugin check datadeck` (0 errors)
