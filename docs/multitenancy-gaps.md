# Multi-tenancy gap matrix (Sprint 1 audit)

Audit date: 2026-10-05. Branch: `feature/multitenancy-sprint1-audit` (off `main`).
Authoritative schema source: `includes/db_verify_schema.php` — `database/freeitsm.sql`
is a seed snapshot and lags behind (30 tables with `tenant_id` there vs ~50 via
Database Verify). Single source of tenant logic: `includes/tenancy.php` (~42 functions).

No production code was changed in Sprint 1. Executable proof lives in
`tests/tenant-isolation/run.php` — every gap below has a failing check there
that must turn green in Sprint 2.

## NULL semantics — read before adding any column

`tenant_id IS NULL` means three different things. Getting this wrong either hides
rows that should be visible or exposes rows that should be hidden:

| Meaning | Tables | Rule |
|---|---|---|
| `NULL = Default-owned` (scoped data) | `tickets`, `assets`, `changes`, `problems`, `tasks`, `domains`, `cmdb_objects`, `users` | Visible only while Default is the active company. Enforced by `ticketTenantFilter()` / `activeTenantFilter()`. Unknown id → deny. |
| `NULL = shared with every company` | `knowledge_articles`, `knowledge_folders`, `messaging_channels`, `target_mailboxes` | Visible from ALL companies. Enforced by `knowledgeTenantFilter()` and the `analystCanAccessChannel()` special case (shared intake returns true). Never "simplify" these into `activeTenantFilter()` or shared articles disappear for non-Default companies. |
| `NULL = global default` (config rows) | `ticket_types`, `ticket_categories`, `ticket_origins`, `ticket_resolution_codes`, `ticket_reply_templates`, `asset_types`, `asset_status_types` | A global row every company inherits unless hidden via `tenant_config_hidden`. Resolved per company by `getTenantConfigRows()` / `getTenantConfigRowsByCompany()` — never as one flat union, because company A may hide a global row company B still uses. |

New columns in Sprint 2 MUST declare which of the three they follow. Default
proposal: `sla_calendars` and `departments` follow the config-rows meaning
(`NULL = global default`), because a single-company install has exactly one set
of each and must keep working unchanged; per-tenant overrides are additive.

## Sprint 2 scope — gap matrix

| # | Table(s) | Has `tenant_id`? | Guard today? | Gap |
|---|---|---|---|---|
| 1 | `sla_calendars` | No (`db_verify_schema.php:620`) | None — no `analystCanAccessSlaCalendar()` | `api/tickets/delete_sla_calendar.php:39` deletes by id with no gate; `get_sla_calendar.php:24,30,40` reads calendar/hours/holidays with no gate; `includes/sla.php:95,347` picks default calendar with no scope |
| 2 | `sla_calendar_hours`, `sla_calendar_holidays` | No (630, 638) | None | Child rows keyed by `calendar_id`; inherit whatever the parent decides. `save_sla_calendar.php:99,108` rewrites them in cascade with no gate |
| 3 | `sla_notification_rules` | No (645) | None | `delete_sla_notification_rule.php:26` deletes by id with no gate; `includes/sla_notifications.php:214` engine runs unscoped |
| 4 | `departments` | No (232) | None — `get_departments.php:24-26` lists globally | `delete_department.php:29` deletes by id with no gate; `get_ticket_counts.php:106,121,160,175` joins departments 4x with no filter; `api/v1/resources/reference.php:104` exposes them on the public API |
| 5 | `ticket_reply_templates` | Yes (NULL = global) | Partial — `replyTemplateWriteScope()` checks owner `analyst_id` only, not tenant | `delete_reply_template.php:45` depends on that gate, so a cross-tenant delete by id is reachable |
| 6 | `notifications`, `portal_notifications` | No (2735) | None in `includes/notifications_router.php` (391 lines, zero `tenant` references) | Audience resolved from `task_collaborators` / `analyst_teams` / assignee with no company check; `notificationsEntityFor:273` selects `ticket_number` by id with no gate (existence oracle for forged events) |
| 7 | `report_packs`, `report_pack_shares` | No (4572) | None | All five endpoints in `api/reporting/packs/` (`get/save/delete/create/shares`) act by id with no tenant filter |

Intentionally global (no change): `system_settings` — install-wide by design.
`contracts` carries `customer_tenant_id` (`db_verify_schema.php:4164`), not `tenant_id`;
its own isolation story is separate and out of Sprint 2 scope.

## Already isolated (do not regress)

`tickets`, `users`, `assets` (+ `asset_locations`, `asset_field_sets`, `asset_import_profiles`),
`cmdb_objects`, `changes`, `problems`, `tasks`, `domains`, `domain_registrar_accounts`,
`knowledge_articles`, `knowledge_folders`, `documents`, `search_documents`,
`messaging_channels`, `messaging_templates`, `target_mailboxes`, `tenant_settings`,
`cost_centres`, routing tables (`tenant_domains`, `tenant_sender_addresses`,
`tenant_channel_senders`, `tenant_config_hidden`) and access tables
(`analyst_tenant_access`, `team_tenant_access`). Guards: `analystCanAccess*` family
plus `ticketTenantFilter` / `activeTenantFilter` / `activeTenantReadFilter` /
`allAccessibleTenantsFilter` (fail-closed on empty scope with `AND 1 = 0`) and
`tenancyDegradeAllowed` (only missing-schema errors forgive; everything else denies
and logs). Master switch `isMultiTenant()` is dormant at N=1, so single-tenant
installs are unaffected.

## Child-table rule (learned from the asset disks miss)

Every guard-the-parent fix must also guard the child writes addressed by parent id:
task comments/subtasks, asset disks/software/history, SLA hours/holidays,
change checklist/comments/attachments. The test in `tests/tenant-isolation/run.php`
asserts parent and child together for this reason.

## Sprint 2 migration contract (design only — not applied)

- Idempotent `ADD COLUMN IF NOT EXISTS tenant_id INT NULL` + backfill existing rows
  to the Default tenant id (never to 0/-1; there is no magic id — see the
  `active_tenant_all` note in `tenancy.php:602`).
- Composite indexes `(tenant_id, <search col>)` on `sla_calendars(name)`,
  `departments(name)`, `sla_notification_rules` per engine query order.
- Reversible: `DROP COLUMN` restores single-tenant shape; no data loss because
  single-tenant rows all carry the same Default id.
- Retrocompat: every filter returns `['', []]` at N=1, so dormant installs see
  zero behaviour change.
