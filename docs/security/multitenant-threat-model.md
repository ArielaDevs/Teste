# Modelo de Ameaça Multi-Tenant — FreeITSM

Data: 2026-10-05 · Base: `includes/db_verify_schema.php` (fonte de verdade), `includes/tenancy.php`, `docs/multitenancy-gaps.md`, pentest v2 (`docs/security/multitenant-pentest-v2.md`).

## 1. Atores e fronteiras

| Ator | Capacidade | Fora do escopo para ele |
|------|-----------|-------------------------|
| Analista restrito (Tenant A) | sessões, IDs enumeráveis, RBAC/teams do próprio tenant | tudo de B (tickets, calendários SLA, departamentos, templates de A) |
| Analista com cap de settings (`TICKETS_SLA`, `TICKETS_DEPARTMENTS`, `TICKETS_REPLY_TEMPLATES`) | gerir config do(s) próprio(s) tenant(s) | config de tenants sem acesso |
| Usuário do portal | próprios tickets (`portalTicketAccess`) | tickets de outros usuários/tenants |
| API key (v1) | escopo da key | departamentos/tickets fora do escopo |
| Cron sem usuário (`cron/sla_breach_check.php`) | lê todos os tenants (sistema) | deve aplicar regras só do tenant do ticket |
| Admin | todos os tenants (incl. debug-tools) | — |

Fronteira de confiança: **todo `WHERE id=?` by-id precisa de gate; toda listagem precisa de filtro; e-mail/notificação precisa de audiência do mesmo tenant.**

## 2. Classificação canônica (100% das tabelas relevantes)

### 2a. Por-tenant — `NULL = Default-owned` (só visível sob Default; `ticketTenantFilter`/`activeTenantFilter`; id desconhecido → deny)

`tickets`, `users`, `assets` (+`asset_locations`, `asset_field_sets`, `asset_import_profiles`), `cmdb_objects`, `changes`, `problems`, `tasks`, `domains`, `domain_registrar_accounts`, `documents`, `search_documents`, `messaging_templates`, `tenant_settings`, `cost_centres`, `target_mailboxes` (NULL=shared-intake operacional, leitura global de conexão), `ticket_assets` e filhas (herdam do ticket), `ticket_audit`, `ticket_notes`, `email_attachments`/`emails` (herdam do ticket).
**Aplicados (Sprint 2, migrations 002/003, NOT NULL puro — sem NULL-global):** `departments`, `sla_calendars` (+`hours`/`holidays` desnormalizados do pai), `sla_notifications_sent` (herdado do ticket; NULL só transitório/pré-migração). `sla_notification_rules`: por-tenant com **NULL = regra global opt-in** (compatível com §2a para linhas com tenant e §2c para NULL). Regra child-table: horas/holidays, comentários/subtasks, anexos seguem o pai.

### 2b. Compartilhado por desenho — `NULL = shared with every company` (visível de todos; `knowledgeTenantFilter`/casos especiais)

`knowledge_articles`, `knowledge_folders`, `messaging_channels` (NULL=intake compartilhado; `analystCanAccessChannel` retorna true), `target_mailboxes` (conexão), `tenant_domains`/`tenant_sender_addresses`/`tenant_channel_senders` (roteamento), `tenant_config_hidden`, `analyst_tenant_access`/`team_tenant_access` (matriz de acesso).

### 2c. Config global + override — `NULL = global default` (herdado salvo `tenant_config_hidden`; `getTenantConfigRows()` por empresa, nunca união plana)

`ticket_types`, `ticket_categories`, `ticket_origins`, `ticket_resolution_codes`, `ticket_reply_templates` (privados `analyst_id=X` seguem a pessoa, fora de tenant), `asset_types`, `asset_status_types`, `ticket_statuses`, `ticket_priorities`.
(D1 fechado 2026-10-05: `sla_calendars` e `departments` pertencem **somente** ao §2a — isolamento puro NOT NULL, migrations 002/003. Ver `docs/migrations/reconciliation-agente1.md` §1–2 e `guard-patterns.md` itens 1/3.)
**Armadilha documentada:** simplificar para `activeTenantFilter()` esconde artigos compartilhados de tenants não-Default; união plana de config vaza item escondido por A para B.

### 2d. Global de instalação (sem `tenant_id`, sem mudança)

`system_settings`, `tenants`, `analysts`/`teams`/`analyst_teams` (identidade; acesso via matriz), `suppliers`, `supplier_contacts`/`contacts`, wiki (`wiki_files/functions/db_references`), `notifications`/`portal_notifications` (por-analista/por-usuário; considerar `tenant_id` denormalizado só para auditoria), `sla_cron_runs`.
`report_packs`/`report_pack_shares` ganharam `tenant_id NULL` (migration 004; NULL = draft pessoal) — acesso segue por owner/share e **dados** pelo viewer via `rpTenantClause`.
`contracts` usa `customer_tenant_id` — história de isolamento própria, fora do Sprint 2.

## 3. Controles que funcionam (manter)

`analystCanAccess*` + filtros; `allAccessibleTenantsFilter` vazio → `AND 1=0`; `tenancyDegradeAllowed` (só missing-schema perdoa); `isMultiTenant()` dormente em N=1; `rpTenantClause` (erro em vez de dado); `requestedTenantId` (id inacessível → fallback ativo); switcher `active_tenant_all` separado de magic-id; sessão mínima + `regenerate_id`.

## 4. Lacunas aceitas vs. corrigir

Aceitas (documentar no contrato do produto): F2, F5, leitura de conexão de mailbox/canal, `notifications` sem tenant denormalizado.
Corrigir (Sprint 2): F6, F7, F8, F10 (P0/P1); F9, F11-residual, F1, F3, F4 (P1/P2).
