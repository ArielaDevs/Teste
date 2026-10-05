# Prioridade de Correção v3 — pós gate 🟢 (o que resta, HEAD `55d399ed`)

Sinal: 🟢 MERGEÁVEL. P0 (F6/F7/F8/F10) + F12/F13 + P1 (F9 code-only, F4) CLOSED-estáticos, verificados em `pentest-v4.md`. D1 fechado (S). Regressão: nenhuma.

## Resta (não bloqueia merge)

- **P1-residual:** `report_pack_shares.tenant_id` sem escritor ativo (F12c-parcial; coluna inerte, só leitura futura).
- **P2:** F1 (badge triagem), F3 (`upload_recording.php` choke-point), F11-telas de pack com criteria cross-tenant (erro `blocked` já correto).
- **P3/Info:** F2 (suppliers/contacts/wiki globais — contrato do produto), F5 (blocos nginx).
- **Pré-release (não pré-merge):** seed drift `database/freeitsm.sql` (`seed-drift-note.md`); SAVE de `get_mapping.php` (re-auditar quando implementado).

## Handoff

- **Orquestrador:** merge liberado; D1 já documentado em threat-model `§2a:23`/`§2c:31-32` + `d1-addendum-20261005.md` (nada a cobrar do Agente 3).
- **Agente 4:** nada reaberto.
- **Agente 2/CI com MySQL:** `php scripts/verify-tenant-consistency.php --json` (exit 0) → `php tests/run-all-tenancy.php` (verde menos F3 sondas 2–3) → `php tests/tenant-isolation/run.php`.
