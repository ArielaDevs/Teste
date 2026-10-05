# Prioridade de Correção — Multi-Tenant (para Sprint Dev)

## P0 — Bloqueador (cross-tenant destrutivo ou com vazamento ativo)

- **F7** SLA rules/engine (`api/tickets/delete_sla_notification_rule.php:26`, `includes/sla_notifications.php:38-314`) — PoC e patch em `docs/security/multitenant-pentest-v2.md#F7`. Handoff Migração: `sla_notification_rules.tenant_id INT NULL` (NULL=global default).
- **F6** SLA calendars (`api/tickets/get_sla_calendar.php:24`, `delete_sla_calendar.php:39`, `save_sla_calendar.php:89-114`, `includes/sla.php:95`) — v2#F6. Migração: `sla_calendars.tenant_id INT NULL` (NULL=global default); filhas herdam.
- **F8** Departments (`api/tickets/delete_department.php:29`, `get_departments.php:24`, `api/v1/resources/reference.php:102`) — v2#F8. Migração: `departments.tenant_id INT NULL` (NULL=global default).
- **F10** Reply templates write (`includes/reply_templates.php:142`, `api/tickets/save_reply_template.php:68-100`, `delete_reply_template.php:33-46`) — v2#F10. Sem migração (coluna existe; corrigir gate para checar `tenant_id`).

## P1 — Alto (vazamento limitado ou pré-requisito de P0)

- **F9** Notifications Router (`includes/notifications_router.php:143-191,273`) — filtrar audiência por acesso ao objeto; v2#F9.
- **F4** `getTenantConfigRows()` fail-open (`includes/tenancy.php:1329`) — só fallback em missing-schema; v2#F4.

## P2 — Médio (hardening e residuais)

- **F3** `api/self-service/upload_recording.php:40-77` → `uploadStoreFile()`; checar claim `recorded_by_user_id`.
- **F1** `includes/tenancy-switcher.php:52` badge de triagem → escopar por acesso a Default.
- **F11** Report Packs residual — validar targets de share; opcional `tenant_id` no pack.

## P3 — Baixo/Info (documentar)

- **F2** suppliers/contacts/wiki globais — declarar no contrato do produto.
- **F5** blocos nginx `deny all` para diretórios de anexos.

## Handoff — Agente 3 (Migração)

| Tabela | Coluna | Semântica NULL | Índice | Backfill |
|--------|--------|----------------|--------|----------|
| `sla_calendars` | `tenant_id INT NULL` | global default | `(tenant_id,name)` | Default id |
| `departments` | `tenant_id INT NULL` | global default | `(tenant_id,name)` | Default id |
| `sla_notification_rules` | `tenant_id INT NULL` | global default | `(tenant_id,trigger_type)` | Default id |
| `sla_calendar_hours/holidays` | — (herdam via `calendar_id`) | — | — | — |
| `sla_notifications_sent` | — (herda via `ticket_id`) | — | `(ticket_id,target_type,trigger_type)` já dedup | — |
| `notifications` | `tenant_id INT NULL` (opcional, auditoria) | Denormalizado do objeto | `(tenant_id,analyst_id)` | NULL permitido |

Idempotente `ADD COLUMN IF NOT EXISTS`, reversível `DROP COLUMN`, filtros `['',[]]` em N=1.

## Handoff — Agente 4 (Sprint Dev)

Ordem: F7 → F6 → F8 → F10 → F9 → F4 → F3 → F1 → F11 → F2/F5. Cada item tem PoC + patch mínimo + controle positivo em `docs/security/multitenant-pentest-v2.md`.

## Handoff — Agente 2 (Testes)

Cenários novos em `tests/tenant-isolation/run.php` (convenção CLI, sem PHPUnit): `slaCalendarIsolation` (F6, pai+filhas), `slaNotificationRuleIsolation` (F7, delete+motor), `departmentIsolation` (F8, lista+delete+API v1), `notificationAudienceIsolation` (F9), `replyTemplateWriteIsolation` (F10), `reportPackIsolation` (F11), `triageBadgeIsolation` (F1), `recordingUploadIsolation` (F3), `configRowsFailClosed` (F4). Regra child-table: todo teste de pai inclui as filhas endereçadas por id do pai.
