<?php
/**
 * Report Packs: Assets and Intune blocks.
 *
 * Assets are scoped to the pack's company (assets.tenant_id). Intune devices have
 * no company - Intune is connected once for the whole install - so the Intune
 * blocks report the install's devices, as the Intune dashboard does.
 *
 * Both are snapshots of now, except where a block is about dates (warranty and
 * lease expiry, which use the pack's range).
 */

const RP_ASSET_DIMENSIONS = [
    'type'         => ["COALESCE(at.name, 'Not set')", 'LEFT JOIN asset_types at ON at.id = x.asset_type_id'],
    'status'       => ["COALESCE(ast.name, 'Not set')", 'LEFT JOIN asset_status_types ast ON ast.id = x.asset_status_id'],
    'os'           => ["COALESCE(NULLIF(x.operating_system, ''), 'Not recorded')", ''],
    'manufacturer' => ["COALESCE(NULLIF(x.manufacturer, ''), 'Not recorded')", ''],
    'model'        => ["COALESCE(NULLIF(x.model, ''), 'Not recorded')", ''],
    'location'     => ["COALESCE(loc.name, 'Not set')", 'LEFT JOIN asset_locations loc ON loc.id = x.location_id'],
];

const RP_INTUNE_DIMENSIONS = [
    'compliance'   => "COALESCE(NULLIF(compliance_state, ''), 'unknown')",
    'os'           => "COALESCE(NULLIF(operating_system, ''), 'Not recorded')",
    'os_version'   => "COALESCE(NULLIF(CONCAT(operating_system, ' ', os_version), ' '), 'Not recorded')",
    'owner'        => "COALESCE(NULLIF(managed_device_owner_type, ''), 'unknown')",
    'manufacturer' => "COALESCE(NULLIF(manufacturer, ''), 'Not recorded')",
    'encryption'   => "CASE WHEN is_encrypted = 1 THEN 'Encrypted' WHEN is_encrypted = 0 THEN 'Not encrypted' ELSE 'Unknown' END",
];

