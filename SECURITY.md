# Security Policy

## Supported Versions

| Version | Supported |
| :--- | :--- |
| 1.x | Yes |

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities.

Instead, report vulnerabilities privately via GitHub Security Advisories (`Security > Advisories > Report a vulnerability`) on `duhanguler/datadeck`. We aim to acknowledge reports within 48 hours and release patched versions promptly.

## Security Architecture Highlights

- Every REST endpoint verifies `is_user_logged_in()`, `X-WP-Nonce`, and record-level `current_user_can()` capabilities.
- Protected metadata is guarded by `is_protected_meta()` checks.
- The AST bulk formula engine uses a strict recursive-descent parser with an explicit pure-function allowlist—**PHP `eval()` is never called**.
- CSV streaming neutralizes spreadsheet formula injection (`=`, `+`, `-`, `@`).
