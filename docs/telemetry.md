# Telemetry & Pendo plan

Safe Publish reports usage through two channels that both land in the WordPress VIP Pendo subscription:

- **Frontend tagging** — Pages and Features tagged in the Pendo UI, enabled on the plugin's admin screens via the `vip_pendo_allowed_screens` filter (`Plugin::register_pendo_screens`).
- **Backend events** — server-side Track events emitted through `Telemetry_Service`, a thin wrapper over the VIP mu-plugins `Automattic\VIP\Telemetry\Telemetry` client. Every event name is sent with the `safe_publish_` prefix and is snake_case. Properties are bounded enums or counts only — never titles, error messages, or URLs.

This document is the source of truth for what the plugin emits and the plan for the Pendo-side configuration that turns those signals into answers.

Pendo coordinates (for reference when doing the UI work):

- Subscription: **WordPress VIP** (`5636252415164416`)
- Application: **WordPress** (`6320138457251840`)

---

## Backend event catalog

All events carry the global properties set in `Plugin::init()` — `plugin_version` and `sync_mode` — plus the VIP library's standard context (`is_vip_user`, `vip_org`, `vip_env`, `wp_version`, `environment_type`, `hosting_provider`, `is_multisite`, `mu_plugins_version`, `site_id`).

| Event (`safe_publish_` prefix) | Trigger | Event-specific properties | Status |
| --- | --- | --- | --- |
| `single_import_completed` | End of single-import AJAX (`ajax_create_draft`) | `outcome` (`new`\|`updated`), `warning_count` | Live (registered in Pendo) |
| `bulk_import_completed` | End of bulk-import AJAX (`ajax_bulk_import`) | `batch_size`, `successful`, `failed`, `has_failures` | Live in code, awaiting first receipt |
| `import_item_failed` | Per-item error in `Post_Import_Service` | `error_code` (bounded enum), `session_type` (`single`\|`bulk`), `media_failure_count` (only for media errors) | Live in code, awaiting first receipt |
| `rollback_performed` | Both rollback handlers, success and failure | `scope` (`session`\|`item`), `deleted_count`, `restored_count`, `failed_count`, `outcome` (`success`\|`partial`\|`failed`) | Live in code, awaiting first receipt |
| `connection_test_completed` | End of test-connection AJAX (`ajax_test_connection`) | `outcome` (`authorized`\|`unauthorized`\|`blocked`\|`unreachable`) | **New — this change** |
| `sync_mode_configured` | Sync-mode option first set or changed | `previous_mode`, `new_mode` (bounded enums), `is_first_configuration` (bool) | **New — this change** |

> A Track event only appears in Pendo after it has been received at least once. As of this writing only `safe_publish_single_import_completed` has registered; the other events surface automatically once real traffic produces them. There is nothing to pre-create.

---

## A. Frontend tagging reconciliation (Pendo UI)

The code allowlist in `register_pendo_screens` is **correct** — it enables Pendo on all three real admin screens (`safe-publish`, `safe-publish-settings`, `safe-publish-audit-log`, across both admin modes). The mismatches are entirely on the Pendo-tag side and must be fixed in the Pendo UI (the API/MCP is read-only):

1. **Dead page tag.** The tagged Page "Safe Publish > Imports" matches `admin.php?page=safe-publish-imports`, but no such slug exists in the plugin — the import UI lives on the main `safe-publish` (Manage) page, already tagged as "Safe Publish > Main". Re-point this rule to a real slug or delete it, along with its orphaned child feature "Safe Publish > Imports > Edit".
2. **Missing page tag.** `safe-publish-audit-log` (Audit Log) is Pendo-enabled by the allowlist but has **no** Page tag, so its views are unattributed. Add a Page:
   - Audit Log → `//*/wp-admin/admin.php?page=safe-publish-audit-log`
3. **Typo.** Rename the Feature "Safe Publish > Test **Conection**" → "Safe Publish > Test **Connection**".

