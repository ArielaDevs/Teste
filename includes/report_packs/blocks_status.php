<?php
/**
 * Report Packs: Service Status blocks.
 *
 * Uptime comes from includes/services/service_uptime.php - the range-based methods
 * added for packs, which follow the same rules as the status board (segments from
 * the update log, overlaps unioned, only impact levels that count as downtime).
 * So a pack can never disagree with the board about how uptime is worked out;
 * only the period differs.
 *
 * Service Status has no company column: services and incidents are the install's.
 * The pack's company criterion therefore does not apply here, and nothing is
 * filtered by it.
 */

require_once __DIR__ . '/../services/service_uptime.php';

/** The services a block covers: the chosen ones, or every active one. */
function rpStatusServices(PDO $conn, array $ids): array
{
    $sql = "SELECT id, name, description FROM status_services WHERE is_active = 1";
    $params = [];
    if ($ids) {
        $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = $ids;
    }
    $s = $conn->prepare($sql . ' ORDER BY display_order, name');
    $s->execute($params);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function rpStatusUptime(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    $out = [];
    foreach (rpStatusServices($conn, $o['services']) as $svc) {
        $incidents = ServiceUptime::incidentsBetween($conn, (int)$svc['id'], $range['from_utc'], $range['to_utc']);
        $sum = ServiceUptime::summaryBetween($conn, (int)$svc['id'], $range['from_utc'], $range['to_utc'], $incidents);
        $row = [
            'name'        => $svc['name'],
            'description' => (string)$svc['description'],
            'uptime'      => rpPercent($sum['uptime_percent']),
            'downtime'    => $sum['downtime_seconds'] > 0 ? rpHumanDuration($sum['downtime_seconds']) : '',
            'strip'       => [],
            'incidents'   => [],
        ];
        if ($o['strip']) {
            foreach (ServiceUptime::dailyStripBetween($conn, (int)$svc['id'], $range['from_date'], $range['to_date'], $range['tz'], $incidents) as $d) {
                $row['strip'][] = ['s' => $d['state'], 'c' => $d['colour']];
            }
        }
        if ($o['incidents']) {
            foreach (array_reverse($incidents) as $i) {   // oldest first, as a report reads
                $row['incidents'][] = [
                    'started'  => rpFmtDateTime($i['started']),
                    'impact'   => $i['impact'],
                    'colour'   => $i['colour'],
                    'duration' => rpHumanDuration((int)$i['seconds']) . ($i['ongoing'] ? ' ' . t('reporting.packs.ongoing') : ''),
                    'title'    => $i['title'],
                ];
            }
        }
        $out[] = $row;
    }
    return ['kind' => 'uptime', 'services' => $out, 'days' => $range['days'],
            'from' => rpFmtDate($range['from_date']), 'to' => rpFmtDate($range['to_date']),
            'empty' => t('reporting.packs.empty.services')];
}

/** 100% / 99.13% / 95.7% - two decimals, trailing zeros dropped. */
function rpPercent(float $p): string
{
    return rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.') . '%';
}

function rpStatusUptimeTable(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    $rows = [];
    foreach (rpStatusServices($conn, $o['services']) as $svc) {
        $sum = ServiceUptime::summaryBetween($conn, (int)$svc['id'], $range['from_utc'], $range['to_utc']);
        $rows[] = [
            'service'   => $svc['name'],
            'uptime'    => rpPercent($sum['uptime_percent']),
            'downtime'  => $sum['downtime_seconds'] > 0 ? rpHumanDuration($sum['downtime_seconds']) : '-',
            'incidents' => (string)$sum['incident_count'],
        ];
    }
    return ['kind' => 'table', 'rows' => $rows, 'empty' => t('reporting.packs.empty.services'), 'columns' => [
        ['key' => 'service',   'label' => t('reporting.packs.col.service'),   'w' => 46],
        ['key' => 'uptime',    'label' => t('reporting.packs.col.uptime'),    'w' => 18, 'align' => 'right'],
        ['key' => 'downtime',  'label' => t('reporting.packs.col.downtime'),  'w' => 18, 'align' => 'right'],
        ['key' => 'incidents', 'label' => t('reporting.packs.col.incidents'), 'w' => 18, 'align' => 'right'],
    ]];
}

/**
 * Every incident UPDATE in the range, oldest first, with the services it named
 * and their worst impact - the incident log in Enrique's example. The update's
 * comment goes underneath the row, full width.
 */
function rpStatusIncidentLog(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    $internal = $o['internal'] ? '' : ' AND u.is_internal = 0';
    $s = $conn->prepare(
        "SELECT u.id, u.created_datetime, u.comment, si.title, st.name AS status, st.colour AS status_colour,
                a.full_name AS analyst
           FROM status_incident_updates u
           JOIN status_incidents si ON si.id = u.incident_id
           LEFT JOIN service_incident_statuses st ON st.id = u.status_id
           LEFT JOIN analysts a ON a.id = u.created_by_id
          WHERE u.created_datetime >= ? AND u.created_datetime < ?$internal
          ORDER BY u.created_datetime ASC, u.id ASC
          LIMIT 2000"
    );
    $s->execute([$range['from_utc'], $range['to_utc']]);
    $updates = $s->fetchAll(PDO::FETCH_ASSOC);

    // The services each update named, with their impact, in one query.
    $svcByUpdate = [];
    if ($updates) {
        $ids = array_map(fn($u) => (int)$u['id'], $updates);
        $q = $conn->prepare(
            "SELECT us.update_id, ss.name, il.name AS impact, il.colour, il.severity_order
               FROM status_incident_update_services us
               JOIN status_services ss ON ss.id = us.service_id
               LEFT JOIN service_impact_levels il ON il.id = us.impact_level_id
              WHERE us.update_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
              ORDER BY ss.display_order, ss.name"
        );
        $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $svcByUpdate[(int)$r['update_id']][] = $r;
        }
    }

    $rows = [];
    foreach ($updates as $u) {
        $svcs = $svcByUpdate[(int)$u['id']] ?? [];
        $worst = null;
        foreach ($svcs as $sv) {
            if ($worst === null || (int)$sv['severity_order'] < (int)$worst['severity_order']) $worst = $sv;
        }
        $row = [
            'date'     => rpFmtDateTime($u['created_datetime']),
            'services' => implode("\n", array_map(fn($sv) => $sv['name'], $svcs)),
            'impact'   => $worst ? ['pill' => (string)$worst['impact'], 'colour' => $worst['colour']] : '',
            'status'   => ['pill' => (string)$u['status'], 'colour' => $u['status_colour']],
            'title'    => $u['title'],
            'analyst'  => (string)$u['analyst'],
        ];
        if ($o['comments'] && trim((string)$u['comment']) !== '') {
            $row['_detail'] = rpPlainText((string)$u['comment']);
        }
        $rows[] = $row;
    }
    return ['kind' => 'table', 'rows' => $rows, 'empty' => t('reporting.packs.empty.incidents'), 'columns' => [
        ['key' => 'date',     'label' => t('reporting.packs.col.date'),     'w' => 15],
        ['key' => 'services', 'label' => t('reporting.packs.col.services'), 'w' => 19],
        ['key' => 'impact',   'label' => t('reporting.packs.col.impact'),   'w' => 13],
        ['key' => 'status',   'label' => t('reporting.packs.col.status'),   'w' => 13],
        ['key' => 'title',    'label' => t('reporting.packs.col.title'),    'w' => 25],
        ['key' => 'analyst',  'label' => t('reporting.packs.col.analyst'),  'w' => 15],
    ]];
}

