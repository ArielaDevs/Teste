<?php
/**
 * Versioned migration 20261005_004: tenant_id for report_packs (+ shares).
 *
 * PREREQUISITES: migrations 001–003 applied.
 * Registry: schema_migrations.version = '20261005_004'.
 *
 * SCOPE
 * -----
 *   report_packs         ADD tenant_id INT NULL (stays nullable), backfill default,
 *                        KEY ix_report_packs_tenant (`tenant_id`,`id`), FK SET NULL.
 *   report_pack_shares   ADD tenant_id INT NULL (stays nullable), inherit from parent
 *                        pack, KEY ix_pack_shares_tenant_pack (`tenant_id`,`pack_id`),
 *                        FK fk_pack_shares_tenant CASCADE.
 *
 * NULL SEMANTICS (this migration)
 * --------------------------------
 *   report_packs NULL = PERSONAL pack (owner-only working draft, the report twin of
 *     ticket_reply_templates' analyst_id-private templates: visible to nobody else,
 *     not even via company scoping). Set = company pack. This mirrors the pack's
 *     existing ownership model (owner_id nullable + open share list) instead of
 *     forcing every private draft into a company.
 *   report_pack_shares NULL = transitional/unresolvable (parent pack deleted before
 *     migration). Shares are denormalised from the parent pack (same justification
 *     as migration 002: simple FKs only, direct tenant filtering, drift detectable
 *     by scripts/verify-tenant-consistency.php). The existing
 *     fk_rps_pack (pack_id CASCADE) is left untouched.
 *
 * WHY NULLABLE (not NOT NULL): packs predate tenancy and analysts keep personal
 * drafts; tightening would either fail on legacy personal packs or force them into
 * a company the owner never chose. Existing rows all land on the default tenant
 * (single-tenant installs: everything was company-visible), only NEW rows may use
 * NULL for personal packs.
 *
 * PHASE 4 NOTE (ticket_reply_templates audit): that table already carries
 * tenant_id with NULL = global default shared by every company (config meaning,
 * resolved via getTenantConfigRows() — see its schema comment and
 * docs/migrations/multitenant-null-semantics.md). This migration does NOT touch
 * it; the audit is documentation-only, recorded in the matrix doc.
 *
 * BACKUP: `_bak_t1_report_packs`, `_bak_t1_report_pack_shares` snapshots before any
 * ALTER (created once) + mysqldump:
 *   mysqldump -h <host> -u <user> -p <dbname> > freeitsm_pre_004_reportpacks.sql
 *
 * USAGE: preview default; --apply executes; --rollback reverts (drops added
 * columns + indexes, checksum-verified, keeps backups unless --drop-backups).
 * Idempotent: re-runs repair structure only.
 */

require_once __DIR__ . '/lib_tenant_migration.php';

tenant_migration_boot(
    [
        'version' => '20261005_004',
        'description' => 'tenant_id for report_packs and shares (NULL = personal)',
        'prereq_versions' => ['20261005_001', '20261005_002', '20261005_003'],
        'tables' => [
            [
                'table' => 'report_packs',
                'column' => 'tenant_id',
                'add_def' => 'INT NULL',
                'backfill' => 'default',
                'not_null' => false, // NULL = personal pack
                'indexes' => [
                    ['ix_report_packs_tenant', 'key', '(`tenant_id`,`id`)'],
                ],
                'fk_action' => 'SET NULL',
                'fk_name' => 'fk_report_packs_tenant',
            ],
            [
                'table' => 'report_pack_shares',
                'column' => 'tenant_id',
                'add_def' => 'INT NULL',
                'backfill' => ['parent' => ['report_packs', 'pack_id', 'tenant_id', 'default']],
                'not_null' => false,
                'indexes' => [
                    ['ix_pack_shares_tenant_pack', 'key', '(`tenant_id`,`pack_id`)'],
                ],
                'fk_action' => 'CASCADE',
                'fk_name' => 'fk_pack_shares_tenant',
            ],
        ],
    ],
    array_slice($argv, 1)
);
