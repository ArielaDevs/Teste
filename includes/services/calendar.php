<?php
/**
 * CalendarService — the single home for the calendar module's write rules
 * (events, shared by the UI + REST API; plus the UI-only categories).
 *
 * Shared by the UI endpoints (api/calendar/*.php) and the REST API
 * (api/v1/resources/calendar.php). Each caller passes an ActorContext and
 * canonical input; this layer validates + writes and returns the affected id
 * (plus a created flag) or throws ServiceError. It never emits HTTP.
 *
 * ⚠️ Datetime semantics — DELIBERATELY naive server-local (unlike the rest of
 * the app, which stores UTC). The calendar stores exactly what the browser
 * sends, and the ICS feed reads it in the server timezone. So this service
 * does NOT stamp/convert UTC — it validates "YYYY-MM-DD HH:MM:SS" naive values
 * and REJECTS Z/offset designators, keeping the UI and API on one model.
 *
 * 🛡️ Generated events (source = 'asset_warranty' etc.) belong to their sync;
 * updates/deletes on them are refused (409) and the write is further guarded
 * with `WHERE source IS NULL`.
 *
 * Canonical event input keys are the API's: title, description, category_id,
 * start_at, end_at, all_day, location, contract_id. The UI adapters map their
 * start_datetime/end_datetime to start_at/end_at.
 */

require_once __DIR__ . '/../service_context.php';
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

class CalendarService
{
    // ======================================================================
    //  Events (UI + API)
    // ======================================================================