These are the only tagging changes; no code change is required.

---

## B. Export-side telemetry — held for approval

Import, rollback, connection-test, and configuration are now instrumented. The remaining coverage gap is the **export** side: in export/bidirectional mode the source site serves content to destinations via `Catalog_REST_Controller` and the core REST API. There is currently zero telemetry on that path.

This is deliberately **not** shipped in this change, because the only place to observe export activity is the live REST serving hot path (machine polling from destinations), and the project guardrail is that serving-path / backward-compatibility-sensitive changes get explicit human approval before implementation.

Recommended design when approved — a **throttled export heartbeat** rather than per-request telemetry:

- New event `export_served` with a bounded `outcome` (`success`\|`error`) property.
- Emitted from `Catalog_REST_Controller::handle_request`, throttled to at most once per hour per install via a site transient (`safe_publish_export_heartbeat`). This bounds volume to roughly 24 events/day/site while still confirming a source is actively exporting.
- Inject `Telemetry_Service` into `Catalog_REST_Controller` as a nullable constructor argument (single production construction site in `Plugin::init()`; the one test construction site keeps working with the default).

Open decision for the human reviewer: is a once-per-hour "is exporting" heartbeat enough, or is per-error visibility wanted (in which case throttle success but always emit `error`, still low-volume since errors are rare)?

---

## C. Pendo configuration to create (Pendo UI)

None of the following can be created through the Pendo MCP/API (it exposes read-only analytics). Each is a one-time Pendo-UI task; the definitions below are ready to hand off.

### C1. Product Area — "Safe Publish"

Every other plugin in this app (Remote Data Blocks, Parse.ly, Security Controls) has a Product Area; Safe Publish does not. Create one and add:

- Pages: Safe Publish > Main, Settings, Audit Log
- Features: the existing Safe Publish button tags
- Track events: all six `safe_publish_*` events

This makes the whole surface navigable and lets engagement metrics roll up in one place.

### C2. Segments

Reusable audiences for metrics and guide targeting:

- **Ran an import** — visitors/accounts with any `single_import_completed` or `bulk_import_completed`.
- **Hit import failures** — any `import_item_failed`, or `bulk_import_completed` where `has_failures = true`.
- **Rolled back** — any `rollback_performed` (regret signal).
- **Connection troubles** — `connection_test_completed` with `outcome` in (`unauthorized`, `blocked`, `unreachable`).
- **By sync mode** — three segments on the `sync_mode` global property (`import`, `export`, `bidirectional`).

### C3. Guides (optional, once events flow)

- Rollback confirmation nudge, or a "need help?" tooltip triggered off repeated `connection_test_completed` failures.
- Post-failure help pointer keyed off `import_item_failed`.

---

## D. Automated reporting (via Pendo MCP)

The Pendo MCP is read-only, so it can't create any of the above — but it can **read** everything once data flows, which is where automation pays off. A scheduled digest can pull, per window:

- Import volume and single-vs-bulk mix (`*_import_completed`).
- Per-batch success rate and `has_failures` share.
- Ranked `error_code` distribution (`import_item_failed`).
- Rollback / regret rate (`rollback_performed` by `outcome`).
- Connection-test outcome breakdown and onboarding funnel (`sync_mode_configured` → first import).

This can run as a recurring Claude Code job (`/loop` or a scheduled routine) posting to Slack or a P2. It depends only on the events above having registered in Pendo.

---

## What's automatable vs. manual

| Work | How |
| --- | --- |
| Backend events (this change: connection test, sync-mode) | Code — shipped |
| Track events appearing in Pendo | Automatic on first receipt — nothing to do |
| Export heartbeat event | Code — **held for human approval** (serving path) |
| Frontend tag fixes (A1–A3) | Manual, Pendo UI |
| Product Area, Segments, Guides (C1–C3) | Manual, Pendo UI |
| Usage reporting / digests (D) | Automatable on top of the read-only Pendo MCP |
