<?php
/**
 * Versioned migration 20261005_003: tenant_id for departments.
 *
 * PREREQUISITES: migrations 001 (tenants + default) and 002 (SLA) applied.
 * Registry: schema_migrations.version = '20261005_003'.
 *
 * SCOPE
 * -----
 *   departments  ADD tenant_id INT, backfill default, tighten NOT NULL,
 *                FK fk_departments_tenant CASCADE, replace the global unique
 *                uq_departments_name (`name`) with per-tenant
 *                uq_departments_tenant_name (`tenant_id`,`name`),
 *                plus KEY ix_departments_tenant (`tenant_id`,`id`).
 *
 * DECISION — isolado puro (pure isolation, NO global-with-override).
 * -------------------------------------------------------------------
 * A department is referenced by security- and routing-sensitive joins:
 *   - tickets.department_id (confidentiality upgrades, discussion #62),
 *   - sla_notification_rules.department_id (FK CASCADE — a department's breach
 *     rules notify its teams),
 *   - department_teams (team scope), manager_grants target_value,
 *   - report_pack_shares target_value (free-text department name).
 * A "global" department visible to every company would leak across all of these
 * at once: a ticket filed under a global HR department would be readable wherever
 * HR tickets are readable, in every company. Sharing is achieved by creating a
 * same-named department per tenant (the per-tenant unique allows exactly that),
 * never by a cross-company row. Hence NOT NULL, no NULL-global meaning.
 *
 * NULL SEMANTICS: NULL = transitional only (between ADD and backfill/tighten).
 * Post-migration every department belongs to exactly one company.
 *
 * UNIQUE SWAP: the pre-existing global uq_departments_name would forbid two
 * companies from each having "HR". It is dropped and replaced. ROLLBACK re-adds
 * it best-effort and FAILS CLOSED (reported, non-zero exit) if per-tenant
 * duplicates were created in the meantime — data is never silently merged.
 *
 * ORDERING: runs after 002 on purpose. SLA rules backfilled to default in 002
 * stay consistent because departments also land on default here; the consistency
 * script verifies rule.tenant == department.tenant afterwards.
 *
 * BACKUP: `_bak_t1_departments` snapshot before any ALTER (created once) +
 * mysqldump:
 *   mysqldump -h <host> -u <user> -p <dbname> > freeitsm_pre_003_departments.sql
 *
 * USAGE: preview default; --apply executes; --rollback reverts (drops the added
 * column + indexes, restores uq_departments_name best-effort, checksum-verified,
 * keeps backups unless --drop-backups). Idempotent: re-runs repair structure only.
 */

require_once __DIR__ . '/lib_tenant_migration.php';

tenant_migration_boot(
    [
        'version' => '20261005_003',
        'description' => 'tenant_id for departments (pure isolation)',
        'prereq_versions' => ['20261005_001', '20261005_002'],
        'tables' => [
            [
                'table' => 'departments',
                'column' => 'tenant_id',
                'add_def' => 'INT NULL',
                'backfill' => 'default',
                'not_null' => true,
                'drop_indexes' => ['uq_departments_name'],
                'indexes' => [
                    ['uq_departments_tenant_name', 'unique', '(`tenant_id`,`name`)'],
                    ['ix_departments_tenant', 'key', '(`tenant_id`,`id`)'],
                ],
                'fk_action' => 'CASCADE',
                'fk_name' => 'fk_departments_tenant',
            ],
        ],
    ],
    array_slice($argv, 1)
);
