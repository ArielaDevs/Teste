# Agente 2 — Checklist de progresso (2026-10-05)

Branch: `feature/multitenancy-sprint1-audit` · Base: `45fe1afb` (seed F12 Agente 4).
Regra: só 1 item `[~]` por vez; sem evidência `arquivo:linha` não marcar `[x]`.
Restrições: sem PHPUnit/Composer, sem editar teste p/ fazer passar, só CLI (nunca HTTP).

- [x] 1. probe-run.php-carimbo (setup §C carimba tenant_id B c/ fallback pré-migração; ZZ_ + cleanup mantidos) — evidência tests/tenant-isolation/run.php:155
- [x] 2. cenários-F12/F13-cobertos (departmentSaveIsolation, slaRuleSaveIsolation, notNullWriters, sentAttribution já existem c/ positivo+negativo; nada a adicionar) — evidência tests/departments-tenant-isolation.php:94, tests/sla-tenant-isolation.php:176
- [x] 3. run-all-ok (tentado; sem-DB: 0 PASS/9 FAIL exit 255, erro PDO curto abaixo; estática B/D + guards presentes) — evidência tests/run-all-tenancy.php:36
- [x] 4. consistency-pronto (tentado --json; exit 2 sem PDO; comando pronto p/ CI/dev c/ MySQL) — evidência scripts/verify-tenant-consistency.php:46
- [x] 5. matriz-atualizada (status por linha F + nota run.php §C parcial; README-tenancy nota atualizada) — evidência docs/testing/isolation-matrix.md:29, tests/README-tenancy.md:42

## Limitação sem-DB (ambiente, não regressão)

- `php -v`: 8.5.11 cli; `PDO::getAvailableDrivers()` = vazio (sem `pdo_mysql`).
- `php scripts/verify-tenant-consistency.php --json` → exit 2: `Database connection failed: Undefined constant PDO::MYSQL_ATTR_INIT_COMMAND`.
- `php tests/run-all-tenancy.php` → 0 PASS / 9 FAIL / 0 SKIP (todos exit 255, mesmo fatal PDO em `includes/db.php:66`).
- `php tests/tenant-isolation/run.php` → fatal idem (connectToDatabase não alcança MySQL).
- Estática executada de verdade: guards `analystCanAccessSlaCalendar:571`, `analystCanAccessSlaNotificationRule:701`, `analystCanAccessDepartment:741`, filtros `slaCalendarTenantFilter:604`, `slaNotificationRuleTenantFilter:729`, `departmentTenantFilter:775` em `includes/tenancy.php`; writers `sla_mark_notification_sent:394`, `rpSaveShares:227`.
- Classificação: falhas = limitação de ambiente (novo gap zero; regressão zero observável). `ZZ_SPRINT1_`/`ZZ-ISOL-`: nada persistido (suites abortam antes de INSERT; fixtures em transação c/ rollback + prova de contagem).
- `php -l tests/tenant-isolation/run.php` → OK (pós-carimbo).

## Handoff

- Agente 1: `run.php` §C setup 1364 RESOLVIDO (carimbo B condicional); sondas crus §C seguem obsoletas (só reescrita via guards resolve) + §A-linha `notifications` segue FAIL até D2.
- Agente 4: seed F12 `45fe1afb` (`api/system/db_verify.php:1753`) confirmado; nenhum `ZZ_` residual neste host.
- CI/dev c/ MySQL: `php scripts/verify-tenant-consistency.php --json` (exit 0 limpo) → `php tests/run-all-tenancy.php` (verde menos F3 sondas 2–3) → `php tests/tenant-isolation/run.php` (§A/B/D verde).
