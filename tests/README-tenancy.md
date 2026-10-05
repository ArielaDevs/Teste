# Tenancy isolation tests

Developer tooling. **Not part of the application.** Every file here follows the
suite convention in `tests/README.md`: CLI only (`PHP_SAPI` guard), real
database, everything inside a transaction that is **always rolled back**, and a
cleanup proof at the end. Run from the repository root, against a **DEV**
install only.

## Running

Needs PHP with `pdo_mysql` and a DEV database with 2+ companies (most files
SKIP cleanly on a single-company install with exit `2`).

```
php tests/run-all-tenancy.php          # whole tenancy suite + summary
php tests/run-all-tenancy.php --json   # same, JSON for CI
php tests/ticket-tenant-isolation.php  # one file
```

Exit codes per file: `0` = pass, `1` = fail, `2` = skip (e.g. fewer than two
companies, missing fixture rows). The orchestrator exits non-zero when any
file fails; skips are not failures.

## The files

| File | What it proves | Pós-P0 |
|---|---|---|
| `ticket-tenant-isolation.php` | Ticket gates end to end (§1–5) + other entities' direct-URL gates (§6: asset/change/problem/task/CMDB/domain) + search branches (§7: assets/knowledge, incl. shared-NULL semantics) | PASS |
| `sla-tenant-isolation.php` | F6/F7 gates + filters, F13 save gates, F12 writers, F12c sent attribution, engine matching | PASS esperado |
| `departments-tenant-isolation.php` | F8 gates + filter, F13 save gate, F12 writers | PASS esperado |
| `notifications-router-isolation.php` | F9 recipient filter + router-mirror flow, bell oracle | PASS esperado |
| `report-packs-tenant-isolation.php` | Pack-tenant gates, unshared 404, F12c shares attribution | PASS esperado |
| `reply-templates-isolation.php` | F10 write-scope (incl. global-needs-all-reach), picker | PASS esperado |
| `tenant-config-rows-failclosed.php` | F4: transient → zero rows | PASS esperado |
| `upload-recording-hardening.php` | Claim ownership (PASS); MIME sniff + name neutralisation (F3 OPEN → FAIL) | PARCIAL (F3 aberto) |
| `tenant-isolation/run.php` (Agente 1, não editar) | Sprint-1 A/B/C/D | ver nota abaixo |

"PASS esperado" = o fix P0 aterrissou; se qualquer sonda falhar, é
**regressão** (reabrir com Agente 1), não gap novo. Estado por achado em
`docs/testing/isolation-matrix.md`.

> Nota sobre `tenant-isolation/run.php`: a seção C faz SELECTs crus sem guarda —
> pós-002/003 as sondas crus sempre retornam linhas por construção. O setup §C
> já carimba `tenant_id` do probe (Agente 2, `run.php:155`, com fallback
> pré-migração). Não editar além disso (Agente 1 é dono da reescrita das
> sondas via guards): a seção está marcada como parcial na matriz até a
> reescrita dele. A seção A segue válida exceto a linha `notifications`
> (coluna adiada, D2).

## Reading a failure

Every scenario has a **positive control** — a neighbouring assertion that
passes only if the checker works. A file where *everything* fails (including
controls) means the fixtures or the environment are broken, not that a leak
was found. A file where controls pass and gap probes fail means a real leak:
the `FAIL` line quotes the leaked rows.

## Adding a new one

1. Copy the header pattern: guard, WHY docblock, `ok()` helper, SKIP exits.
2. Fixtures use the `ZZ-ISOL-` prefix (tickets, users, tenants, and any new
   table) so the cleanup proof and a human `SELECT` can find them.
3. Scope the analyst(s) **before** any tenancy call — results cache per
   analyst per process.
4. Drive only functions that take an explicit `PDO`. Anything opening its own
   connection cannot see the transaction's fixtures (`connectToDatabase()` is
   not a singleton).
5. Roll back always; prove it with counts; exit non-zero on failure.
6. If the file name matches `*isolation*.php`, `*failclosed*.php` or
   `*hardening*.php` (plus `tenant-isolation/run.php`, owned by Agente 1),
   the orchestrator picks it up with no further wiring.
7. Pre-flight on migrated installs:
   `php scripts/verify-tenant-consistency.php --json` (read-only; exit 0 =
   clean). Fixtures must stamp `tenant_id` per migrations 002/003/004 —
   inserts without it break on NOT NULL (F12).
