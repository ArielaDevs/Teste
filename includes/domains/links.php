<?php
/**
 * Domains, joined to the rest of FreeITSM (3.0.0) - the ONE place that decides
 * who may see a link and who may make one, from either side.
 *
 * Four kinds of link, each a plain join table:
 *
 *   cmdb     domain_cmdb_objects        the configuration items that depend on it
 *   service  domain_status_services     the Service Status services that run on it
 *   ticket   ticket_domains             tickets about it
 *   article  domain_knowledge_articles  runbooks - knowledge articles pinned to it
 *
 * plus the contract, which is the domain's own `contract_id` column (one per
 * domain, set in its edit dialog or from the contract's page).
 *
 * 🔑 THE RULE, for every kind and both directions: a link is only shown, made or
 * removed between two records the analyst can already open - Domains plus the
 * other module, and the record itself (its company, or for an article its
 * knowledge permissions). A link the reader cannot see both ends of does not
 * exist for them: it is left out, never shown as "hidden".
 *
 * 🔑 And a link never crosses companies. A CI or a ticket must be in the
 * domain's own company - the rule ticket <-> CMDB links already follow. Status
 * services and knowledge articles are not company records (services are
 * install-wide; articles have their own audience model), so they are judged by
 * their own permissions only.
 */

require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../entity_links.php';
require_once __DIR__ . '/../service_context.php';

