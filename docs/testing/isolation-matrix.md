# Isolation matrix — tenant gaps × tests × status (Agente 2, pós-seed-F12 45fe1afb)

F12/F13/P0 commitados (`35c19a9e`) + seed F12 (`45fe1afb`). Cada linha: o teste que prova o achado,
o que mostrava **Antes** (pré-fix), o que deve valer **Depois** (critério de
aceite, verificado nesta rodada onde o ambiente permite). Controles passam em
todas as linhas; só as sondas de gap viram.

Limite de ambiente desta rodada: sem `pdo_mysql` e sem BD com ≥2 tenants
neste host — nada com banco executou aqui. Evidência DB-backed é
**projetada** (sondas + fixtures prontas para o CI/dev); evidência estática
(seções B/D de `tenant-isolation/run.php` espelhadas + assinaturas de guards)
foi executada de verdade. Coluna "Evidência" diz qual é qual.

| # | Achado | Teste (cenários) | Antes | Depois (aceite) | Evidência | Estado |
|---|---|---|---|---|---|---|
| F1 | Badge triagem | — sem cobertura (badge global, sem sonda) | OPEN | registrar decisão ou cobrir | — | 🔴 sem cobertura |
| F2 | Suppliers/contacts/wiki globais | — sem cobertura (by-design, threat model §2d) | INFO | nenhuma ação | — | ⚪ contrato do produto |
| F3 | `upload_recording.php` fora do choke-point | `tests/upload-recording-hardening.php` sondas 2–3 (MIME, nome) | FAIL | sniff independente do claim; nomes neutralizados | projetada (P0 não tocou F3) | 🔴 OPEN |
| F3-claim | Claim cross-user de pending | `upload-recording-hardening.php` sonda 1 | PASS | segue PASS | projetada | 🟢 |
| F4 | `getTenantConfigRows()` fail-open em erro transitório | `tests/tenant-config-rows-failclosed.php` (sonda 1205) | FAIL (retornava ambas) | `[]` em transitório (via `tenancyDegradeAllowed`) | projetada + fakes validados de verdade* | 🟢 esperado |
| F5 | Blocos nginx | — sem cobertura (hardening pendente) | INFO | nenhuma ação | — | ⚪ pendente |
| F6 | SLA calendars (V1 leitura, V2 wipe de hours, V3 sequestro de default) | `tests/sla-tenant-isolation.php` (gates F6, filtro, herança de filhos, save-path) | FAIL | zero linhas estrangeiras; escritas recusadas | projetada + estática B PASS de verdade | 🟢 esperado |
| F7 | SLA rules/engine (V1 delete, V2 rewrite de `notify_emails`, V3 global+externo) | `tests/sla-tenant-isolation.php` (gates F7, filtro A+global, `slaRuleSaveIsolation`, engine, `sentAttribution`) | FAIL | gates negam; engine casa por tenant+global; `sent.tenant` = ticket | projetada + estática B PASS de verdade | 🟢 esperado |
| F8 | Departments (V1 delete, V2 rename + cascata de sensitivity, V3 lista v1) | `tests/departments-tenant-isolation.php` (gates F8, filtro, `departmentSaveIsolation`, `notNullWriters`) | FAIL | zero linhas estrangeiras; save recusado antes da cascata | projetada + estática B PASS de verdade | 🟢 esperado |
| F9 | Router/notificações (audiência por payload, sino como oráculo) | `tests/notifications-router-isolation.php` (`notificationsRecipientMaySeeEntity` + fluxo espelho do router) | FAIL | forjado escreve nada; sino sem linhas estrangeiras | projetada + estática D PASS de verdade | 🟢 esperado |
| F10 | Reply-templates escrita (V1 overwrite, V2 delete, V3 demote) | `tests/reply-templates-isolation.php` (write-scope + global-precisa-all-reach) | FAIL | `null` p/ estrangeiro e global scoped | projetada + estática B PASS de verdade | 🟢 esperado |
| F11 | Report packs (role por time cross-tenant) | `tests/report-packs-tenant-isolation.php` (role/lista + gate de pack-tenant P2.3) | FAIL | `null` + ausente da lista | projetada | 🟢 esperado |
| F11-shares | `share.tenant` denormalizado (F12c) | `report-packs-tenant-isolation.php` (invariante + fonte do writer) | n/a (novo) | shares carregam o tenant do pack | projetada (código confirma `rpSaveShares` carimba) | 🟢 esperado |
| F12 | Skew schema/código: writers sem `tenant_id` (NOT NULL) | `sla-*` + `departments-*` (`notNullWriters`); `run.php` §C setup carimbado Agente 2 | FAIL (1364) | writers carimbam (ativo/Default); inserts passam | projetada + seed `45fe1afb` confirma carimbo; probe `run.php:155` carimba B (fallback pré-migração) | 🟢 esperado |
| F12c-sent | `sent.tenant` sempre NULL | `sla-tenant-isolation.php` (`sentAttribution`) | FAIL (NULL) | `sent.tenant` = ticket | projetada (código confirma carimbo) | 🟢 esperado |
| F13 | Save/update sem gate (departments, SLA rules) | `departmentSaveIsolation`, `slaRuleSaveIsolation` | FAIL | 403/404 indistinguível; linha intocada | projetada | 🟢 esperado |
| D2 | Coluna `notifications` adiada | — sem cenário (adiado, sem coluna) | — | Agente 1 decide; `run.php` §A-linha `notifications` segue FAIL até lá | — | 🟡 adiado/escalado |
| run.php §C | Sondas crus sem guarda + setup sem `tenant_id` | `tests/tenant-isolation/run.php` §C (Agente 1, reescrita pendente; setup carimbado Agente 2) | FAIL (setup 1364; sondas crus sempre retornam) | setup carimba B (`run.php:155`, fallback pré-migração); sondas via guards na reescrita Agente 1 | setup corrigido (lint OK); sondas seguem obsoletas até reescrita | 🟡 parcial/escalado |
| run.php §B | Sondas estáticas de endpoints | idem §B | 5/7 PASS pré-P0 | 6/7 PASS (`pack get` limitado: gate vive em `rpRole`, coberto no teste de packs) | executada de verdade (espelho) | 🟢 (1 limitação documentada) |

