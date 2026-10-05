# Agente 1 — Checklist de auditoria (2026-10-05, HEAD `55d399ed`)

Branch: `feature/multitenancy-sprint1-audit` · Base v3 (`multitenant-pentest-v3.md` 🔴) + addendum D1 (`docs/migrations/d1-addendum-20261005.md`) + matriz Agente 2 (`docs/testing/isolation-matrix.md`).
Método: grep/`php -l`/leitura. Sem `pdo_mysql` (`PDO::getAvailableDrivers()`=vazio) e sem BD ≥2 tenants → auditoria **estática por identidade binária** (limitação de ambiente, não regressão). Nenhum `api/`/`includes/`/`tests/` modificado por este agente.

- [x] F12 skew schema/código — CLOSED-estático. Writers carimbam: `save_department.php:68` (insert c/ tenant_id), `save_sla_calendar.php:141,156,172` (cal+hours+holidays), `save_sla_notification_rule.php:211-220`, `sla_notifications.php:406` (`sent.tenant` do ticket), seed `db_verify.php:1768-1770` (Default c/ probe pré-001). `php -l` OK.
- [x] F13 save/update sem gate — CLOSED-estático. `save_department.php:46` gate update + cascata sob gate `:87-104`; `save_sla_calendar.php:93-104` gate + pai-gate (filhas herdam pai, nunca ativo); `save_sla_notification_rule.php:83-92` gate regra+departamento, `:140` global exige all-access, `:154-164` invariante mesma-empresa.
- [x] F6 SLA calendars — CLOSED-estático. `tenancy.php:571` `analystCanAccessSlaCalendar` + `:604` `slaCalendarTenantFilter`; gates `get_sla_calendar.php:27`, `delete_sla_calendar.php:33`, `save_sla_calendar.php:93`; motor por ticket.tenant `sla.php:65-130`; `is_default` escopado `:130`.
- [x] F7 SLA rules/engine — CLOSED-estático. `tenancy.php:701` + `:683/:729` filtro; gates `delete_sla_notification_rule.php:29`, `save_sla_notification_rule.php:83`, list `get_sla_notification_rules.php:50`; motor `sla_notifications.php:217-237` (`tenant_id=? OR NULL` do ticket); `sent` carimbado `:394-412`.
- [x] F8 Departments — CLOSED-estático. `tenancy.php:741` + `:775` filtro; gates `delete_department.php:33`, `save_department.php:46`, lists `get_departments.php:27`, `get_my_departments.php:37`, `get_team_departments.php:30`, counts `get_ticket_counts.php:44`, `assign_ticket_department.php:47`, `get_orphaned_tickets.php:67`, v1 `reference.php:103-131`.
- [x] F10 reply-templates escrita — CLOSED-estático. `replyTemplateWriteScope` tenant-aware `reply_templates.php:148-185` (global exige all-access `:182-183`); save `:69` + delete `:33` via WriteScope.
- [x] F9 notifications router — CLOSED (P1, code-only, D2 adiado). `notificationsRecipientMaySeeEntity` `notifications_router.php:262-282` (desconhecido→deny, N=1→pass); audiência filtrada `:76-82`. Sem coluna `notifications.tenant_id` por D2 — classificado, sem exigir fix.
- [x] F4 fail-closed — CLOSED (P1). `getTenantConfigRows` `tenancy.php:1558-1573`: transitório→`[]`+log, só missing-schema cai em all-rows.
- [x] Regressão tickets — SEM REGRESSÃO. `git diff 4c1990ef...HEAD -- includes/tenancy.php` sem toques em `analystCanAccessTicket` (`:266`)/`ticketTenantFilter` (`:916`); diff P0 só adiciona guards/filtros. `php -l` limpo (7 arquivos).
- [x] D1 — FECHADO (S). Threat model `§2c:31` não lista `departments`/`sla_calendars`; nota D1 em `:32` + `§2a:23` detém NOT NULL puro. Nenhuma edição necessária (única edição permitida não executada por desnecessária). Addendum `d1-addendum-20261005.md` existe e é coerente.

## Limitação sem-DB (igual Agente 2)

`php -v` 8.5.11 cli; `PDO::getAvailableDrivers()` vazio; PoCs HTTP/SQL não executáveis aqui → vereditos CLOSED-estáticos, a confirmar com `verify-tenant-consistency.php --json` + `run-all-tenancy.php` em CI/dev com MySQL.
