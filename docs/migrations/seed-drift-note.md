# Aviso formal de drift: `db_verify_schema.php` × `database/freeitsm.sql`

Data: 2026-10-05 · Escopo: migrations 002/003/004 (a 001 já havia introduzido drift próprio).

## O que está em drift

`includes/db_verify_schema.php` (o que o Database Verify aplica em installs
EXISTENTES) contém o que `database/freeitsm.sql` (o que constrói installs NOVOS)
ainda não tem:

- 8 colunas `tenant_id`: `sla_calendars`, `sla_calendar_hours`,
  `sla_calendar_holidays`, `sla_notification_rules`, `sla_notifications_sent`,
  `departments`, `report_packs`, `report_pack_shares`.
- 15 índices da lista em `includes/db_verify_indexes.php` marcada
  "Tenant migrations 002-004".
- 1 unique trocado: `uq_departments_name` (global) → `uq_departments_tenant_name`
  (por tenant). **Atenção:** aqui o drift é duplo — o seed ainda cria o unique
  global, que a 003 remove em installs existentes.

## Por que isso importa (precedente real)

O header de `includes/db_verify_column_parse.php` documenta o caso
`asset_locations.tenant_id`: adicionado ao Verify mas não ao seed — install novo
quebrou na tela de locations enquanto upgrades funcionavam. Fresh-install é o
único caminho que um estranho usa; upgrade é o único que o desenvolvedor testa.
Sem correção, cada item acima repete esse bug na direção "novo install sem a
coluna até alguém rodar Verification".

## Quem atualiza o seed, quando e como verificar

- **Quem:** o mantenedor do processo de seed (hoje: edição manual de
  `database/freeitsm.sql` pelo release manager — ver `RELEASING.md`; NÃO foi feito
  nesta sprint por restrição explícita do escopo).
- **Quando:** antes da próxima release que contenha as migrations 002–004; sem
  isso, installs novos dessa release nascem sem as colunas.
- **Como:**
  1. Espelhar as 8 colunas (mesmos tipos e comentários) e os 15 índices no
     `CREATE TABLE` correspondente em `database/freeitsm.sql`; trocar o unique de
     `departments` para `(tenant_id, name)`.
  2. Re-rodar `php scripts/gen_db_verify_indexes.php` e confirmar que as 15
     entradas marcadas 002–004 passam a ser geradas (podendo então remover o
     marcador manual).
  3. Verificar com o self-check: rodar Database Verify
     (`api/system/db_verify.php` ou `php scripts/db_verify_cli.php --apply` num
     install de teste) e confirmar zero "red cards" de
     `dbVerifyColumnSelfCheck()` — ele compara as duas fontes a cada execução.
  4. Prova final: subir um container **novo** a partir do seed e abrir as telas de
     SLA, departamentos e report packs (o caminho que o desenvolvedor nunca testa).

## Writers (F12/F12c) — parte do fechamento do drift

Schema sem writer compatível quebra criação (pentest-v3 F12). Ao atualizar o
seed, carimbar `tenant_id` também nos writers do próprio seed/processo:

- `api/system/db_verify.php:1753-1756` (seed de calendário/horas → tenant
  default; especificação em `docs/migrations/d1-addendum-20261005.md` §2).
- Fixtures dos testes de isolamento do Agente 2
  (`tests/sla-tenant-isolation.php:66-80`,
  `tests/departments-tenant-isolation.php:65-67`,
  `tests/tenant-isolation/run.php:150-158`,
  `tests/report-packs-tenant-isolation.php:89`) — carimbam os tenants A/B do
  fixture; edição pertence ao Agente 2, não a esta rodada.
- Prova de fechamento: `php scripts/verify-tenant-consistency.php --json`
  (somente leitura) com `would_block` e `drift` vazios após o seed atualizado.

## Status

**PENDENTE — drift conhecido e aceito temporariamente.** Este arquivo é o
registro formal; fechar este item = seed atualizado + self-check verde + teste de
fresh-install. Nada nesta sprint depende disso para upgrades (o Verify cobre),
mas nenhuma release com 002–004 deve sair sem isso.
