# Reconciliação Agente 1 (auditoria) × Agente 3 (migração)

Data: 2026-10-05 · Estado: migrations 002/003/004 aplicadas (preview OK, rollback documentado).
Base da auditoria: `docs/security/multitenant-threat-model.md` §2a/§2c/§2d.
Base da migração: `docs/migrations/multitenant-null-semantics.md` + cabeçalhos das migrations.

Leitura prévia indispensável: o threat model lista `departments` em **duas**
categorias incompatíveis — §2a (por-tenant, NULL=Default-owned) e §2c (config
global+override). As duas não podem valer ao mesmo tempo para a mesma tabela; boa
parte do "conflito" abaixo é essa contradição interna, não uma divergência real.

## 1. departments — DECISÃO FINAL: manter Agente 3 (isolado puro, NOT NULL, 003)

**Posição Agente 1:** §2a diz por-tenant; §2c propõe config-rows global+override.
**Posição Agente 3 (aplicada):** isolado puro — `tenant_id NOT NULL`, FK CASCADE,
unique global `uq_departments_name` trocada por `uq_departments_tenant_name`.

**Justificativa técnica (3 fatos de código, não opinião):**

1. `departments` é um **hub referencial**, não um catálogo de picker. Consumidores
   reais: `tickets.department_id` (upgrade de confidencialidade, discussion #62),
   `sla_notification_rules.department_id` (FK CASCADE), `department_teams`,
   `manager_grants.target_value` e `report_pack_shares.target_value` (nomes
   livres). Nenhum deles resolve via `getTenantConfigRows()` — o primitivo do
   modelo config-rows é usado hoje só por catálogos (types, origins, categories,
   resolution codes, asset types/status/locations, reply templates; verificado por
   grep em 2026-10-05). Adotar config-rows exigiria construir plumbing novo
   (entity_type no `tenant_config_hidden` + religar todos os consumidores) sem
   nenhum call site existente para reaproveitar.
2. O modelo config-rows falha **aberto** por construção: a lista global é visível
   salvo exclusão explícita em `tenant_config_hidden`. Confidencialidade de ticket
   (§2, fronteira "todo WHERE id=? precisa de gate") passaria a depender de cada
   consumidor lembrar de checar a hide-list; um departamento "HR" global seria
   legível onde quer que tickets de HR sejam legíveis, em todas as empresas.
   Isolamento NOT NULL falha **fechado**: linha sem empresa não existe.
3. A migração 003 implementa exatamente o §2a do próprio threat model. Manter 003
   resolve a contradição interna do Agente 1 na direção que o §2a já prescreve.

**Impacto se a decisão contrária fosse tomada (config-rows):** departamentos
globais visíveis a todas as empresas em filtros de ticket, regras de SLA e times;
rebaixamento silencioso da fronteira de confidencialidade; cada novo consumidor
de `departments` precisaria lembrar do filtro de hide-list (o padrão de erro que o
§2 do threat model manda eliminar).

**Custo de uma eventual reversão (documentado, NÃO executado):** nova migration
005 — relaxar para NULL, re-globalizar linhas (destrói o isolamento recém-criado),
desfazer a troca de unique, criar entity_type + religar ~6 consumidores,
regressão total de filtros ticket/department. Estimativa: 6–10 h, risco ALTO
(toca confidencialidade). Recomendação: Agente 1 remover `departments` do §2c.

## 2. sla_calendars — DECISÃO FINAL: manter Agente 3 (NOT NULL, isolado, 002)

**Posição Agente 1:** §2c, config global+override (horas/holidays herdam).
**Posição Agente 3 (aplicada):** `tenant_id NOT NULL`, FK CASCADE, uniques/keys
por tenant; filhas com `tenant_id` desnormalizado herdado do pai.

**Justificativa técnica:**

1. Calendários não são catálogo escolhido pelo usuário — são **resolvidos por
   cadeia referencial**: `ticket_priorities.sla_calendar_id`
   (`fk_ticket_priorities_sla_calendar`, SET NULL) e
   `webchat_widgets.business_calendar_id`. Nenhum consumidor usa
   `getTenantConfigRows()` para calendários. O modelo config-rows não tem onde se
   pendurar sem religar a cadeia prioridade→calendário→cálculo de breach.
2. Com override global, um ticket da empresa B poderia resolver para um calendário
   customizado da empresa A por confusão de override — e o cálculo de SLA (que o
   threat model §1 atribui ao cron sem usuário, com a obrigação de "aplicar regras
   só do tenant do ticket") deixaria de ser verificável por igualdade simples.
   Com propriedade isolada, a invariante `ticket.tenant == calendar.tenant` é
   checável por query barata (`verify-tenant-consistency.php` faz exatamente isso).
3. Horas/holidays de um calendário global serviriam todas as empresas de qualquer
   jeito — o "compartilhamento" que o §2c quer já existe via empresa Default sem
   custo de plumbing.

**Impacto se a decisão contrária fosse tomada:** religar resolução de calendário
em prioridades, webchat e cron; risco de breach computado com calendário de outra
empresa (SLA errado = notificação errada = potencial vazamento de existência de
ticket via e-mail de audiência errada, a fronteira que o §1 destaca).

**Custo de reversão (documentado, NÃO executado):** migration 005 — relaxar NOT
NULL, re-globalizar, FK/tighten desfeitos, entity_type novo, religar 3 cadeias de
resolução + cron. Estimativa: 4–8 h, risco MÉDIO-ALTO. Recomendação: Agente 1
mover `sla_calendars` do §2c para §2a.

## 3. sla_notification_rules — DECISÃO FINAL: convergem (manter 002, sem mudança)

Não há conflito material. O §2a do threat model quer rules por-tenant; a 002
entrega exatamente isso **e** preserva NULL como regra global opt-in
(herdado da convenção `department_id NULL` = sem escopo). Linhas com tenant
comportam-se byte-a-byte como o §2a prescreve; linhas NULL seguem a regra global
já prevista no §2c para catálogos. Confirmação em uma linha: **rules com tenant
set são por-tenant Default-owned; NULL é global — compatível com §2a e §2c.**

**Impacto se fosse NOT NULL puro (a única "alternativa" real):** regras
catch-all sem departamento (o caso padrão de breach) seriam forçadas para a
Default e **parariam de disparar para as demais empresas — perda silenciosa de
notificação**. Por isso a 002 não aperta. Custo de apertar depois, se o
orquestrador exigir: migration 005 trivial (backfill + MODIFY), risco BAIXO no
schema e MÉDIO no comportamento (exige recriar regras globais por empresa).

## 4. Concordâncias (1 linha cada)

- `sla_notifications_sent` por-tenant via ticket (§2a): **de acordo** — 002 herda do ticket com fallback default.
- `sla_calendar_hours`/`holidays` herdam do pai: **de acordo** — 002 desnormaliza do calendar (§2, regra child-table).
- `ticket_reply_templates` NULL=global + `analyst_id` ortogonal: **de acordo** — FASE 4 só documentou, schema intocado.
- `sla_cron_runs` global sem coluna: **de acordo** — §2d lista como instalação-global.
- `tenant_config_hidden`/`tenant_domains`/`tenant_sender_addresses`/matrizes de acesso como infra compartilhada: **de acordo** — 001 não relaxationou.
- `knowledge_articles`/`folders` NULL=shared: **de acordo** — 001 excluiu do backfill.

## 5. Divergência adicional observada (fora do escopo do conflito, sem ação)

O §2d classifica `report_packs`/`report_pack_shares` como instalação-global sem
`tenant_id`, enquanto a 004 adicionou coluna NULLABLE (NULL = pack pessoal).
Na prática não há quebra: linhas migradas foram todas para default, linhas NULL
novas comportam-se como globais para leitura, e `rpTenantClause` continua
autoritativo. Se o orquestrador exigir o "sem mudança" estrito, o custo é uma
migration 005 de remoção de coluna (pequena, risco BAIXO) — **aguardando decisão
explícita; nada executado nesta rodada.**
