# Prioridade de Correção v2 — pós re-auditoria (Sprint Dev)

Estado: nenhum P0/P1 fechado (Agente 4 sem entrega nesta branch). 2 achados novos. Sinal: 🔴 NÃO-VERDE.

## P0 — Bloqueador

- **F12** skew schema/código: INSERTs sem `tenant_id` quebram em NOT NULL (`api/tickets/save_department.php:42`, `api/tickets/save_sla_calendar.php:93,101,110`, `api/system/db_verify.php:1753-1756` + fixtures). Quebra single-tenant. Primeiro, pois sem ele nada P0 é testável.
- **F7** SLA rules/engine · **F6** SLA calendars · **F8** departments · **F10** reply-templates-escrita · **F13** save/update sem gate (departments, SLA rules). Ordem: F12 → F8/F13 → F6 → F7 → F10 (F13 pega carona no gate de F8/F7).

## P1 — Alto

- **F9** Notifications Router (audiência + entidade por acesso). **F4** `getTenantConfigRows()` fail-open (`tenancy.php:1329`).

## P2 — Médio

- **F3** upload_recording choke-point. **F1** badge triagem. **F11** packs residual + F12c (preencher `sent.tenant`/`share.tenant` no INSERT).

## P3 — Info

- **F2** suppliers/contacts/wiki globais (contrato do produto). **F5** blocos nginx.

## Handoff Agente 4 (nova rodada)

F12 (+F12c) → F13 → F6 → F7 → F8 → F10 → F9 → F4 → P2/P3. PoCs, patches e controles em `docs/security/multitenant-pentest-v3.md`. Proibido declarar "corrigido" sem PoC-antes/depois + bypass V1–V3 + controle positivo.

## Handoff Agente 3

Ajustar seed/fixtures com `tenant_id` (parte de F12 é sua); sem nova coluna (F12c usa colunas 002/004 existentes). Reversões 005: NÃO executar (documentadas, alto custo).

## Handoff Agente 2

Re-run `tests/tenant-isolation/run.php` + novos `sla-*/departments-*/reply-*/report-*/upload-*/tenant-config-rows-*.php` **após** F12 (fixtures atuais inserem sem `tenant_id` e falham pós-migração). Cenários novos: `departmentSaveIsolation`, `slaRuleSaveIsolation`, `notNullWriters` (F12), `sentAttribution` (F12c).
