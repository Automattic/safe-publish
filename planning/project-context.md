# Project Context

## Product Name
**Safe Publish** (internal working name; product brief calls it "Compliant Content Publisher")

## One-liner
A WordPress plugin enabling secure, efficient content transfer between non-production (staging/dev) and production WordPress environments, targeted at regulated industries.

## Product Owner
Jacob Smith

## Target Users
- **Primary:** Content managers in regulated industries (pharmaceuticals, finance) — moderate WordPress knowledge, limited dev skills.
- **Secondary:** IT administrators and compliance officers — higher technical knowledge, responsible for configuration.

## Tech Stack
- **Language:** PHP 8.2+, TypeScript/React (Gutenberg components)
- **Platform:** WordPress 6.8+, WordPress VIP
- **Build:** Webpack, Vitest, PHPUnit, Playwright (integration tests)
- **Standards:** WordPress coding standards, VIP-safe practices

## Code Location
- Project root: `/mnt/project/`
- Plugin PHP entry: `safe-publish.php`
- Source (JS/TS): `src/`
- PHP includes: `includes/`
- Tests: `tests/`
- Docs: `docs/`

## MVP Features (Must-Have)
1. **Content Discovery** — list recently updated content on non-prod site, check URL conflicts, support posts/pages/CPTs and `wp_posts`-stored content (nav, footers).
2. **Content Transfer** — WordPress REST API, media + metadata + taxonomies, URL/path search-replace, create draft on production.
3. **Non-Post Content Management** — diff view for menus/taxonomies, backup prior state for rollback.
4. **Batch Publishing** — select and publish multiple transferred items simultaneously.
5. **Basic Rollback** — revert most recent batch.

## Out of Scope (No-Goes)
- Strict legal audit log (content approval controls, archival storage).
- Monitoring/archiving versions beyond rollback of last batch.

## Success Metrics
- 100% Merck adoption within 1 month of release.
- ≥3 additional enterprise customers within 3 months.
- ≥20% reduction in content production time.

## Key Constraints
- HTTPS required between environments.
- REST API must be accessible (corporate VPN edge case).
- Investigate Playground zip-export and staging2live.com before committing to custom REST approach (time-boxed ≤2 days).
- Comprehensive docs and knowledge transfer to CMS Engineering team on handover.

## Status
Active development — codebase exists with secure auth, content preview, bulk import, media handling, block preservation, import history, and CPT support. Plugin is in **Beta**.