if (!defined('DOMAIN_LINKS_LOADED')) {
    define('DOMAIN_LINKS_LOADED', true);

    /** kind => table, the column naming the other record, and the module it belongs to. */
    function domainLinkKinds(): array
    {
        return [
            'cmdb'    => ['table' => 'domain_cmdb_objects',       'col' => 'cmdb_object_id', 'module' => 'cmdb'],
            'service' => ['table' => 'domain_status_services',    'col' => 'service_id',     'module' => 'service-status'],
            'ticket'  => ['table' => 'ticket_domains',            'col' => 'ticket_id',      'module' => 'tickets'],
            'article' => ['table' => 'domain_knowledge_articles', 'col' => 'article_id',     'module' => 'knowledge'],
        ];
    }

    /** Before Database Verification the tables are not there: no links, never an error. */
    function domainLinksReady(PDO $conn): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                foreach (domainLinkKinds() as $k) $conn->query("SELECT 1 FROM {$k['table']} LIMIT 0");
                $ready = true;
            } catch (Throwable $e) {
                $ready = false;
            }
        }
        return $ready;
    }

    function domainLinkKind(string $kind): array
    {
        $kinds = domainLinkKinds();
        if (!isset($kinds[$kind])) throw new ServiceError('validation', 'invalid_field', 'Unknown link kind.');
        return $kinds[$kind];
    }

    /** May this analyst use links of this kind at all? (Domains AND the other module.) */
    function domainLinkKindAllowed(PDO $conn, int $analystId, string $kind): bool
    {
        $k = domainLinkKinds()[$kind] ?? null;
        return $k !== null
            && analystCanAccessModule($conn, $analystId, 'domains')
            && analystCanAccessModule($conn, $analystId, $k['module']);
    }

    /** A record's company with NULL read as the Default company. */
    function domainLinkTenantOf(PDO $conn, string $table, int $id): ?int
    {
        $st = $conn->prepare("SELECT tenant_id FROM $table WHERE id = ?");
        $st->execute([$id]);
        $t = $st->fetchColumn();
        if ($t === false) return null;
        return $t === null ? (int)getDefaultTenantId($conn) : (int)$t;
    }

    /**
     * Is the OTHER end of a link one this analyst may see, and (for company
     * records) in the domain's company? $domainTenant null = do not compare.
     */
    function domainLinkTargetOk(PDO $conn, int $analystId, string $kind, int $targetId, ?int $domainTenant): bool
    {
        if ($targetId <= 0) return false;
        $multi = isMultiTenant($conn);
        switch ($kind) {
            case 'cmdb':
                if (!analystCanAccessCmdbObject($conn, $analystId, $targetId)) return false;
                return !$multi || $domainTenant === null || domainLinkTenantOf($conn, 'cmdb_objects', $targetId) === $domainTenant;
            case 'ticket':
                if (!analystCanAccessTicket($conn, $analystId, $targetId)) return false;
                $st = $conn->prepare("SELECT 1 FROM tickets WHERE id = ? AND deleted_datetime IS NULL");
                $st->execute([$targetId]);
                if (!$st->fetchColumn()) return false;
                return !$multi || $domainTenant === null || domainLinkTenantOf($conn, 'tickets', $targetId) === $domainTenant;
            case 'service':
                $st = $conn->prepare("SELECT 1 FROM status_services WHERE id = ?");
                $st->execute([$targetId]);
                return (bool)$st->fetchColumn();
            case 'article':
                require_once __DIR__ . '/../knowledge/visibility.php';
                return knowledgeCanRead($conn, KnowledgeViewer::forAnalyst($conn, $analystId), $targetId);
        }
        return false;
    }

    /** The domain's company (Default for NULL), or a not-found for a domain out of reach. */
    function domainLinkDomainTenant(PDO $conn, int $analystId, int $domainId): int
    {
        if (!analystCanAccessModule($conn, $analystId, 'domains') || !analystCanAccessDomain($conn, $analystId, $domainId)) {
            throw new ServiceError('not_found', 'not_found', 'Domain not found.');
        }
        $t = domainLinkTenantOf($conn, 'domains', $domainId);
        if ($t === null) throw new ServiceError('not_found', 'not_found', 'Domain not found.');
        return $t;
    }

    /** One linked record, shaped for display: id, label, sub-line, link, and kind-specific extras. */
    function domainLinkDescribe(PDO $conn, string $kind, array $ids): array
    {
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        switch ($kind) {
            case 'cmdb':
                $st = $conn->prepare("SELECT o.id, o.name, c.name AS class_name FROM cmdb_objects o LEFT JOIN cmdb_classes c ON c.id = o.class_id WHERE o.id IN ($in) ORDER BY o.name");
                $st->execute($ids);
                return array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['name'], 'sub' => $r['class_name'],
                    'url' => 'cmdb/object.php?id=' . (int)$r['id']], $st->fetchAll(PDO::FETCH_ASSOC));
            case 'service':
                $st = $conn->prepare("SELECT id, name, description, is_active FROM status_services WHERE id IN ($in) ORDER BY display_order, name");
                $st->execute($ids);
                return array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['name'], 'sub' => $r['description'],
                    'url' => 'service-status/', 'inactive' => (int)$r['is_active'] !== 1], $st->fetchAll(PDO::FETCH_ASSOC));
            case 'ticket':
                $st = $conn->prepare(
                    "SELECT t.id, t.ticket_number, t.subject, ts.name AS status, ts.colour AS status_colour, COALESCE(ts.is_closed, 0) AS is_closed
                       FROM tickets t LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
                      WHERE t.id IN ($in) ORDER BY COALESCE(ts.is_closed, 0), t.created_datetime DESC");
                $st->execute($ids);
                return array_map(fn($r) => ['id' => (int)$r['id'], 'label' => ($r['ticket_number'] ?: '#' . $r['id']), 'sub' => $r['subject'],
                    'status' => $r['status'], 'status_colour' => $r['status_colour'], 'closed' => (int)$r['is_closed'] === 1,
                    'url' => entityLink('ticket', (int)$r['id'])], $st->fetchAll(PDO::FETCH_ASSOC));
            case 'article':
                $st = $conn->prepare("SELECT id, title, modified_datetime FROM knowledge_articles WHERE id IN ($in) ORDER BY title");
                $st->execute($ids);
                return array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['title'], 'sub' => null,
                    'modified' => $r['modified_datetime'], 'url' => 'knowledge/?article=' . (int)$r['id']], $st->fetchAll(PDO::FETCH_ASSOC));
        }
        return [];
    }

    /**
     * Everything linked to one domain, per kind, for this analyst: a kind they
     * cannot use is left out entirely; within a kind, rows they cannot see are
     * dropped.
     */
    function domainLinks(PDO $conn, int $analystId, int $domainId): array
    {
        $tenant = domainLinkDomainTenant($conn, $analystId, $domainId);
        $out = [];
        if (!domainLinksReady($conn)) return $out;
        foreach (domainLinkKinds() as $kind => $k) {
            if (!domainLinkKindAllowed($conn, $analystId, $kind)) continue;
            $st = $conn->prepare("SELECT {$k['col']} FROM {$k['table']} WHERE domain_id = ?");
            $st->execute([$domainId]);
            $ids = array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)),
                fn($id) => domainLinkTargetOk($conn, $analystId, $kind, $id, $tenant)));
            $out[$kind] = domainLinkDescribe($conn, $kind, $ids);
        }
        return $out;
    }

    function domainLinkAdd(PDO $conn, int $analystId, int $domainId, string $kind, int $targetId): bool
    {
        $k = domainLinkKind($kind);
        if (!domainLinksReady($conn)) throw new ServiceError('validation', 'not_ready', 'Run System → Database Verification first.');
        $tenant = domainLinkDomainTenant($conn, $analystId, $domainId);
        if (!domainLinkKindAllowed($conn, $analystId, $kind) || !domainLinkTargetOk($conn, $analystId, $kind, $targetId, $tenant)) {
            throw new ServiceError('not_found', 'not_found', 'That record cannot be linked to this domain.');
        }
        $st = $conn->prepare("INSERT IGNORE INTO {$k['table']} (domain_id, {$k['col']}, created_by_analyst_id) VALUES (?, ?, ?)");
        $st->execute([$domainId, $targetId, $analystId ?: null]);
        return $st->rowCount() > 0;
    }

    function domainLinkRemove(PDO $conn, int $analystId, int $domainId, string $kind, int $targetId): bool
    {
        $k = domainLinkKind($kind);
        if (!domainLinksReady($conn)) return false;
        $tenant = domainLinkDomainTenant($conn, $analystId, $domainId);
        // Removing needs the same right as adding: both ends visible. A link you
        // cannot see is not yours to delete.
        if (!domainLinkKindAllowed($conn, $analystId, $kind) || !domainLinkTargetOk($conn, $analystId, $kind, $targetId, $tenant)) {
            throw new ServiceError('not_found', 'not_found', 'Link not found.');
        }
        $st = $conn->prepare("DELETE FROM {$k['table']} WHERE domain_id = ? AND {$k['col']} = ?");
        $st->execute([$domainId, $targetId]);
        return $st->rowCount() > 0;
    }

    /**
     * Records of one kind that could be linked to a domain: matching $q, in the
     * domain's company, visible to the analyst, not already linked. At most 20.
     */
    function domainLinkSearch(PDO $conn, int $analystId, int $domainId, string $kind, string $q): array
    {
        $k = domainLinkKind($kind);
        $tenant = domainLinkDomainTenant($conn, $analystId, $domainId);
        if (!domainLinkKindAllowed($conn, $analystId, $kind) || !domainLinksReady($conn)) return [];
        $q = trim($q);
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $taken = "NOT EXISTS (SELECT 1 FROM {$k['table']} l WHERE l.domain_id = ? AND l.{$k['col']} = x.id)";
        $multi = isMultiTenant($conn);
        $default = (int)getDefaultTenantId($conn);
        switch ($kind) {
            case 'cmdb':
                $sql = "SELECT x.id FROM cmdb_objects x WHERE x.name LIKE ? AND $taken"
                     . ($multi ? " AND COALESCE(x.tenant_id, $default) = ?" : '') . " ORDER BY x.name LIMIT 40";
                $args = $multi ? [$like, $domainId, $tenant] : [$like, $domainId];
                break;
            case 'ticket':
                $sql = "SELECT x.id FROM tickets x WHERE x.deleted_datetime IS NULL AND (x.subject LIKE ? OR x.ticket_number LIKE ?) AND $taken"
                     . ($multi ? " AND COALESCE(x.tenant_id, $default) = ?" : '') . " ORDER BY x.created_datetime DESC LIMIT 40";
                $args = $multi ? [$like, $like, $domainId, $tenant] : [$like, $like, $domainId];
                break;
            case 'service':
                $sql = "SELECT x.id FROM status_services x WHERE x.is_active = 1 AND x.name LIKE ? AND $taken ORDER BY x.display_order, x.name LIMIT 40";
                $args = [$like, $domainId];
                break;
            case 'article':
                require_once __DIR__ . '/../knowledge/visibility.php';
                [$vis, $vArgs] = knowledgeVisibilitySql($conn, KnowledgeViewer::forAnalyst($conn, $analystId), 'x');
                $sql = "SELECT x.id FROM knowledge_articles x WHERE x.title LIKE ? AND $taken $vis ORDER BY x.title LIMIT 40";
                $args = array_merge([$like, $domainId], $vArgs);
                break;
            default:
                return [];
        }
        $st = $conn->prepare($sql);
        $st->execute($args);
        // The SQL narrows by company; the per-record check is the same one
        // add() makes, so the list never offers something add() would refuse.
        $ids = array_slice(array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)),
            fn($id) => domainLinkTargetOk($conn, $analystId, $kind, $id, $tenant))), 0, 20);
        return domainLinkDescribe($conn, $kind, $ids);
    }

    /**
     * The other direction: the domains linked to one CI / service / ticket /
     * article that this analyst can see. Empty (not an error) when they cannot
     * open Domains or the other record, so a page can call it unconditionally.
     */
    function domainsLinkedTo(PDO $conn, int $analystId, string $kind, int $targetId): array
    {
        $k = domainLinkKinds()[$kind] ?? null;
        if ($k === null || !domainLinksReady($conn) || !domainLinkKindAllowed($conn, $analystId, $kind)) return [];
        if (!domainLinkTargetOk($conn, $analystId, $kind, $targetId, null)) return [];
        $st = $conn->prepare("SELECT domain_id FROM {$k['table']} WHERE {$k['col']} = ?");
        $st->execute([$targetId]);
        $ids = array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)),
            fn($id) => analystCanAccessDomain($conn, $analystId, $id)));
        return domainLinkDomainRows($conn, $ids);
    }

    /**
     * The picker on the OTHER side: domains that could be linked to this CI /
     * service / ticket / article - visible, matching $q, not already linked, and
     * for a company record in that record's company. At most 20.
     */
    function domainLinkPickDomains(PDO $conn, int $analystId, string $kind, int $targetId, string $q): array
    {
        $k = domainLinkKinds()[$kind] ?? null;
        if ($k === null || !domainLinksReady($conn) || !domainLinkKindAllowed($conn, $analystId, $kind)) return [];
        if (!domainLinkTargetOk($conn, $analystId, $kind, $targetId, null)) return [];
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($q)) . '%';
        $sql = "SELECT d.id FROM domains d WHERE (d.domain_name LIKE ? OR d.display_name LIKE ?)
                  AND NOT EXISTS (SELECT 1 FROM {$k['table']} l WHERE l.{$k['col']} = ? AND l.domain_id = d.id)";
        $args = [$like, $like, $targetId];
        if (isMultiTenant($conn) && ($kind === 'cmdb' || $kind === 'ticket')) {
            $default = (int)getDefaultTenantId($conn);
            $sql .= " AND COALESCE(d.tenant_id, $default) = ?";
            $args[] = domainLinkTenantOf($conn, $kind === 'cmdb' ? 'cmdb_objects' : 'tickets', $targetId);
        }
        $st = $conn->prepare($sql . " ORDER BY d.domain_name LIMIT 40");
        $st->execute($args);
        $ids = array_slice(array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)),
            fn($id) => analystCanAccessDomain($conn, $analystId, $id))), 0, 20);
        return domainLinkDomainRows($conn, $ids);
    }

    /** Domains by id, shaped for a list on another module's page. */
    function domainLinkDomainRows(PDO $conn, array $ids): array
    {
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $conn->prepare(
            "SELECT d.id, d.domain_name, d.display_name, d.expiry_date, d.ssl_expiry_date, d.security_grade,
                    d.cost, d.currency, d.billing_years, s.name AS status_name, s.colour AS status_colour
               FROM domains d LEFT JOIN domain_statuses s ON s.id = d.status_id
              WHERE d.id IN ($in) ORDER BY d.domain_name");
        $st->execute($ids);
        $today = new DateTimeImmutable('today');
        $days = function ($d) use ($today) {
            if (!$d) return null;
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', substr((string)$d, 0, 10));
            return $dt ? (int)$today->diff($dt)->format('%r%a') : null;
        };
        return array_map(fn($r) => [
            'id' => (int)$r['id'], 'name' => $r['display_name'] ?: $r['domain_name'], 'domain' => $r['domain_name'],
            'expiry_date' => $r['expiry_date'], 'days_left' => $days($r['expiry_date']),
            'ssl_expiry_date' => $r['ssl_expiry_date'], 'ssl_days_left' => $days($r['ssl_expiry_date']),
            'grade' => $r['security_grade'], 'status' => $r['status_name'], 'status_colour' => $r['status_colour'],
            'cost' => $r['cost'] !== null ? (float)$r['cost'] : null, 'currency' => $r['currency'],
            'billing_years' => (int)($r['billing_years'] ?: 1),
            'url' => entityLink('domain', (int)$r['id']),
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Domains under one contract (domains.contract_id) that this analyst can see. */
    function domainsForContract(PDO $conn, int $analystId, int $contractId): array
    {
        if ($contractId <= 0 || !analystCanAccessModule($conn, $analystId, 'domains')) return [];
        $st = $conn->prepare("SELECT id FROM domains WHERE contract_id = ?");
        $st->execute([$contractId]);
        $ids = array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)),
            fn($id) => analystCanAccessDomain($conn, $analystId, $id)));
        return domainLinkDomainRows($conn, $ids);
    }
}
