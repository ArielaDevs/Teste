<?php
/**
 * Service history and uptime, DERIVED from incidents (discussion #59).
 *
 * ── Why there is nothing to "log" ────────────────────────────────────────────
 *
 * The request was for a table recording every status change per service. There
 * is no such thing to record: `status_services` has no status column. A
 * service's status is computed live in get_dashboard.php as
 *
 *     the worst impact level among its OPEN incidents, else 'Operational'
 *
 * so a history table of previous → new would sit permanently empty.
 *
 * What already exists is better. Every period a service was not Operational IS
 * an incident that touched it:
 *
 *     start   status_incidents.created_datetime
 *     end     status_incidents.resolved_datetime  (or now, if still open)
 *     what    status_incident_services.impact_level_id
 *
 * So the history can be derived from data every install already has, which
 * means it works RETROSPECTIVELY on outages that have already happened rather
 * than shipping empty and becoming useful in six months.
 *
 * ── What this deliberately cannot see yet ────────────────────────────────────
 *
 * ⚠️ Changes DURING an incident. `replaceIncidentServices()` deletes and
 * re-inserts the service links, so moving a service from Major Outage to
 * Degraded mid-incident overwrites the earlier value — this will report the
 * whole incident at whatever the level ended up as. Incident status transitions
 * (Investigating → Identified → Monitoring) are not timestamped either.
 *
 * That is the phase-two work: one incident-updates log, written where incidents
 * are saved, which this function would then read in preference to the
 * incident's own start/end. Until then every figure here is "per incident"
 * rather than "per change", and the UI says so rather than implying a precision
 * it does not have.
 */

require_once __DIR__ . '/../tenancy.php';

class ServiceUptime
{
    /** Windows offered in the UI, in days. */
    const WINDOWS = [7, 30, 90, 365];

    /** Fallback when the setting is absent or nonsense. */
    const DEFAULT_WINDOW = 90;

    /**
     * The install's chosen default window, clamped to one we offer.
     */
    public static function defaultWindowDays(PDO $conn): int
    {
        try {
            $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'status_uptime_window_days'");
            $stmt->execute();
            $v = (int) $stmt->fetchColumn();
            if (in_array($v, self::WINDOWS, true)) {
                return $v;
            }
        } catch (Exception $e) {
            // fall through
        }
        return self::DEFAULT_WINDOW;
    }