    /** Create (no id) or update (id present) an event. Returns ['id','created']. */
    public static function saveEvent(PDO $conn, ActorContext $ctx, array $in): array
    {
        if (!empty($in['id'])) {
            $id      = (int)$in['id'];
            $current = self::loadEventRow($conn, $id);       // 404 if gone
            self::guardGenerated($current);                  // 409 on generated rows
            if (!array_diff_key($in, ['id' => true])) {
                throw new ServiceError('validation', 'missing_field', 'No fields to update.');
            }
            $f = self::collectEventFields($conn, $in, $current, $ctx);
            if ($f['title'] === '') {
                throw new ServiceError('validation', 'invalid_field', "'title' cannot be empty.");
            }
            if ($f['start'] === null) {
                throw new ServiceError('validation', 'invalid_field', "'start_at' cannot be cleared.");
            }
            if (!array_key_exists('all_day', $in)) {
                $f['all_day'] = (int)$current['all_day'];
            }
            // WHERE source IS NULL: belt-and-braces against a sync racing us.
            $conn->prepare(
                "UPDATE calendar_events SET title=?, description=?, category_id=?, start_datetime=?,
                    end_datetime=?, all_day=?, location=?, contract_id=?, updated_at=UTC_TIMESTAMP()
                 WHERE id=? AND source IS NULL"
            )->execute([
                $f['title'], $f['description'], $f['category_id'], $f['start'],
                $f['end'] ?? $f['start'], $f['all_day'], $f['location'], $f['contract_id'],
                $id,
            ]);
            WorkflowEngine::dispatch('calendar_event.updated', ['calendar_event' => ['id' => $id, 'title' => $f['title'], 'category_id' => $f['category_id']]]);
            return ['id' => $id, 'created' => false];
        }

        $f = self::collectEventFields($conn, $in, [], $ctx);
        if ($f['title'] === '') {
            throw new ServiceError('validation', 'missing_field', "'title' is required.");
        }
        if ($f['start'] === null) {
            throw new ServiceError('validation', 'missing_field', "'start_at' is required.");
        }
        // Consumers can never set source — new events are always manual.
        $conn->prepare(
            "INSERT INTO calendar_events (title, description, category_id, start_datetime,
                end_datetime, all_day, location, contract_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $f['title'], $f['description'], $f['category_id'], $f['start'],
            $f['end'] ?? $f['start'], $f['all_day'], $f['location'], $f['contract_id'],
            $ctx->actorId,
        ]);
        $newId = (int)$conn->lastInsertId();
        WorkflowEngine::dispatch('calendar_event.created', ['calendar_event' => ['id' => $newId, 'title' => $f['title'], 'category_id' => $f['category_id']]]);
        return ['id' => $newId, 'created' => true];
    }

    /** Delete an event. 404 if missing, 409 if generated. Returns the id. */
    public static function deleteEvent(PDO $conn, ActorContext $ctx, int $id): int
    {
        $current = self::loadEventRow($conn, $id);
        self::guardGenerated($current);
        $conn->prepare("DELETE FROM calendar_events WHERE id = ? AND source IS NULL")->execute([$id]);
        WorkflowEngine::dispatch('calendar_event.deleted', ['calendar_event' => ['id' => $id, 'title' => $current['title'] ?? null, 'category_id' => isset($current['category_id']) ? (int)$current['category_id'] : null]]);
        return $id;
    }

    // ======================================================================
    //  Categories (UI-only — no API twin; behaviour preserved verbatim)
    // ======================================================================

    /** Create (no id) or update (id present) a category. Returns ['id','created']. */
    public static function saveCategory(PDO $conn, ActorContext $ctx, array $in): array
    {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') {
            throw new ServiceError('validation', 'missing_field', 'Category name is required');
        }
        $color = trim((string)($in['color'] ?? '#ef6c00'));
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            throw new ServiceError('validation', 'invalid_field', 'Invalid color format');
        }
        $description = trim((string)($in['description'] ?? ''));
        $isActive = isset($in['is_active']) ? (int)(bool)$in['is_active'] : 1;

        if (!empty($in['id'])) {
            $id = (int)$in['id'];
            $conn->prepare(
                "UPDATE calendar_categories SET name = ?, color = ?, description = ?, is_active = ?, updated_at = UTC_TIMESTAMP()
                 WHERE id = ?"
            )->execute([$name, $color, $description, $isActive, $id]);
            WorkflowEngine::emitCrud('calendar_category', 'updated', $id, $name);
            return ['id' => $id, 'created' => false];
        }
        $conn->prepare(
            "INSERT INTO calendar_categories (name, color, description, is_active) VALUES (?, ?, ?, ?)"
        )->execute([$name, $color, $description, $isActive]);
        $newId = (int)$conn->lastInsertId();
        WorkflowEngine::emitCrud('calendar_category', 'created', $newId, $name);
        return ['id' => $newId, 'created' => true];
    }

    /** Delete a category — refused (409) while events still use it. Returns the id. */
    public static function deleteCategory(PDO $conn, ActorContext $ctx, int $id): int
    {
        if ($id <= 0) {
            throw new ServiceError('validation', 'missing_field', 'Category ID is required');
        }
        $chk = $conn->prepare("SELECT COUNT(*) FROM calendar_events WHERE category_id = ?");
        $chk->execute([$id]);
        $count = (int)$chk->fetchColumn();
        if ($count > 0) {
            throw new ServiceError('conflict', 'conflict', "Cannot delete category: {$count} event(s) are using it. Please reassign or delete those events first.");
        }
        $name = $conn->query("SELECT name FROM calendar_categories WHERE id = " . (int)$id)->fetchColumn() ?: null;
        $del = $conn->prepare("DELETE FROM calendar_categories WHERE id = ?");
        $del->execute([$id]);
        if ($del->rowCount() === 0) {
            throw new ServiceError('not_found', 'not_found', 'Category not found');
        }
        WorkflowEngine::emitCrud('calendar_category', 'deleted', $id, $name);
        return $id;
    }

    // ======================================================================
    //  Internals
    // ======================================================================

    private static function loadEventRow(PDO $conn, int $id): array
    {
        $stmt = $conn->prepare("SELECT * FROM calendar_events WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Event not found.');
        }
        return $row;
    }

    /** Generated rows belong to their sync — never edited/deleted through here. */
    private static function guardGenerated(array $event): void
    {
        if ($event['source'] !== null && $event['source'] !== '') {
            throw new ServiceError('conflict', 'conflict', "This event is generated by the '{$event['source']}' sync and is read-only — it would be recreated on the next sync anyway.");
        }
    }

    /**
     * Collect + validate the writable event fields (mirrors the API resource's
     * apiCalendarEventFields). On update, absent keys fall back to the current
     * row. Datetimes are naive server-local (Z/offset rejected).
     */
    private static function collectEventFields(PDO $conn, array $in, array $current = [], ?ActorContext $ctx = null): array
    {
        $get = function (string $key, $default = null) use ($in, $current) {
            return array_key_exists($key, $in) ? $in[$key] : ($current[$key] ?? $default);
        };
        $f = [];
        $f['title']       = trim((string)$get('title', ''));
        $f['description'] = ($v = trim((string)($get('description') ?? ''))) !== '' ? $v : null;
        $f['location']    = ($v = trim((string)($get('location') ?? ''))) !== '' ? $v : null;
        $f['all_day']     = $get('all_day', 0) ? 1 : 0;

        $f['start'] = array_key_exists('start_at', $in)
            ? self::parseNaiveDatetime($in['start_at'], 'start_at')
            : ($current['start_datetime'] ?? null);
        $f['end'] = array_key_exists('end_at', $in)
            ? self::parseNaiveDatetime($in['end_at'], 'end_at')
            : ($current['end_datetime'] ?? null);
        if ($f['start'] !== null && $f['end'] !== null && $f['end'] < $f['start']) {
            throw new ServiceError('validation', 'invalid_field', "'end_at' cannot be before 'start_at'.");
        }

        // Friendly validation the raw UI left to FK errors.
        $f['category_id'] = null;
        $catRaw = $get('category_id');
        if ($catRaw !== null && $catRaw !== '') {
            $f['category_id'] = (int)$catRaw;
            $c = $conn->prepare("SELECT id FROM calendar_categories WHERE id = ?");
            $c->execute([$f['category_id']]);
            if (!$c->fetchColumn()) {
                throw new ServiceError('validation', 'invalid_field', "Unknown category id: {$f['category_id']}");
            }
        }
        $f['contract_id'] = null;
        $conRaw = $get('contract_id');
        if ($conRaw !== null && $conRaw !== '') {
            $f['contract_id'] = (int)$conRaw;
            try {
                require_once __DIR__ . '/../contract_party.php';
                [$kVis, $kArgs] = contractVisibilitySqlForScope($conn, $ctx ? $ctx->companyScope : null, 'k');   // #153
                $c = $conn->prepare("SELECT k.id FROM contracts k WHERE k.id = ?$kVis");
                $c->execute(array_merge([$f['contract_id']], $kArgs));
                if (!$c->fetchColumn()) {
                    throw new ServiceError('validation', 'invalid_field', "Unknown contract id: {$f['contract_id']}");
                }
            } catch (PDOException $e) {
                throw new ServiceError('validation', 'invalid_field', 'Contract links are not available on this install.');
            }
        }
        return $f;
    }

    /**
     * Parse a NAIVE local datetime ("YYYY-MM-DD HH:MM[:SS]", T separator OK,
     * date-only OK). Throws on timezone designators — naive-local by design.
     * (Throwing twin of the resource's apiParseNaiveDatetime.)
     */
    private static function parseNaiveDatetime($value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $v = trim((string)$value);
        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $v)) {
            throw new ServiceError('validation', 'invalid_field', "'{$field}' must be a naive local datetime (no Z/offset) — the calendar stores server-local times, matching the UI.");
        }
        $v = str_replace('T', ' ', $v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            $v .= ' 00:00:00';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v)) {
            $v .= ':00';
        }
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $v);
        if (!$dt || $dt->format('Y-m-d H:i:s') !== $v) {
            throw new ServiceError('validation', 'invalid_field', "'{$field}' must be 'YYYY-MM-DD HH:MM:SS' (naive local time).");
        }
        return $v;
    }
}