/** Incidents overlapping the range: title, status pill, affected services as chips. */
function rpStatusIncidentSummary(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    $s = $conn->prepare(
        "SELECT si.id, si.title, si.created_datetime, si.resolved_datetime,
                st.name AS status, st.colour AS status_colour
           FROM status_incidents si
           LEFT JOIN service_incident_statuses st ON st.id = si.status_id
          WHERE si.created_datetime < ? AND (si.resolved_datetime IS NULL OR si.resolved_datetime >= ?)
          ORDER BY si.created_datetime ASC
          LIMIT 1000"
    );
    $s->execute([$range['to_utc'], $range['from_utc']]);
    $incidents = $s->fetchAll(PDO::FETCH_ASSOC);

    $chips = [];
    if ($incidents) {
        $ids = array_map(fn($r) => (int)$r['id'], $incidents);
        $q = $conn->prepare(
            "SELECT sis.incident_id, ss.name, il.colour
               FROM status_incident_services sis
               JOIN status_services ss ON ss.id = sis.service_id
               LEFT JOIN service_impact_levels il ON il.id = sis.impact_level_id
              WHERE sis.incident_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")
              ORDER BY ss.display_order, ss.name"
        );
        $q->execute($ids);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $chips[(int)$r['incident_id']][] = ['text' => $r['name'], 'colour' => $r['colour']];
        }
    }

    $rows = [];
    foreach ($incidents as $i) {
        $secs = max(0, (($i['resolved_datetime'] ? strtotime($i['resolved_datetime'] . ' UTC') : time()) - strtotime($i['created_datetime'] . ' UTC')));
        $rows[] = [
            'title'    => $i['title'],
            'status'   => ['pill' => (string)$i['status'], 'colour' => $i['status_colour']],
            'services' => ['chips' => $chips[(int)$i['id']] ?? []],
            'started'  => rpFmtDateTime($i['created_datetime']),
            'duration' => rpHumanDuration($secs) . ($i['resolved_datetime'] ? '' : ' ' . t('reporting.packs.ongoing')),
        ];
    }
    return ['kind' => 'table', 'rows' => $rows, 'empty' => t('reporting.packs.empty.incidents'), 'columns' => [
        ['key' => 'title',    'label' => t('reporting.packs.col.title'),    'w' => 30],
        ['key' => 'status',   'label' => t('reporting.packs.col.status'),   'w' => 13],
        ['key' => 'services', 'label' => t('reporting.packs.col.services'), 'w' => 31],
        ['key' => 'started',  'label' => t('reporting.packs.col.started'),  'w' => 15],
        ['key' => 'duration', 'label' => t('reporting.packs.col.duration'), 'w' => 11, 'align' => 'right'],
    ]];
}