    /** Should portal users see uptime figures? Defaults to NO — see the settings tab. */
    public static function shownInPortal(PDO $conn): bool
    {
        try {
            $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'status_uptime_show_portal'");
            $stmt->execute();
            return (string) $stmt->fetchColumn() === '1';
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Every incident that touched this service and overlaps the window.
     *
     * Returned newest first, each with its own start, end (null = ongoing) and
     * the impact level recorded against THIS service.
     *
     * @return array<int,array{incident_id:int,title:string,impact:string,colour:?string,
     *                         counts:bool,started:string,ended:?string,seconds:int,ongoing:bool}>
     */
    public static function incidentsFor(PDO $conn, int $serviceId, int $windowDays): array
    {
        // ⚠️ FIND THE INCIDENT, NOT THE CURRENT LINK.
        //
        // This used to start FROM status_incident_services, which holds only the
        // link as it stands now. Phase 2 made that wrong: a service that has been
        // RESTORED is recorded by dropping it from the latest snapshot, so its
        // current link is gone — and the incident that took it down for nine
        // hours vanished from its history entirely. A four-service outage where
        // everything was brought back reported all four at 100%.
        //
        // The incident is therefore matched if it touched this service in the
        // current links OR anywhere in its update log.
        $hasLog = self::updateLogAvailable($conn);
        $logClause = $hasLog
            ? "OR EXISTS (SELECT 1 FROM status_incident_update_services y
                            JOIN status_incident_updates u ON u.id = y.update_id
                           WHERE u.incident_id = si.id AND y.service_id = ?)"
            : '';

        $sql = "SELECT si.id AS incident_id, si.title,
                       si.created_datetime  AS started,
                       si.resolved_datetime AS ended,
                       il.name   AS impact,
                       il.colour AS colour,
                       il.counts_as_downtime AS counts,
                       sst.is_resolved AS status_resolved
                  FROM status_incidents si
                  LEFT JOIN status_incident_services sis
                         ON sis.incident_id = si.id AND sis.service_id = ?
                  LEFT JOIN service_impact_levels il  ON il.id = sis.impact_level_id
                  LEFT JOIN service_incident_statuses sst ON sst.id = si.status_id
                 WHERE (sis.id IS NOT NULL $logClause)
                   -- Overlaps the window: it either has not ended, or it ended
                   -- inside it. An incident that STARTED before the window but
                   -- is still running must be included, which a naive
                   -- `created_datetime >= cutoff` would drop — and that is
                   -- exactly the long outage nobody wants missing.
                   AND (si.resolved_datetime IS NULL
                        OR si.resolved_datetime >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY))
                 ORDER BY si.created_datetime DESC";

        $params = $hasLog ? [$serviceId, $serviceId, $windowDays] : [$serviceId, $windowDays];
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Phase 2: where an incident has an update log, its segments replace the
        // single row derived from the incident's own start and end.
        $out = [];
        foreach ($rows as $r) {
            $segments = self::segmentsFor($conn, (int)$r['incident_id'], $serviceId,
                                          (string)$r['started'], $r['ended']);
            if ($segments !== null) {
                foreach ($segments as $seg) {
                    $out[] = $seg + ['title' => (string)$r['title'], 'incident_id' => (int)$r['incident_id']];
                }
                continue;
            }
            // An incident is "ongoing" when it has no resolved stamp. The status
            // being a resolved one without a stamp is a data oddity rather than a
            // state; treat the stamp as authoritative and let the UI show the row.
            $ongoing = ($r['ended'] === null);
            $out[] = [
                'incident_id' => (int) $r['incident_id'],
                'title'       => (string) $r['title'],
                'impact'      => (string) ($r['impact'] ?? 'Unknown'),
                'colour'      => $r['colour'] ?? null,
                'counts'      => (int) ($r['counts'] ?? 1) === 1,
                'started'     => (string) $r['started'],
                'ended'       => $r['ended'],
                'ongoing'     => $ongoing,
                'seconds'     => self::spanSeconds((string) $r['started'], $r['ended']),
            ];
        }
        return $out;
    }