\* Fakes `ZZIsolFakeStmt`/`ZZIsolTransientConn` executados de verdade contra o
`tenancy.php` real: `isMultiTenant(fake)=true`, `tenancyDegradeAllowed(1205)=false`,
`getTenantConfigRows` no blip retornou ambas as linhas (fail-open reproduzido —
a sonda que hoje deve passar).

## Handoff

- **Agente 1 (cobertura):** Fs provados por teste: F3-claim, F4, F6, F7
  (+engine, +sent), F8, F9, F10, F11 (+shares), F12, F12c-sent, F13. Sem
  cobertura: F1, F2 (contrato), F5 (pendente), D2 (adiado). `run.php` §C
  setup 1364 resolvido pelo carimbo Agente 2 (`run.php:155`); sondas crus
  precisam da sua reescrita (não enxergam guards); §A-linha `notifications`
  Fail até D2 decidir.
- **Agente 4 (falhas):** nenhuma falha DB-backed observável neste host
  (sem `pdo_mysql`/BD). Se qualquer sonda "🟢 esperado" falhar no CI/dev, é
  **regressão P0** — log + reprodução seguem o padrão do arquivo (seção
  nomeada, `FAIL` cita as linhas vazadas, fixtures `ZZ-ISOL-`).
- **Agente 3:** nada pendente de schema para estes testes (002/003/004
  aplicados no desenho das fixtures). Reversões 005 continuam NÃO executar.

## Como re-verificar (com BD)

```
php scripts/verify-tenant-consistency.php --json   # pré-flight, read-only
php tests/run-all-tenancy.php                      # suite (exit 1 = gaps abertos)
php tests/tenant-isolation/run.php                 # legado A/B/C/D (Agente 1)
```

Verde = todas as linhas "🟢 esperado" passam e `run.php` §A/B/D passam
(§C após a reescrita do Agente 1). `upload-recording-hardening.php` sondas
2–3 seguem FAIL (F3 OPEN) — esperado até o fix.