function rpAssetsBreakdown(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    [$label, $join] = RP_ASSET_DIMENSIONS[$o['by']];
    [$tSql, $tParams] = rpTenantClause($conn, $analystId, $tenant, 'x.tenant_id');
    $s = $conn->prepare("SELECT $label AS label, COUNT(*) AS value FROM assets x $join
                          WHERE 1=1$tSql GROUP BY label ORDER BY value DESC");
    $s->execute($tParams);
    $data = rpChartFromRows($s->fetchAll(PDO::FETCH_ASSOC), $o['limit'], $o['chart'], t('reporting.packs.series.assets'));
    $data['snapshot'] = true;
    return $data;
}

function rpAssetsKpis(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    [$tSql, $tParams] = rpTenantClause($conn, $analystId, $tenant, 'x.tenant_id');
    $s = $conn->prepare("SELECT COUNT(*) AS total,
                                SUM(x.first_seen >= ? AND x.first_seen < ?) AS added,
                                SUM(x.warranty_expiry >= ? AND x.warranty_expiry <= ?) AS warranty,
                                SUM(x.warranty_expiry IS NOT NULL AND x.warranty_expiry < ?) AS out_of_warranty
                           FROM assets x WHERE 1=1$tSql");
    $s->execute(array_merge([$range['from_utc'], $range['to_utc'], $range['from_date'], $range['to_date'], $range['to_date']], $tParams));
    $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['kind' => 'kpi', 'tiles' => [
        ['label' => t('reporting.packs.kpi.assets'),          'value' => number_format((int)($r['total'] ?? 0))],
        ['label' => t('reporting.packs.kpi.assets_added'),    'value' => number_format((int)($r['added'] ?? 0)), 'hint' => t('reporting.packs.kpi.in_period')],
        ['label' => t('reporting.packs.kpi.warranty_ending'), 'value' => number_format((int)($r['warranty'] ?? 0)), 'hint' => t('reporting.packs.kpi.in_period')],
        ['label' => t('reporting.packs.kpi.out_of_warranty'), 'value' => number_format((int)($r['out_of_warranty'] ?? 0)), 'hint' => t('reporting.packs.kpi.at_end')],
    ]];
}

/** Assets whose warranty and/or lease ends inside the range. */
function rpAssetsExpiring(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    [$tSql, $tParams] = rpTenantClause($conn, $analystId, $tenant, 'x.tenant_id');
    $conds = []; $params = [];
    if ($o['what'] !== 'lease')    { $conds[] = '(x.warranty_expiry >= ? AND x.warranty_expiry <= ?)'; array_push($params, $range['from_date'], $range['to_date']); }
    if ($o['what'] !== 'warranty') { $conds[] = '(x.lease_expiry >= ? AND x.lease_expiry <= ?)';       array_push($params, $range['from_date'], $range['to_date']); }
    $s = $conn->prepare("SELECT x.hostname, x.asset_tag, x.manufacturer, x.model, x.warranty_expiry, x.lease_expiry, x.logged_in_user
                           FROM assets x
                          WHERE (" . implode(' OR ', $conds) . ")$tSql
                          ORDER BY LEAST(COALESCE(x.warranty_expiry, '9999-12-31'), COALESCE(x.lease_expiry, '9999-12-31'))
                          LIMIT 2000");
    $s->execute(array_merge($params, $tParams));
    $rows = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'asset'    => trim($r['hostname'] . ($r['asset_tag'] ? ' (' . $r['asset_tag'] . ')' : '')),
            'model'    => trim($r['manufacturer'] . ' ' . $r['model']),
            'user'     => (string)$r['logged_in_user'],
            'warranty' => $r['warranty_expiry'] ? rpFmtDate(substr((string)$r['warranty_expiry'], 0, 10)) : '',
            'lease'    => $r['lease_expiry'] ? rpFmtDate(substr((string)$r['lease_expiry'], 0, 10)) : '',
        ];
    }
    $cols = [
        ['key' => 'asset', 'label' => t('reporting.packs.col.asset'), 'w' => 28],
        ['key' => 'model', 'label' => t('reporting.packs.col.model'), 'w' => 28],
        ['key' => 'user',  'label' => t('reporting.packs.col.user'),  'w' => 20],
    ];
    if ($o['what'] !== 'lease')    $cols[] = ['key' => 'warranty', 'label' => t('reporting.packs.col.warranty_ends'), 'w' => 14];
    if ($o['what'] !== 'warranty') $cols[] = ['key' => 'lease',    'label' => t('reporting.packs.col.lease_ends'),    'w' => 14];
    return ['kind' => 'table', 'rows' => $rows, 'columns' => $cols, 'empty' => t('reporting.packs.empty.expiring')];
}

function rpIntuneBreakdown(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    $label = RP_INTUNE_DIMENSIONS[$o['by']];
    $s = $conn->query("SELECT $label AS label, COUNT(*) AS value FROM intune_devices GROUP BY label ORDER BY value DESC");
    $data = rpChartFromRows($s->fetchAll(PDO::FETCH_ASSOC), $o['limit'], $o['chart'], t('reporting.packs.series.devices'));
    $data['snapshot'] = true;
    return $data;
}

function rpIntuneKpis(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    $r = $conn->query("SELECT COUNT(*) AS total,
                              SUM(compliance_state = 'compliant') AS compliant,
                              SUM(is_encrypted = 1) AS encrypted,
                              -- Never synced counts as stale, as on the Intune dashboard.
                              SUM(last_sync_datetime IS NULL OR last_sync_datetime < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)) AS stale
                         FROM intune_devices")->fetch(PDO::FETCH_ASSOC) ?: [];
    $total = (int)($r['total'] ?? 0);
    $pct = fn($n) => $total > 0 ? round(100 * (int)$n / $total) . '%' : '-';
    return ['kind' => 'kpi', 'snapshot' => true, 'tiles' => [
        ['label' => t('reporting.packs.kpi.devices'),   'value' => number_format($total)],
        ['label' => t('reporting.packs.kpi.compliant'), 'value' => $pct($r['compliant'] ?? 0)],
        ['label' => t('reporting.packs.kpi.encrypted'), 'value' => $pct($r['encrypted'] ?? 0)],
        ['label' => t('reporting.packs.kpi.stale'),     'value' => number_format((int)($r['stale'] ?? 0)), 'hint' => t('reporting.packs.kpi.stale_hint')],
    ]];
}