function rpStatusKpis(PDO $conn, int $analystId, array $o, array $range, $tenant): array
{
    $services = rpStatusServices($conn, []);
    $sumPct = 0.0; $down = 0; $affected = 0;
    foreach ($services as $svc) {
        $sum = ServiceUptime::summaryBetween($conn, (int)$svc['id'], $range['from_utc'], $range['to_utc']);
        $sumPct += $sum['uptime_percent'];
        $down   += $sum['downtime_seconds'];
        if ($sum['downtime_seconds'] > 0) $affected++;
    }
    $s = $conn->prepare("SELECT COUNT(*) FROM status_incidents WHERE created_datetime < ? AND (resolved_datetime IS NULL OR resolved_datetime >= ?)");
    $s->execute([$range['to_utc'], $range['from_utc']]);
    $incidents = (int)$s->fetchColumn();

    return ['kind' => 'kpi', 'tiles' => [
        ['label' => t('reporting.packs.kpi.avg_uptime'), 'value' => $services ? rpPercent($sumPct / count($services)) : '-'],
        ['label' => t('reporting.packs.kpi.incidents'),  'value' => number_format($incidents)],
        ['label' => t('reporting.packs.kpi.affected'),   'value' => $affected . ' / ' . count($services)],
        ['label' => t('reporting.packs.kpi.downtime'),   'value' => $down > 0 ? rpHumanDuration($down) : '0'],
    ]];
}

/** Rich text from an incident comment, flattened for a table's detail line. */
function rpPlainText(string $html): string
{
    $txt = preg_replace('/<(br|\/p|\/div|\/li)\s*\/?>/i', "\n", $html);
    $txt = html_entity_decode(strip_tags($txt), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $txt)));
}
