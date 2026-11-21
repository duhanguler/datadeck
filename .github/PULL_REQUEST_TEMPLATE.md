## Summary
- Describe what changed and why.

## Checklist
- [ ] `npm run typecheck && npm run build && npm run check:budget` passes (`datadeck-app.js <= 45 KB` gzip)
- [ ] `composer test:unit` and `composer lint:php` pass with 0 errors
- [ ] Zero frontend assets or SQL queries added on public pages
- [ ] No N+1 SQL queries introduced (`<= 8 SQL` per page)