    /**
     * Do the phase-2 log tables exist? Cached — every service on the board asks.
     *
     * An install that has not run Database Verification since phase 2 shipped
     * still works: the log clause is left out of the query and every incident
     * falls back to its own start and end, which is exactly phase-1 behaviour.
     */
    private static function updateLogAvailable(PDO $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $conn->query("SELECT 1 FROM status_incident_update_services LIMIT 1");
            return $ok = true;
        } catch (Exception $e) {
            return $ok = false;
        }
    }

    /**
     * One row per period this service spent at a given impact DURING an incident
     * (discussion #59, phase 2), or null when the incident predates the log.
     *
     * Each update is a full snapshot, so a service's interval runs from the
     * update that named it to the next update — whether or not that next update
     * mentions the service. A service dropped from the snapshot, or moved to a
     * level that does not count as downtime, simply stops being impacted there.
     *
     * ⚠️ Returning null rather than an empty array is the whole compatibility
     * story. No updates means "this incident was created before the log existed",
     * and the caller falls back to the incident's own start and end. An empty
     * array would mean "the log says this service was never impacted", which is a
     * completely different claim and would erase every historical outage.
     *
     * @return array|null
     */
    private static function segmentsFor(PDO $conn, int $incidentId, int $serviceId, string $incidentStart, ?string $incidentEnd): ?array
    {
        try {
            $stmt = $conn->prepare(
                "SELECT u.id, u.created_datetime,
                        sus.impact_level_id,
                        il.name   AS impact,
                        il.colour AS colour,
                        il.counts_as_downtime AS counts
                   FROM status_incident_updates u
                   LEFT JOIN status_incident_update_services sus
                          ON sus.update_id = u.id AND sus.service_id = ?
                   LEFT JOIN service_impact_levels il ON il.id = sus.impact_level_id
                  WHERE u.incident_id = ?
                  ORDER BY u.created_datetime ASC, u.id ASC"
            );
            $stmt->execute([$serviceId, $incidentId]);
            $updates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return null;   // tables not migrated yet — fall back
        }

        if (!$updates) {
            return null;   // pre-log incident — fall back
        }

        $incidentEndTs = $incidentEnd !== null ? strtotime($incidentEnd . ' UTC') : time();
        $out = [];

        foreach ($updates as $idx => $u) {
            // No row for this service in that snapshot => not impacted from here on.
            if ($u['impact_level_id'] === null) {
                continue;
            }
            $from = strtotime((string)$u['created_datetime'] . ' UTC');
            // The next update ends this interval, whatever it says — including
            // saying nothing about this service, which is how "restored" is
            // recorded when a service is simply dropped from the snapshot.
            $to = isset($updates[$idx + 1])
                ? strtotime((string)$updates[$idx + 1]['created_datetime'] . ' UTC')
                : $incidentEndTs;
            if ($from === false || $to === false) {
                continue;
            }
            // The incident's own end still caps the last interval: resolving does
            // not necessarily write a further update.
            $to = min($to, $incidentEndTs);
            if ($to <= $from) {
                continue;   // two saves in the same second, or a resolved-then-edited incident
            }

            $out[] = [
                'impact'  => (string)($u['impact'] ?? 'Unknown'),
                'colour'  => $u['colour'] ?? null,
                'counts'  => (int)($u['counts'] ?? 1) === 1,
                'started' => gmdate('Y-m-d H:i:s', $from),
                'ended'   => ($incidentEnd === null && !isset($updates[$idx + 1])) ? null : gmdate('Y-m-d H:i:s', $to),
                'ongoing' => ($incidentEnd === null && !isset($updates[$idx + 1])),
                'seconds' => $to - $from,
            ];
        }

        // ⚠️ Merge consecutive segments at the SAME level.
        //
        // Every save writes a snapshot, so a service that was simply never
        // touched by five updates produces five adjacent identical rows. The
        // durations were right but it read as five separate outages — the
        // three-day Email outage listed as 9h + 18h + 8h + 14h + 5h, and a
        // reader has to add them up to learn anything. The underlying data keeps
        // every update; this is the reading of it.
        //
        // Only ADJACENT ones merge, so a genuine Major → Degraded → Major stays
        // three rows, which is the distinction the whole feature exists to make.
        $merged = [];
        foreach ($out as $seg) {
            $last = $merged ? count($merged) - 1 : -1;
            if ($last >= 0
                && $merged[$last]['impact'] === $seg['impact']
                && $merged[$last]['ended'] === $seg['started']) {
                $merged[$last]['ended']   = $seg['ended'];
                $merged[$last]['ongoing'] = $seg['ongoing'];
                $merged[$last]['seconds'] += $seg['seconds'];
                continue;
            }
            $merged[] = $seg;
        }

        // Every update dropped the service (raised then immediately removed): the
        // log is present and says "never impacted", which is a real answer.
        return $merged;
    }

    /**
     * Downtime seconds in the window, and the uptime percentage.
     *
     * ⚠️ OVERLAPS ARE MERGED. Two incidents both taking the same service down at
     * the same time is one outage, not two — summing their durations would
     * happily report more downtime than there were seconds in the window, and
     * an uptime below zero. The intervals are clipped to the window, sorted, and
     * unioned before anything is added up.
     *
     * Only levels with counts_as_downtime = 1 are included, so planned
     * maintenance does not make a well-run service look worse than a neglected
     * one. That is a per-level setting — see Status → Settings → Impact levels.
     *
     * @return array{window_days:int,window_seconds:int,downtime_seconds:int,
     *                uptime_percent:float,incident_count:int,counted_count:int}
     */
    public static function summaryFor(PDO $conn, int $serviceId, int $windowDays): array
    {
        $incidents     = self::incidentsFor($conn, $serviceId, $windowDays);
        $windowSeconds = $windowDays * 86400;
        $now           = time();
        $windowStart   = $now - $windowSeconds;

        $spans = [];
        $counted = 0;
        foreach ($incidents as $i) {
            if (!$i['counts']) {
                continue;   // maintenance and the like
            }
            $counted++;
            $s = strtotime($i['started'] . ' UTC');
            $e = $i['ended'] !== null ? strtotime($i['ended'] . ' UTC') : $now;
            if ($s === false || $e === false || $e <= $s) {
                continue;
            }
            // Clip to the window — an outage that began before it only counts
            // for the part that falls inside.
            $s = max($s, $windowStart);
            $e = min($e, $now);
            if ($e > $s) {
                $spans[] = [$s, $e];
            }
        }

        $downtime = self::unionSeconds($spans);

        // Guard the division and the ceiling: a clipped span can never exceed the
        // window, but a clock change or a future-dated incident should not be able
        // to produce 103% or -4%.
        $downtime = max(0, min($downtime, $windowSeconds));
        $uptime   = $windowSeconds > 0
            ? round((($windowSeconds - $downtime) / $windowSeconds) * 100, 3)
            : 100.0;

        return [
            'window_days'      => $windowDays,
            'window_seconds'   => $windowSeconds,
            'downtime_seconds' => $downtime,
            'uptime_percent'   => $uptime,
            'incident_count'   => count($incidents),
            'counted_count'    => $counted,
        ];
    }

    /**
     * One entry per day in the window, newest LAST, for the bar strip.
     *
     * Each day carries the worst counting impact that touched it, so a strip of
     * 90 bars reads as "when was this service unhappy" at a glance. Days with no
     * incident are 'ok'; days touched only by a non-counting level are 'info',
     * which is how planned maintenance stays visible without being punitive.
     *
     * @return array<int,array{date:string,state:string,impact:?string,colour:?string,seconds:int}>
     */
    public static function dailyStrip(PDO $conn, int $serviceId, int $windowDays): array
    {
        $incidents = self::incidentsFor($conn, $serviceId, $windowDays);
        $now       = time();
        $days      = [];

        // Severity ordering so a day showing two impacts shows the worse one.
        $sevStmt = $conn->query("SELECT name, severity_order FROM service_impact_levels");
        $severity = [];
        foreach ($sevStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $severity[$r['name']] = (int) $r['severity_order'];
        }

        for ($d = $windowDays - 1; $d >= 0; $d--) {
            $dayStart = strtotime('today UTC') - ($d * 86400);
            $dayEnd   = $dayStart + 86400;
            $key      = gmdate('Y-m-d', $dayStart);

            $worst = null; $worstSev = PHP_INT_MAX; $secs = 0; $info = null;
            foreach ($incidents as $i) {
                $s = strtotime($i['started'] . ' UTC');
                $e = $i['ended'] !== null ? strtotime($i['ended'] . ' UTC') : $now;
                if ($s === false || $e === false || $e <= $dayStart || $s >= $dayEnd) {
                    continue;
                }
                // ⚠️ Keep the non-counting incident, do not just remember THAT there
                // was one. This was a bare `$sawInfo = true`, and the strip then had
                // no name to show — so the tooltip fell back to the word
                // "maintenance" for every excluded level. An incident logged at
                // Operational reported itself as maintenance, which is simply untrue.
                // Any level with counts_as_downtime = 0 lands here, and there are
                // three shipped ones plus anything an administrator adds.
                if (!$i['counts']) {
                    if ($info === null) { $info = $i; }
                    continue;
                }
                $secs += min($e, $dayEnd) - max($s, $dayStart);
                $sev = $severity[$i['impact']] ?? 99;
                if ($sev < $worstSev) { $worstSev = $sev; $worst = $i; }
            }

            $shown = $worst ?? $info;
            $days[] = [
                'date'    => $key,
                'state'   => $worst !== null ? 'down' : ($info !== null ? 'info' : 'ok'),
                'impact'  => $shown['impact'] ?? null,
                'colour'  => $shown['colour'] ?? null,
                'seconds' => $secs,
            ];
        }
        return $days;
    }

    // ── Fixed date ranges (Report Packs) ──────────────────────────────────────
    //
    // The methods above answer "the last N days, ending now", which is what the
    // status board shows. A report answers "1 to 30 September", which may have
    // ended weeks ago - so it needs both ends. These are deliberately separate
    // methods rather than an extra parameter on the ones above, so the board's
    // own figures cannot move because a report needed something different. The
    // rules are the same: segments from the update log where there is one,
    // overlaps unioned, only counts_as_downtime levels counted.

    /**
     * Every incident (or update-log segment) touching this service that overlaps
     * [$fromUtc, $toUtc). Same shape as incidentsFor(). An incident still open
     * runs to now, or to the end of the range if that is earlier.
     */
    public static function incidentsBetween(PDO $conn, int $serviceId, string $fromUtc, string $toUtc): array
    {
        $hasLog = self::updateLogAvailable($conn);
        $logClause = $hasLog
            ? "OR EXISTS (SELECT 1 FROM status_incident_update_services y
                            JOIN status_incident_updates u ON u.id = y.update_id
                           WHERE u.incident_id = si.id AND y.service_id = ?)"
            : '';

        $sql = "SELECT si.id AS incident_id, si.title,
                       si.created_datetime  AS started,
                       si.resolved_datetime AS ended,
                       il.name   AS impact,
                       il.colour AS colour,
                       il.counts_as_downtime AS counts
                  FROM status_incidents si
                  LEFT JOIN status_incident_services sis
                         ON sis.incident_id = si.id AND sis.service_id = ?
                  LEFT JOIN service_impact_levels il ON il.id = sis.impact_level_id
                 WHERE (sis.id IS NOT NULL $logClause)
                   AND si.created_datetime < ?
                   AND (si.resolved_datetime IS NULL OR si.resolved_datetime >= ?)
                 ORDER BY si.created_datetime DESC";
        $params = $hasLog ? [$serviceId, $serviceId, $toUtc, $fromUtc] : [$serviceId, $toUtc, $fromUtc];
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        $from = strtotime($fromUtc . ' UTC');
        $to   = strtotime($toUtc . ' UTC');
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $segments = self::segmentsFor($conn, (int)$r['incident_id'], $serviceId, (string)$r['started'], $r['ended']);
            if ($segments === null) {
                $segments = [[
                    'impact'  => (string)($r['impact'] ?? 'Unknown'),
                    'colour'  => $r['colour'] ?? null,
                    'counts'  => (int)($r['counts'] ?? 1) === 1,
                    'started' => (string)$r['started'],
                    'ended'   => $r['ended'],
                    'ongoing' => $r['ended'] === null,
                    'seconds' => self::spanSeconds((string)$r['started'], $r['ended']),
                ]];
            }
            foreach ($segments as $seg) {
                // A segment of an overlapping incident can itself fall outside.
                $s = strtotime($seg['started'] . ' UTC');
                $e = $seg['ended'] !== null ? strtotime($seg['ended'] . ' UTC') : time();
                if ($s === false || $e === false || $e <= $from || $s >= $to) continue;
                $out[] = $seg + ['title' => (string)$r['title'], 'incident_id' => (int)$r['incident_id']];
            }
        }
        return $out;
    }

    /**
     * Uptime over [$fromUtc, $toUtc). Downtime is clipped to the range AND to now,
     * so a range running into the future is measured only up to the present.
     *
     * @return array{range_seconds:int,downtime_seconds:int,uptime_percent:float,incident_count:int,counted_count:int}
     */
    public static function summaryBetween(PDO $conn, int $serviceId, string $fromUtc, string $toUtc, ?array $incidents = null): array
    {
        $incidents = $incidents ?? self::incidentsBetween($conn, $serviceId, $fromUtc, $toUtc);
        $from = strtotime($fromUtc . ' UTC');
        $to   = min(strtotime($toUtc . ' UTC'), time());
        $rangeSeconds = max(0, $to - $from);

        $spans = []; $counted = 0; $ids = [];
        foreach ($incidents as $i) {
            $ids[$i['incident_id']] = true;
            if (!$i['counts']) continue;
            $counted++;
            $s = strtotime($i['started'] . ' UTC');
            $e = $i['ended'] !== null ? strtotime($i['ended'] . ' UTC') : time();
            if ($s === false || $e === false) continue;
            $s = max($s, $from); $e = min($e, $to);
            if ($e > $s) $spans[] = [$s, $e];
        }
        $downtime = max(0, min(self::unionSeconds($spans), $rangeSeconds));
        return [
            'range_seconds'    => $rangeSeconds,
            'downtime_seconds' => $downtime,
            'uptime_percent'   => $rangeSeconds > 0 ? round((($rangeSeconds - $downtime) / $rangeSeconds) * 100, 3) : 100.0,
            'incident_count'   => count($ids),
            'counted_count'    => $counted,
        ];
    }

    /**
     * One entry per LOCAL day from $fromDate to $toDate inclusive (Y-m-d in $tz),
     * oldest first - the bar strip for a report. Same states as dailyStrip().
     */
    public static function dailyStripBetween(PDO $conn, int $serviceId, string $fromDate, string $toDate, string $tz, ?array $incidents = null): array
    {
        try { $zone = new DateTimeZone($tz); } catch (Exception $e) { $zone = new DateTimeZone('UTC'); }
        $day  = new DateTimeImmutable($fromDate, $zone);
        $last = new DateTimeImmutable($toDate, $zone);
        if ($incidents === null) {
            $utc = new DateTimeZone('UTC');
            $incidents = self::incidentsBetween($conn, $serviceId,
                $day->setTimezone($utc)->format('Y-m-d H:i:s'),
                $last->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'));
        }

        $severity = [];
        foreach ($conn->query("SELECT name, severity_order FROM service_impact_levels")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $severity[$r['name']] = (int)$r['severity_order'];
        }

        $now = time(); $days = []; $guard = 0;
        while ($day <= $last && $guard++ < 3700) {
            $dayStart = $day->getTimestamp();
            $dayEnd   = $day->modify('+1 day')->getTimestamp();
            $worst = null; $worstSev = PHP_INT_MAX; $secs = 0; $info = null;
            foreach ($incidents as $i) {
                $s = strtotime($i['started'] . ' UTC');
                $e = $i['ended'] !== null ? strtotime($i['ended'] . ' UTC') : $now;
                if ($s === false || $e === false || $e <= $dayStart || $s >= $dayEnd) continue;
                if (!$i['counts']) { if ($info === null) $info = $i; continue; }
                $secs += min($e, $dayEnd) - max($s, $dayStart);
                $sev = $severity[$i['impact']] ?? 99;
                if ($sev < $worstSev) { $worstSev = $sev; $worst = $i; }
            }
            $shown = $worst ?? $info;
            $days[] = [
                'date'    => $day->format('Y-m-d'),
                'state'   => $dayStart > $now ? 'future' : ($worst !== null ? 'down' : ($info !== null ? 'info' : 'ok')),
                'impact'  => $shown['impact'] ?? null,
                'colour'  => $shown['colour'] ?? null,
                'seconds' => $secs,
            ];
            $day = $day->modify('+1 day');
        }
        return $days;
    }

    /**
     * Merge overlapping [start, end] pairs and total the result.
     *
     * The whole reason summaryFor() cannot simply SUM durations.
     */
    private static function unionSeconds(array $spans): int
    {
        if (!$spans) {
            return 0;
        }
        usort($spans, fn($a, $b) => $a[0] <=> $b[0]);
        $total = 0;
        [$curStart, $curEnd] = $spans[0];
        for ($i = 1; $i < count($spans); $i++) {
            [$s, $e] = $spans[$i];
            if ($s <= $curEnd) {              // overlapping or touching → extend
                $curEnd = max($curEnd, $e);
            } else {
                $total += $curEnd - $curStart;
                [$curStart, $curEnd] = [$s, $e];
            }
        }
        return $total + ($curEnd - $curStart);
    }

    /** Seconds between two UTC stamps; an open incident runs to now. */
    private static function spanSeconds(string $started, ?string $ended): int
    {
        $s = strtotime($started . ' UTC');
        $e = $ended !== null ? strtotime($ended . ' UTC') : time();
        if ($s === false || $e === false || $e <= $s) {
            return 0;
        }
        return $e - $s;
    }

    /** "2h 15m" / "45m" / "3d 4h" — the shape the request asked for. */
    public static function humanDuration(int $seconds): string
    {
        if ($seconds < 60)  return $seconds . 's';
        $m = intdiv($seconds, 60);
        if ($m < 60)        return $m . 'm';
        $h = intdiv($m, 60); $m = $m % 60;
        if ($h < 24)        return $m ? "{$h}h {$m}m" : "{$h}h";
        $d = intdiv($h, 24); $h = $h % 24;
        return $h ? "{$d}d {$h}h" : "{$d}d";
    }
}
