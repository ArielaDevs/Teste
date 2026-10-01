<?php
/**
 * ChecklistsService — the single home for the SOP-checklist rules that other
 * modules have to obey.
 *
 * Contributed as PR #141 by Santhosh Srinivasan; this layer was added during
 * integration so the one rule that reaches outside the module — "a ticket with
 * outstanding mandatory steps does not close" — is written once instead of
 * being re-stated by every caller that can close a ticket.
 *
 * Follows the three service rules (wiki: Service-Layer-Architecture):
 *   1. method(PDO, ActorContext, …) — no superglobals
 *   2. no transport — returns data or throws ServiceError, never echoes
 *   3. typed errors — ServiceError(kind, code, message)
 *
 * ──────────────────────────────────────────────────────────────────────────
 * 🔴 WHY THE GATE IS HERE AND NOT IN THE BROWSER
 *
 * As contributed, the gate was an alert() in assets/js/inbox.js and nothing
 * else. The UI refused to close the ticket; the v1 REST API, bulk actions, the
 * workflow engine and one line of curl all closed it with every mandatory step
 * outstanding. For a feature whose entire purpose is ISO/SOP compliance, an
 * unenforced gate is worse than no gate — it reports control that isn't there.
 *
 * ⚠️ AND WHY IT CAN ALWAYS BE OVERRIDDEN.
 *
 * assets/js/inbox.js already carries the house position, written for #83 and
 * sitting three lines below where the checklist gate was inserted:
 *
 *     "closing a ticket that still has unfinished tasks WARNS, it never blocks
 *      … a warning that cannot be shown must not become a block that cannot be
 *      cleared."
 *
 * That is right, and a hard block breaks it: the analyst who owned step 4 has
 * left, the step is obsolete, and the ticket is stuck forever with no way out
 * but SQL. So the rule is not "you cannot close this". It is:
 *
 *     close it with the steps outstanding and the override is RECORDED —
 *     who closed it, when, and which steps were skipped.
 *
 * Which is what a compliance feature should produce anyway. An auditor does not
 * want a system where the bad outcome was impossible; they want one where it is
 * attributable. Blocking hides the exception, recording it surfaces it.
 * ──────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenant_settings.php';   // ticketChecklistClosureMode()

class ChecklistsService
{
    /**
     * Check if a ticket has at least one checklist / SOP attached.
     */
    public static function hasAttachedChecklists(PDO $conn, int $ticketId): bool
    {
        if ($ticketId <= 0) return false;
        try {
            $stmt = $conn->prepare("SELECT 1 FROM ticket_checklists WHERE ticket_id = ? LIMIT 1");
            $stmt->execute([$ticketId]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Mandatory steps still outstanding on a ticket.
     *
     * @return array<int, array{checklist: string, step: string, item_id: int}>
     */
    public static function outstandingMandatorySteps(PDO $conn, int $ticketId): array
    {
        if ($ticketId <= 0) return [];

        // Tolerate the tables not existing yet: a site that has never opened the
        // Checklists module must not have its ticket closures start failing.
        try {
            $stmt = $conn->prepare(
                "SELECT i.id AS item_id, i.title AS step, c.title AS checklist, c.closure_mode
                   FROM ticket_checklist_items i
                   JOIN ticket_checklists c ON c.id = i.ticket_checklist_id
                  WHERE c.ticket_id = ?
                    AND i.is_mandatory = 1
                    AND i.is_completed = 0
                  ORDER BY c.id ASC, i.sort_order ASC, i.id ASC"
            );
            $stmt->execute([$ticketId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * What this one checklist's gate actually is, once the company setting has
     * had its say.
     *
     * Two dials, and they only ever tighten:
     *
     *   the template   Standard (warn and record) or Critical (block), chosen
     *                  by whoever wrote the procedure, because they are the one
     *                  who knows whether skipping a step is a nuisance or a
     *                  compliance breach.
     *   the company    'per_template' honours that choice; 'block_all' overrides
     *                  every template and blocks the lot.
     *
     * There is deliberately no company setting that loosens a Critical template
     * to a warning. An operator who wants that should edit the template, where
     * the change is visible to the people following the procedure.
     *
     * 🔑 Strictest wins, and a ticket carrying several checklists is resolved a
     * checklist at a time by assertClosureAllowed() — so one Critical checklist
     * blocks the close even if three Standard ones would have warned.
     */
    public static function effectiveClosureMode(PDO $conn, string $mode, ?int $tenantId = null): string
    {
        if (function_exists('ticketChecklistClosureMode')
            && ticketChecklistClosureMode($conn, $tenantId) === 'block_all') {
            return 'block';
        }

        return $mode === 'block' ? 'block' : 'warn';
    }

    /**
     * Refuse the closure outright where the gate is a block.
     *
     * 🔴 MUST be called BEFORE the status is written. Called after, a blocked
     * close would throw having already closed the ticket - the caller sees an
     * error and the database disagrees with it.
     *
     * Enforced here rather than in the browser so the REST API, bulk actions and
     * automation obey whichever the operator chose; that is the whole reason the
     * rule left inbox.js.
     *
     * Two separate refusals live here, and they answer different questions:
     * whether this ticket may close with steps outstanding, and whether it may
     * close with no checklist at all.
     */
    public static function assertClosureAllowed(PDO $conn, int $ticketId, ?int $tenantId = null): void
    {
        // Nothing attached: the only question is whether this install insists
        // on a checklist. 'warn' is not decided here - a warning is the absence
        // of a refusal, and it is recorded by recordClosureOverride() after the
        // close, exactly as an outstanding-steps override is.
        if (!self::hasAttachedChecklists($conn, $ticketId)) {
            if (function_exists('ticketChecklistEmptyClosureMode')
                && ticketChecklistEmptyClosureMode($conn, $tenantId) === 'block') {
                throw new ServiceError(
                    'validation',
                    'no_checklist_attached',
                    'This ticket cannot be closed until a checklist is attached to it.'
                );
            }
            return;
        }

        $outstanding = self::outstandingMandatorySteps($conn, $ticketId);
        if (!$outstanding) return;

        // Resolve per checklist, not per step: the mode lives on the checklist,
        // so resolving it once per step would ask the same question repeatedly
        // and read the company setting once per outstanding step.
        $blocking  = [];
        $modeCache = [];
        foreach ($outstanding as $row) {
            $checklist = $row['checklist'] !== '' ? $row['checklist'] : 'Checklist';
            if (!isset($modeCache[$checklist])) {
                $modeCache[$checklist] = self::effectiveClosureMode(
                    $conn, (string)($row['closure_mode'] ?? 'warn'), $tenantId
                );
            }
            if ($modeCache[$checklist] === 'block') {
                $blocking[$checklist][] = $row['step'];
            }
        }
        if (!$blocking) return;

        $parts = [];
        foreach ($blocking as $checklist => $steps) {
            $parts[] = $checklist . ' (' . implode(', ', $steps) . ')';
        }

        throw new ServiceError(
            'validation',
            'mandatory_steps_outstanding',
            'This ticket cannot be closed until its mandatory checklist steps are complete: '
                . implode('; ', $parts)
        );
    }

    /**
     * Called by TicketsService when a ticket moves to a closed status.
     *
     * Records what was skipped rather than refusing it — see the header. Returns
     * the steps that were outstanding so the caller can tell the analyst what was
     * skipped; empty array = a clean close, or a close this install does not care
     * to record.
     *
     * @return array<int, array{checklist: string, step: string, item_id: int}>
     */

    public static function recordClosureOverride(
        PDO $conn,
        ActorContext $ctx,
        int $ticketId,
        ?int $tenantId = null
    ): array {
        // Closed with nothing attached. Only worth a note where the operator
        // asked to be told about it - on an install that allows it, a note on
        // every close is noise that hides the ones that matter.
        //
        // ⚠️ $tenantId, not null. assertClosureAllowed() resolves this setting
        // per company, so reading it install-wide here would let the two halves
        // of one rule disagree: company A blocks while the audit trail is
        // written against the default.
        if (!self::hasAttachedChecklists($conn, $ticketId)) {
            if (function_exists('ticketChecklistEmptyClosureMode')
                && ticketChecklistEmptyClosureMode($conn, $tenantId) === 'warn') {
                self::writeClosureNote(
                    $conn,
                    $ctx,
                    $ticketId,
                    "⚠️ Ticket closed with no checklist attached.\n" . self::closedBy($ctx)
                );
            }
            return [];
        }

        $outstanding = self::outstandingMandatorySteps($conn, $ticketId);
        if (!$outstanding) return [];

        $list = implode("\n", array_map(
            fn($r) => '  • ' . $r['checklist'] . ' — ' . $r['step'],
            $outstanding
        ));
        self::writeClosureNote(
            $conn,
            $ctx,
            $ticketId,
            "⚠️ Ticket closed with " . count($outstanding) . " mandatory checklist step(s) outstanding.\n"
                . self::closedBy($ctx) . "\n\n" . $list
        );

        return $outstanding;
    }

    /** "Closed by Jane Smith via the web interface." — one phrasing, used by both notes. */
    private static function closedBy(ActorContext $ctx): string
    {
        if ($ctx->source === 'workflow') return 'Closed by a workflow.';

        $who = $ctx->actorName !== '' ? $ctx->actorName : ('analyst #' . $ctx->actorId);
        $via = $ctx->source === 'api' ? 'the API' : 'the web interface';
        return "Closed by {$who} via {$via}.";
    }

    /**
     * Write one closure-audit entry, wherever it belongs.
     *
     * 🔴 A WORKFLOW MUST NOT WRITE TO ticket_notes. `analyst_id` is NOT NULL
     * with a foreign key to `analysts`, and a workflow has no analyst behind it.
     * Naming any id there does one of two bad things: on an install where that
     * analyst does not exist the insert throws and the audit entry is lost, and
     * on an install where they do exist it puts a real person's name against an
     * action they did not take. So an automated close is recorded in
     * `ticket_audit` with a NULL analyst, which is where the workflow engine's
     * own notes go and where the timeline already knows to look for them.
     *
     * Both notes go through here so that reasoning cannot be missed by the next
     * person adding a third kind of closure note.
     *
     * UTC at rest (GH #126).
     */
    private static function writeClosureNote(PDO $conn, ActorContext $ctx, int $ticketId, string $note): void
    {
        try {
            if ($ctx->actorId > 0) {
                $conn->prepare(
                    "INSERT INTO ticket_notes (ticket_id, analyst_id, note_text, is_internal, created_datetime)
                     VALUES (?, ?, ?, 1, UTC_TIMESTAMP())"
                )->execute([$ticketId, $ctx->actorId, $note]);
            } else {
                $conn->prepare(
                    "INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime)
                     VALUES (?, NULL, 'Workflow Note', NULL, ?, UTC_TIMESTAMP())"
                )->execute([$ticketId, $note]);
            }
        } catch (Throwable $e) {
            // A failed audit note must not block the close it is describing, but
            // it must not pass silently either.
            error_log('[checklists] could not record closure override for ticket '
                . $ticketId . ': ' . $e->getMessage());
        }
    }

    // ======================================================================
    //  Lookup lists — checklist categories and suggested roles
    //
    //  Two tables with identical shape (id, name) and identical rules, so one
    //  pair of methods serves both rather than four near-identical copies. The
    //  table is chosen from a whitelist, never from caller input, so `$kind`
    //  can never reach SQL.
    // ======================================================================

    private const LOOKUPS = [
        'category' => ['table' => 'checklist_categories', 'label' => 'Category'],
        'role'     => ['table' => 'checklist_roles',      'label' => 'Role'],
    ];

    private static function lookup(string $kind): array
    {
        if (!isset(self::LOOKUPS[$kind])) {
            throw new ServiceError('validation', 'invalid_field', 'Unknown list: ' . $kind);
        }
        return self::LOOKUPS[$kind];
    }

    /**
     * Create (no id) or rename (id present) a category or role. Returns the id.
     *
     * Renaming matters more than it looks: templates store their category as
     * TEXT (`checklist_templates.category`) and steps store their suggested role
     * the same way, so the rows have to be carried across with the rename or the
     * list silently orphans every template that used the old name.
     */
    public static function saveLookup(PDO $conn, ActorContext $ctx, string $kind, array $in): int
    {
        ['table' => $table, 'label' => $label] = self::lookup($kind);

        $name = trim((string)($in['name'] ?? ''));
        if ($name === '')            throw new ServiceError('validation', 'missing_field', $label . ' name is required.');
        if (mb_strlen($name) > 100)  throw new ServiceError('validation', 'invalid_field', $label . ' name is too long (100 characters maximum).');

        $id = isset($in['id']) ? (int)$in['id'] : 0;

        // Names are UNIQUE in both tables; catch the clash here so the caller
        // gets a sentence rather than a driver error.
        $clash = $conn->prepare("SELECT id FROM `$table` WHERE name = ? AND id <> ? LIMIT 1");
        $clash->execute([$name, $id]);
        if ($clash->fetchColumn() !== false) {
            throw new ServiceError('conflict', 'duplicate', $label . ' "' . $name . '" already exists.');
        }

        if ($id > 0) {
            $cur = $conn->prepare("SELECT name FROM `$table` WHERE id = ? LIMIT 1");
            $cur->execute([$id]);
            $oldName = $cur->fetchColumn();
            if ($oldName === false) throw new ServiceError('not_found', 'not_found', $label . ' not found.');

            if ($oldName !== $name) {
                $conn->beginTransaction();
                try {
                    $conn->prepare("UPDATE `$table` SET name = ? WHERE id = ?")->execute([$name, $id]);
                    // Carry the rows that reference it by name.
                    if ($kind === 'category') {
                        $conn->prepare("UPDATE checklist_templates SET category = ? WHERE category = ?")->execute([$name, $oldName]);
                    } else {
                        $conn->prepare("UPDATE checklist_template_items SET suggested_role = ? WHERE suggested_role = ?")->execute([$name, $oldName]);
                        $conn->prepare("UPDATE ticket_checklist_items  SET suggested_role = ? WHERE suggested_role = ?")->execute([$name, $oldName]);
                    }
                    $conn->commit();
                } catch (Throwable $e) {
                    $conn->rollBack();
                    throw $e;
                }
            }
            return $id;
        }

        $conn->prepare("INSERT INTO `$table` (name, created_datetime) VALUES (?, UTC_TIMESTAMP())")->execute([$name]);
        return (int)$conn->lastInsertId();
    }

    /**
     * Delete a category or role.
     *
     * The rows that reference it by name are left alone deliberately: a template
     * filed under a category you retire keeps saying what it said. Losing the
     * list entry should not silently re-file somebody's SOP.
     */
    public static function deleteLookup(PDO $conn, ActorContext $ctx, string $kind, int $id): void
    {
        ['table' => $table, 'label' => $label] = self::lookup($kind);
        if ($id <= 0) throw new ServiceError('validation', 'invalid_field', 'A valid id is required.');

        $stmt = $conn->prepare("DELETE FROM `$table` WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            throw new ServiceError('not_found', 'not_found', $label . ' not found.');
        }
    }

    // ======================================================================
    //  Checklists on TASKS (discussion #138, round three)
    //
    //  Sandy asked whether checklists should reach Tasks, and thought probably
    //  not: subtasks already split work between people with due dates, and two
    //  similar things side by side invite teams to use them interchangeably.
    //  Ed's answer was to build it behind a switch - Tasks → Settings →
    //  Checklists, OFF by default, the same shape as time recording - so a team
    //  that would be confused by both simply never sees it.
    //
    //  🔑 The rules are the TICKET rules, not new ones, so the two cannot drift:
    //   - a checklist is a COPY of the template's steps, taken when attached;
    //   - Standard (warn) records who completed the task with steps outstanding,
    //     Critical (block) refuses - resolved by effectiveClosureMode(), so the
    //     company's "block all" setting reaches tasks too (a zero-tolerance
    //     company that blocked tickets but let tasks through would have a gap);
    //   - the gate is enforced HERE, called from every path that completes a
    //     task (TasksService::updateTask / moveTask, reorder.php,
    //     toggle_subtask.php), never in the browser alone.
    //
    //  ⚠️ What tasks deliberately do NOT get: the "no checklist attached" rule.
    //  On tickets it is a default-off switch for procedure-led desks; on tasks it
    //  would gate every to-do on the board, which nobody would want.
    //
    //  🔴 Switched OFF means OFF: nothing shown and NOTHING ENFORCED, even where
    //  checklists were attached while it was on. A rule the admin has turned off
    //  must not keep refusing completions from behind a section nobody can see.
    // ======================================================================

    /** Is Tasks → Settings → Checklists switched on? Off by default; safe before DB Verification. */
    public static function tasksEnabled(PDO $conn): bool
    {
        try {
            $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'tasks_checklists_enabled'");
            $st->execute();
            return (string)$st->fetchColumn() === '1';
        } catch (Throwable $e) {
            return false;
        }
    }

    /** The task's company for the gate (NULL = the Default company, read as the install default). */
    public static function taskTenantId(PDO $conn, int $taskId): ?int
    {
        try {
            $st = $conn->prepare("SELECT tenant_id FROM tasks WHERE id = ?");
            $st->execute([$taskId]);
            $v = $st->fetchColumn();
            return ($v === false || $v === null) ? null : (int)$v;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * The task's checklists with their steps and progress, each carrying the
     * gate it will ACTUALLY apply (template mode + company setting), resolved
     * here so the padlock in the panel is the lock the server enforces.
     */
    public static function taskChecklists(PDO $conn, int $taskId): array
    {
        $tenantId = self::taskTenantId($conn, $taskId);
        $st = $conn->prepare("SELECT id, template_id, title, closure_mode, sort_order, created_datetime
                                FROM task_checklists WHERE task_id = ? ORDER BY sort_order ASC, id ASC");
        $st->execute([$taskId]);
        $lists = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = $conn->prepare("SELECT id, title, suggested_role, is_mandatory, requires_input, input_placeholder,
                                        response_value, is_completed, completed_by_id, completed_by_name, completed_datetime
                                   FROM task_checklist_items WHERE task_checklist_id = ?
                                  ORDER BY sort_order ASC, id ASC");
        foreach ($lists as &$l) {
            $l['closure_mode'] = $l['closure_mode'] ?: 'warn';
            $l['effective_closure_mode'] = self::effectiveClosureMode($conn, $l['closure_mode'], $tenantId);
            $items->execute([(int)$l['id']]);
            $l['items'] = $items->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $done = count(array_filter($l['items'], fn($i) => !empty($i['is_completed'])));
            $l['total_items'] = count($l['items']);
            $l['completed_items'] = $done;
            $l['percent'] = $l['total_items'] > 0 ? (int)round($done / $l['total_items'] * 100) : 0;
        }
        unset($l);
        return $lists;
    }

    /** Templates offered for a task: scope task or both, active, with the gate each would apply. */
    public static function templatesForTask(PDO $conn, int $taskId): array
    {
        $tenantId = self::taskTenantId($conn, $taskId);
        $rows = $conn->query("SELECT id, title, category, description, closure_mode FROM checklist_templates
                               WHERE scope IN ('task','both') AND (is_active = 1 OR is_active IS NULL)
                               ORDER BY category ASC, title ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['closure_mode'] = $r['closure_mode'] ?: 'warn';
            $r['effective_closure_mode'] = self::effectiveClosureMode($conn, $r['closure_mode'], $tenantId);
        }
        unset($r);
        return $rows;
    }

    /**
     * Attach a template to a task: copy its title, gate and steps. Returns the new id.
     *
     * The copy is the point, exactly as on a ticket: rewriting a procedure must
     * not change a task already half-way through it.
     */
    public static function attachTemplateToTask(PDO $conn, ActorContext $ctx, int $taskId, int $templateId): int
    {
        $tpl = $conn->prepare("SELECT title, closure_mode, scope FROM checklist_templates WHERE id = ?");
        $tpl->execute([$templateId]);
        $t = $tpl->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            throw new ServiceError('not_found', 'not_found', 'Checklist not found.');
        }
        if (!in_array((string)$t['scope'], ['task', 'both'], true)) {
            throw new ServiceError('validation', 'invalid_field', 'That checklist is for tickets only.');
        }
        return self::copyTemplateToTask($conn, $taskId, $templateId, (string)$t['title'],
            (string)$t['closure_mode'], $ctx->actorId > 0 ? $ctx->actorId : null);
    }

    /** The one place a task checklist is written: parent, then its steps, in one transaction. */
    private static function copyTemplateToTask(PDO $conn, int $taskId, int $templateId, string $title,
                                               string $closureMode, ?int $createdBy): int
    {
        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            $conn->prepare("INSERT INTO task_checklists (task_id, template_id, title, closure_mode, sort_order, created_by_id, created_datetime)
                            VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
                 ->execute([$taskId, $templateId, $title, $closureMode === 'block' ? 'block' : 'warn',
                            self::nextTaskChecklistOrder($conn, $taskId), $createdBy]);
            $chkId = (int)$conn->lastInsertId();

            $steps = $conn->prepare("SELECT title, suggested_role, is_mandatory, requires_input, input_placeholder, sort_order
                                       FROM checklist_template_items WHERE template_id = ? ORDER BY sort_order ASC, id ASC");
            $steps->execute([$templateId]);
            $ins = $conn->prepare("INSERT INTO task_checklist_items
                                     (task_checklist_id, title, suggested_role, is_mandatory, requires_input, input_placeholder, sort_order)
                                   VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($steps->fetchAll(PDO::FETCH_ASSOC) as $i => $s) {
                $ins->execute([$chkId, $s['title'], $s['suggested_role'] ?: null, !empty($s['is_mandatory']) ? 1 : 0,
                               !empty($s['requires_input']) ? 1 : 0, $s['input_placeholder'] ?: null,
                               $s['sort_order'] ?? ($i + 1)]);
            }
            if ($own) $conn->commit();
            return $chkId;
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /** A newly attached checklist goes below the ones already on the task. */
    private static function nextTaskChecklistOrder(PDO $conn, int $taskId): int
    {
        $st = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM task_checklists WHERE task_id = ?");
        $st->execute([$taskId]);
        return (int)$st->fetchColumn();
    }

    /**
     * Put a task's checklists in a new order. $ids is the whole list, top first.
     * Each UPDATE is pinned to the task, so an id from another task matches
     * nothing - the caller gates on $taskId and that gate then covers every row.
     */
    public static function reorderTaskChecklists(PDO $conn, int $taskId, array $ids): void
    {
        $st = $conn->prepare("UPDATE task_checklists SET sort_order = ? WHERE id = ? AND task_id = ?");
        foreach (array_values($ids) as $i => $id) {
            $st->execute([$i + 1, (int)$id, $taskId]);
        }
    }

    /** The same for the steps inside one checklist, pinned to that checklist. */
    public static function reorderTaskChecklistItems(PDO $conn, int $checklistId, array $ids): void
    {
        $st = $conn->prepare("UPDATE task_checklist_items SET sort_order = ? WHERE id = ? AND task_checklist_id = ?");
        foreach (array_values($ids) as $i => $id) {
            $st->execute([$i + 1, (int)$id, $checklistId]);
        }
    }

    /** Which task does this step / checklist belong to? 0 if neither exists. Callers gate on the task. */
    public static function taskIdForItem(PDO $conn, int $itemId): int
    {
        $st = $conn->prepare("SELECT c.task_id FROM task_checklist_items i
                                JOIN task_checklists c ON c.id = i.task_checklist_id WHERE i.id = ?");
        $st->execute([$itemId]);
        return (int)($st->fetchColumn() ?: 0);
    }
    public static function taskIdForChecklist(PDO $conn, int $checklistId): int
    {
        $st = $conn->prepare("SELECT task_id FROM task_checklists WHERE id = ?");
        $st->execute([$checklistId]);
        return (int)($st->fetchColumn() ?: 0);
    }

    /**
     * Tick or untick a step. A step that asks for a value cannot be ticked
     * without one - enforced here, not only in the panel, because a tick with an
     * empty answer is a record that says something was checked when it was not.
     *
     * UTC at rest (GH #126). Returns the stored row.
     */
    public static function toggleTaskItem(PDO $conn, ActorContext $ctx, int $itemId, bool $completed, ?string $value): array
    {
        $st = $conn->prepare("SELECT requires_input FROM task_checklist_items WHERE id = ?");
        $st->execute([$itemId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Step not found.');
        }
        // A value is only kept on a step that asks for one - a stray value on a
        // plain tick would show up as an answer to a question nobody asked.
        $value = ((int)$row['requires_input'] === 1 && $value !== null) ? trim($value) : null;
        if ($completed && (int)$row['requires_input'] === 1 && ($value === null || $value === '')) {
            throw new ServiceError('validation', 'missing_field', 'This step needs a value before it can be ticked.');
        }
        if ($completed) {
            $conn->prepare("UPDATE task_checklist_items
                               SET is_completed = 1, response_value = ?, completed_by_id = ?,
                                   completed_by_name = ?, completed_datetime = UTC_TIMESTAMP()
                             WHERE id = ?")
                 ->execute([$value !== '' ? $value : null, $ctx->actorId > 0 ? $ctx->actorId : null,
                            $ctx->actorName !== '' ? $ctx->actorName : null, $itemId]);
        } else {
            $conn->prepare("UPDATE task_checklist_items
                               SET is_completed = 0, response_value = NULL, completed_by_id = NULL,
                                   completed_by_name = NULL, completed_datetime = NULL
                             WHERE id = ?")
                 ->execute([$itemId]);
        }
        $back = $conn->prepare("SELECT id, is_completed, response_value, completed_by_id, completed_by_name, completed_datetime
                                  FROM task_checklist_items WHERE id = ?");
        $back->execute([$itemId]);
        return $back->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /** Remove one checklist from a task: its steps first (no FK to rely on), then it. */
    public static function removeTaskChecklist(PDO $conn, int $checklistId): void
    {
        $own = !$conn->inTransaction();
        if ($own) $conn->beginTransaction();
        try {
            $conn->prepare("DELETE FROM task_checklist_items WHERE task_checklist_id = ?")->execute([$checklistId]);
            $conn->prepare("DELETE FROM task_checklists WHERE id = ?")->execute([$checklistId]);
            if ($own) $conn->commit();
        } catch (Throwable $e) {
            if ($own && $conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Remove every checklist from these tasks - for TasksService::deleteTask,
     * which deletes a whole subtask tree. Children explicitly, then parents;
     * tolerant of the tables not existing yet.
     *
     * @param int[] $taskIds
     */
    public static function deleteForTasks(PDO $conn, array $taskIds): void
    {
        $taskIds = array_values(array_filter(array_map('intval', $taskIds)));
        if (!$taskIds) return;
        $in = implode(',', array_fill(0, count($taskIds), '?'));
        try {
            $conn->prepare("DELETE i FROM task_checklist_items i
                              JOIN task_checklists c ON c.id = i.task_checklist_id
                             WHERE c.task_id IN ($in)")->execute($taskIds);
            $conn->prepare("DELETE FROM task_checklists WHERE task_id IN ($in)")->execute($taskIds);
        } catch (Throwable $e) {
            // tables not there yet on a part-upgraded install: nothing to remove
        }
    }

    /** Mandatory steps still outstanding on a task (same shape as the ticket version). */
    public static function taskOutstandingMandatorySteps(PDO $conn, int $taskId): array
    {
        if ($taskId <= 0) return [];
        try {
            $st = $conn->prepare(
                "SELECT i.id AS item_id, i.title AS step, c.title AS checklist, c.closure_mode
                   FROM task_checklist_items i
                   JOIN task_checklists c ON c.id = i.task_checklist_id
                  WHERE c.task_id = ? AND i.is_mandatory = 1 AND i.is_completed = 0
                  ORDER BY c.id ASC, i.sort_order ASC, i.id ASC"
            );
            $st->execute([$taskId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];   // tables not created yet: completing a task must not start failing
        }
    }

    /**
     * What completing this task now would run into - for the panel and the
     * board to warn BEFORE they post. Read-only; the server still decides.
     *
     * @return array{blocking: array<string,string[]>, warning: array<string,string[]>}
     */
    public static function taskCompletionCheck(PDO $conn, int $taskId): array
    {
        $out = ['blocking' => [], 'warning' => []];
        if (!self::tasksEnabled($conn)) return $out;
        $tenantId = self::taskTenantId($conn, $taskId);
        foreach (self::taskOutstandingMandatorySteps($conn, $taskId) as $r) {
            $name = $r['checklist'] !== '' ? $r['checklist'] : 'Checklist';
            $mode = self::effectiveClosureMode($conn, (string)($r['closure_mode'] ?? 'warn'), $tenantId);
            $out[$mode === 'block' ? 'blocking' : 'warning'][$name][] = $r['step'];
        }
        return $out;
    }

    /**
     * Refuse completing the task where a Critical checklist has steps outstanding.
     *
     * 🔴 MUST be called BEFORE the status is written - see assertClosureAllowed().
     * Called from every path that completes a task; a new one must call it too.
     */
    public static function assertTaskCompletionAllowed(PDO $conn, int $taskId): void
    {
        $check = self::taskCompletionCheck($conn, $taskId);
        if (!$check['blocking']) return;
        $parts = [];
        foreach ($check['blocking'] as $checklist => $steps) {
            $parts[] = $checklist . ' (' . implode(', ', $steps) . ')';
        }
        throw new ServiceError('validation', 'mandatory_steps_outstanding',
            'This task cannot be completed until its mandatory checklist steps are done: ' . implode('; ', $parts));
    }

    /**
     * Called AFTER a task has moved to a closed status: record any Standard
     * checklist steps it was completed without, on the task's own audit trail.
     *
     * task_audit, not a comment: its analyst_id is nullable, so a workflow or
     * an API key with no analyst behind it is recorded honestly as nobody,
     * instead of being stamped with a colleague's name (the round-two lesson,
     * see writeClosureNote()).
     */
    public static function recordTaskCompletionOverride(PDO $conn, ActorContext $ctx, int $taskId): void
    {
        if (!self::tasksEnabled($conn)) return;
        $outstanding = self::taskOutstandingMandatorySteps($conn, $taskId);
        if (!$outstanding) return;
        $list = implode('; ', array_map(fn($r) => $r['checklist'] . ' - ' . $r['step'], $outstanding));
        $note = count($outstanding) . ' mandatory checklist step(s) outstanding: ' . $list . '. ' . self::closedBy($ctx);
        try {
            $conn->prepare("INSERT INTO task_audit (task_id, analyst_id, field_name, old_value, new_value, source, created_datetime)
                            VALUES (?, ?, 'Completed with checklist steps outstanding', NULL, ?, ?, UTC_TIMESTAMP())")
                 ->execute([$taskId, $ctx->actorId > 0 ? $ctx->actorId : null, mb_substr($note, 0, 500),
                            // 'app' for the web interface, as the rest of task_audit writes it.
                            $ctx->source === 'ui' ? 'app' : substr($ctx->source, 0, 20)]);
        } catch (Throwable $e) {
            error_log('[checklists] could not record task completion override for task ' . $taskId . ': ' . $e->getMessage());
        }
    }

    /**
     * Give a new occurrence of a recurring task fresh, unticked copies of the
     * checklists the finished one had (TaskRecurrence::createOccurrence).
     *
     * Re-copied from the TEMPLATE where it still exists, so a monthly check
     * picks up this month's version of the procedure; a checklist whose template
     * has since been deleted is copied from the finished task's own steps
     * instead, rather than silently vanishing from the series.
     */
    public static function copyTaskChecklists(PDO $conn, int $fromTaskId, int $toTaskId): void
    {
        if (!self::tasksEnabled($conn)) return;
        try {
            $src = $conn->prepare("SELECT c.id, c.template_id, c.title, c.closure_mode, c.created_by_id,
                                          t.id AS tpl_exists, t.title AS tpl_title, t.closure_mode AS tpl_mode
                                     FROM task_checklists c
                                     LEFT JOIN checklist_templates t ON t.id = c.template_id
                                    WHERE c.task_id = ? ORDER BY c.sort_order ASC, c.id ASC");
            $src->execute([$fromTaskId]);
            foreach ($src->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if ($c['tpl_exists']) {
                    self::copyTemplateToTask($conn, $toTaskId, (int)$c['template_id'], (string)$c['tpl_title'],
                        (string)$c['tpl_mode'], $c['created_by_id'] !== null ? (int)$c['created_by_id'] : null);
                    continue;
                }
                $conn->prepare("INSERT INTO task_checklists (task_id, template_id, title, closure_mode, sort_order, created_by_id, created_datetime)
                                VALUES (?, NULL, ?, ?, ?, ?, UTC_TIMESTAMP())")
                     ->execute([$toTaskId, $c['title'], $c['closure_mode'] ?: 'warn',
                                self::nextTaskChecklistOrder($conn, $toTaskId), $c['created_by_id']]);
                $newId = (int)$conn->lastInsertId();
                $conn->prepare("INSERT INTO task_checklist_items
                                  (task_checklist_id, title, suggested_role, is_mandatory, requires_input, input_placeholder, sort_order)
                                SELECT ?, title, suggested_role, is_mandatory, requires_input, input_placeholder, sort_order
                                  FROM task_checklist_items WHERE task_checklist_id = ?")
                     ->execute([$newId, (int)$c['id']]);
            }
        } catch (Throwable $e) {
            // A recurrence must still be created if this fails.
            error_log('[checklists] could not copy checklists to recurring task ' . $toTaskId . ': ' . $e->getMessage());
        }
    }
}
