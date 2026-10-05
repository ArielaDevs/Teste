<?php
/**
 * Versioned migration 20261005_002: tenant_id for the SLA tables.
 *
 * PREREQUISITES: migration 001 applied (tenants table + default tenant exist).
 * Registry: schema_migrations.version = '20261005_002'.
 *
 * SCOPE
 * -----
 *   sla_calendars            ADD tenant_id INT, backfill default, tighten NOT NULL, FK CASCADE
 *   sla_calendar_hours       ADD tenant_id INT, inherit from parent calendar, tighten NOT NULL, FK CASCADE
 *   sla_calendar_holidays    ADD tenant_id INT, inherit from parent calendar, tighten NOT NULL, FK CASCADE
 *   sla_notification_rules   ADD tenant_id INT NULL (stays nullable), backfill default, FK SET NULL
 *   sla_notifications_sent   ADD tenant_id INT NULL (stays nullable), inherit from ticket, FK SET NULL
 *
 * NOTA BENE — name correction: the sprint brief lists `sla_notifications_cron_runs`,
 * which does not exist. The real table is `sla_cron_runs` (see schema line ~668,
 * cron/sla_breach_check.php, docs/sla-cron-setup.md). It is DELIBERATELY EXCLUDED:
 * it is a global operational/security log (cron invocations, per-IP failed-auth
 * lockouts, outcomes) with no tenant parent — the same category as `system_logs`,
 * which is also tenant-less. Manufacturing a tenant_id for failed-auth rows from
 * arbitrary IPs would be fabricated data. Documented as GLOBAL in
 * docs/migrations/multitenant-null-semantics.md.
 *
 * NULL SEMANTICS (this migration)
 * --------------------------------
 *   sla_calendars          NULL = transitional only. Post-migration NOT NULL:
 *                          every calendar belongs to exactly one company.
 *   sla_calendar_hours / holidays
 *                          NULL = transitional only. Denormalised from the parent
 *                          calendar (see below), then NOT NULL.
 *   sla_notification_rules NULL = GLOBAL rule (fires for every company). Set = one
 *                          company's rule. department_id NULL already means "no
 *                          department scope"; tenant NULL extends that to "no company
 *                          scope". Stays nullable by design.
 *   sla_notifications_sent NULL = transitional/unresolvable (ticket deleted before
 *                          migration). Log rows must never block deletes, so the
 *                          column stays nullable with FK SET NULL.
 *
 * CHILD INHERITANCE — denormalised tenant_id (NOT composite FK). Justification:
 *   1. The project uses simple FKs everywhere (api/system/db_verify.php has zero
 *      composite FKs); a composite FK (tenant_id, calendar_id) would demand a
 *      composite UNIQUE on the parent and is unenforceable tooling-wide.
 *   2. Reads filter by tenant_id directly — no JOIN to the parent needed — which
 *      is what ticketTenantFilter()/activeTenantFilter() consume.
 *   3. Drift (child.tenant != parent.tenant) is detectable by a cheap query, and
 *      scripts/verify-tenant-consistency.php checks exactly that before/after.
 *   Existing parent FKs (fk_sla_hours_calendar, fk_sla_holidays_calendar,
 *   fk_sla_notif_rule_dept, all CASCADE) are left untouched.
 *
 * ORDERING NOTE: departments are tenanted in migration 003, AFTER this one.
 * Rules are therefore backfilled to the default tenant (correct on single-tenant
 * installs: departments themselves all land on default in 003). The consistency
 * script re-checks rule.tenant vs department.tenant once both apply.
 *
 * BACKUP: `_bak_t1_<table>` snapshots before any ALTER (created once) + mysqldump:
 *   mysqldump -h <host> -u <user> -p <dbname> > freeitsm_pre_002_sla.sql
 *
 * USAGE: preview default; --apply executes; --rollback reverts (drops added
 * columns, restores replaced indexes best-effort, checksum-verified, keeps
 * backups unless --drop-backups). Idempotent: re-runs repair structure only.
 */

require_once __DIR__ . '/lib_tenant_migration.php';

tenant_migration_boot(
    [
        'version' => '20261005_002',
        'description' => 'tenant_id for SLA tables',
        'prereq_versions' => ['20261005_001'],
        'tables' => [
            [
                'table' => 'sla_calendars',
                'column' => 'tenant_id',
                'add_def' => 'INT NULL',
                'backfill' => 'default',
                'not_null' => true,
                'indexes' => [
                    ['uq_sla_calendars_tenant_name', 'unique', '(`tenant_id`,`name`)'],
                    ['ix_sla_calendars_tenant_active', 'key', '(`tenant_id`,`is_active`)'],
                    ['ix_sla_calendars_tenant', 'key', '(`tenant_id`,`id`)'],
                ],
                'fk_action' => 'CASCADE',
                'fk_name' => 'fk_sla_calendars_tenant',
            ],
            [
                'table' => 'sla_calendar_hours',
                'column' => 'tenant_id',
                'add_def' => 'INT NULL',
                'backfill' => ['parent' => ['sla_calendars', 'calendar_id', 'tenant_id', 'default']],
                'not_null' => true,
                'indexes' => [
                    ['ix_sla_hours_tenant_calendar', 'key', '(`tenant_id`,`calendar_id`)'],
                    ['ix_sla_hours_tenant', 'key', '(`tenant_id`,`id`)'],
                ],
                'fk_action' => 'CASCADE',
                'fk_name' => 'fk_sla_hours_tenant',
            ],
            [
                'table' => 'sla_calendar_holidays',
                'column' => 'tenant_id',
                'add_def' => 'INT NULL',
                'backfill' => ['parent' => ['sla_calendars', 'calendar_id', 'tenant_id', 'default']],
                'not_null' => true,
                'indexes' => [
                    ['ix_sla_holidays_tenant_calendar', 'key', '(`tenant_id`,`calendar_id`)'],
                    ['ix_sla_holidays_tenant', 'key', '(`tenant_id`,`id`)'],
                ],
                'fk_action' => 'CASCADE',
                'fk_name' => 'fk_sla_holidays_tenant',
            ],
            [
                'table' => 'sla_notification_rules',
                'column' => 'tenant_id',
                'add_def' => 'INT NULL',
                'backfill' => 'default',
                'not_null' => false, // NULL = global rule (all companies)
                'indexes' => [
                    ['ix_sla_rules_tenant_active', 'key', '(`tenant_id`,`is_active`)'],
                    ['ix_sla_rules_tenant_dept', 'key', '(`tenant_id`,`department_id`)'],
                ],
                'fk_action' => 'SET NULL',
                'fk_name' => 'fk_sla_rules_tenant',
            ],
            [
                'table' => 'sla_notifications_sent',
                'column' => 'tenant_id',
                'add_def' => 'INT NULL',
                'backfill' => ['parent' => ['tickets', 'ticket_id', 'tenant_id', 'default']],
                'not_null' => false, // log rows must never block deletes
                'indexes' => [
                    ['ix_sla_sent_tenant_ticket', 'key', '(`tenant_id`,`ticket_id`)'],
                    ['ix_sla_sent_tenant', 'key', '(`tenant_id`,`id`)'],
                ],
                'fk_action' => 'SET NULL',
                'fk_name' => 'fk_sla_sent_tenant',
            ],
        ],
    ],
    array_slice($argv, 1)
);
