# Multi-tenant NULL semantics — table × meaning matrix

> Source of truth for what `tenant_id = NULL` means on every tenant-carrying
> table. Every tenant migration must consult this file before choosing
> `backfill-to-default` vs `keep NULL`. Guards and filters (Agent 4) must
> implement exactly the reading below; `scripts/verify-tenant-consistency.php`
> encodes the same map for its NULL inventory.
>
> Legend — **D** = Default-owned (NULL is transitional/triage, backfill to the
> default tenant) · **S** = Shared (NULL visible to every company) ·
> **G** = Global/config (NULL = global default, never backfilled) ·
> **C** = Child-owned (NOT NULL, owned via the parent tenant row) ·
> **P** = Personal (NULL = owner-only, not company data).

## Scoped data — D (Default-owned)

NULL means "not yet assigned to a company". Backfilled to default on
single-tenant installs; afterwards NULL = triage/unrouted and surfaces under
Default via `ticketTenantFilter()` / `activeTenantFilter()`.

| Table | tenant column | Notes |
|---|---|---|
| users | tenant_id NULL | Pre-fill from email domain on multi-tenant (001); freemail stays NULL → triage. |
| tickets | tenant_id NULL | NULL = inbound email matched no company → TRIAGE queue. |
| assets | tenant_id NULL | One company per asset; NULL treated as Default-owned. |
| tasks | tenant_id NULL | Scoped data (GH #83 groundwork). |
| changes | tenant_id NULL | Change Management twin of tickets. |
| problems | tenant_id NULL | Problem Management twin of tickets. |
| domains | tenant_id NULL | Module #154; scoped, never shared. |
| domain_registrar_accounts | tenant_id NULL | Credential owner company. |
| cmdb_objects | tenant_id NULL | Exactly one company per CI; no shared CIs. |
| documents | tenant_id NULL | Company document (links cascade). |
| contracts | customer_tenant_id NULL | Customer side of the contract. |
| cost_centres | tenant_id NOT NULL | **C** — always one company's (GH #160). |
| search_documents | tenant_id NULL | Search mirror; `tenant_scope` disambiguates Default-owned vs shared sources. |
| sla_calendars (002) | tenant_id NOT NULL | One company per calendar after 002. |
| sla_calendar_hours (002) | tenant_id NOT NULL | **C**-like: denormalised from parent calendar, then NOT NULL. |
| sla_calendar_holidays (002) | tenant_id NOT NULL | Same as hours. |
| sla_notification_rules (002) | tenant_id NULL | NULL here is **G** (global rule) — see below. Non-NULL rows are D. |
| sla_notifications_sent (002) | tenant_id NULL | Inherited from ticket; NULL = ticket gone pre-migration. |
| departments (003) | tenant_id NOT NULL | Pure isolation (003 decision record). |
| report_pack_shares (004) | tenant_id NULL | Inherited from parent pack. |

## Shared — S (NULL visible to every company)

| Table | Notes |
|---|---|
| knowledge_articles | NULL = shared library article (e.g. "reset your password"); resolved per-company PLUS shared, never Default-only. |
| knowledge_folders | Same sharing model as articles. |

## Global / config — G (NULL = global default, never backfilled)

Resolved via `getTenantConfigRows()`: global defaults + the company's own rows,
minus rows hidden through `tenant_config_hidden`. Backfilling these would
privatise shared data the day a second company is added.

| Table | Notes |
|---|---|
| ticket_types / ticket_origins / ticket_categories / ticket_resolution_codes | Global catalogues. |
| asset_types / asset_status_types / asset_locations | Global lists; locations NULL also covers head office. |
| ticket_reply_templates | **FASE 4 audit — NO schema change.** NULL tenant_id = global shared team template (config axis), orthogonal to analyst_id NULL = shared vs private (ownership axis). A global shared template is both columns NULL; reads filter on both axes. Altering this would break `getTenantConfigRows()` resolution. |
| checklist_templates | Global template catalogue. |
| sla_notification_rules (002) | NULL = global rule firing for every company (extends the existing department_id-NULL = "no department scope" convention). |
| auth_providers | NULL = global provider shown on analyst login; tenant-scoped rows are client IdPs. Backfilling would hide SSO. |
| apikeys | NULL = unpinned ingest key. |
| target_mailboxes | NULL = shared intake (routed per sender), NOT unmigrated data. Pinned on first migration by design (001 precedent). |
| messaging_channels / messaging_templates | Same connection-shaped tenancy as mailboxes. |
| integration_connections | NULL = MSP-global connection. |

## Child-owned — C (NOT NULL, FK to tenants)

Owned through their tenant row; deleted with it (CASCADE). No backfill question —
rows cannot exist without a company.

`tenant_domains`, `tenant_sender_addresses`, `tenant_channel_senders`,
`tenant_settings`, `tenant_config_hidden`, `analyst_tenant_access`,
`team_tenant_access`.

## Personal — P

| Table | Notes |
|---|---|
| report_packs (004) | NULL = personal owner-only draft (report twin of reply-template privates). Set = company pack. |

## Deliberately tenant-less (no column, by design)

| Table | Rationale |
|---|---|
| sla_cron_runs | Global operational/security log (invocations, per-IP failed-auth lockouts). Same category as `system_logs`. Fabricating a tenant for failed-auth rows from arbitrary IPs would be invented data. Sprint brief named it `sla_notifications_cron_runs` — that table does not exist; the real name is `sla_cron_runs`. |
| system_logs, analysts, teams, … | Infra / people tables outside the company-data boundary. |

## Rules for future migrations

1. New scoped data → **D**, backfill once on first apply, never re-backfill.
2. New catalogue/config → **G**, keep NULL, resolve via `getTenantConfigRows()`.
3. New child-of-tenant → **C**, NOT NULL + CASCADE.
4. Document the choice in the migration header AND append the row here.
