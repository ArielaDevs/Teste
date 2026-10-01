<?php
/**
 * Task recurrence — the date engine and the spawning of occurrences.
 *
 * Discussion #94 (dschipfel): recurring tasks in the style of Microsoft Planner.
 *
 * ── The model ────────────────────────────────────────────────────────────────
 * A recurrence is a SERIES, not a template. The rule lives in one row of
 * task_recurrences; every task the series produces carries recurrence_id (the
 * series) and recurrence_master_id (the FIRST task of the series, so any
 * occurrence can point a reader back to where it started).
 *
 * That mirrors how people describe it - "this task repeats" - rather than
 * introducing a hidden template task that is not real work and would show up in
 * every list, count and report that was written before recurrence existed.
 *
 * ── Two ways to repeat, and they are genuinely different ─────────────────────
 *   'completion'  the next occurrence is created when this one is COMPLETED,
 *                 counted from the completion date. A monthly backup check you
 *                 finish four days late should next be due a month after you did
 *                 it, not a month after it was theoretically due. This is what
 *                 Planner does and what most people mean.
 *   'schedule'    occurrences appear on fixed dates whether or not the previous
 *                 one was finished. A compliance review happens on the 1st
 *                 whether or not last month's was signed off, and the fact that
 *                 last month's is still open is the point.
 *
 * The distinction matters because 'schedule' can produce a backlog and
 * 'completion' cannot. Only 'schedule' needs anything to run in the background.
 *
 * ⚠️ All arithmetic here is on DATES, never datetimes. A due date is a calendar
 * day, not an instant: "the 1st" means the 1st wherever the reader is. Passing a
 * datetime through this would reintroduce GH #116 by another route.
 */

class TaskRecurrence
{
    /** ISO weekday numbers, as PHP's date('N') gives them. */
    const MON = 1, TUE = 2, WED = 3, THU = 4, FRI = 5, SAT = 6, SUN = 7;

    /** A rule with nothing filled in - every field the engine understands. */
    public static function blankRule(): array
    {
        return [
            'mode'          => 'completion',  // completion | schedule
            'freq'          => 'weekly',      // daily | weekly | monthly | yearly
            'interval_n'    => 1,             // every N days/weeks/months/years
            'weekdays'      => '',            // weekly: CSV of ISO weekdays, e.g. "1,3,5"
            'month_mode'    => 'dom',         // monthly/yearly: dom | nth
            'day_of_month'  => null,          // dom: 1..31, or -1 for the last day
            'nth'           => null,          // nth: 1..4, or -1 for the last one
            'nth_weekday'   => null,          // nth: ISO weekday
            'month_of_year' => null,          // yearly: 1..12
            'ends_mode'     => 'never',       // never | on_date | after_count
            'ends_on'       => null,
            'max_occurrences' => null,
        ];
    }

    /**
     * The next date in the series strictly AFTER $from.
     *
     * $from is the anchor: the completion date for 'completion' mode, or the
     * previous occurrence's due date for 'schedule'. Returns Y-m-d, or null if
     * the rule cannot produce one (an empty weekly weekday set, say).
     *
     * Every branch is "move the anchor forward, then land on a valid day",
     * never "add days and hope" - which is what makes month ends and nth
     * weekdays behave.
     */
    public static function nextDate(array $rule, string $from): ?string
    {
        $r    = $rule + self::blankRule();
        $step = max(1, (int)($r['interval_n'] ?? 1));
        $d    = self::mkDate($from);
        if (!$d) return null;

        switch ($r['freq']) {
            case 'daily':
                return $d->modify("+{$step} day")->format('Y-m-d');

            case 'weekly':
                return self::nextWeekly($d, $step, (string)$r['weekdays']);

            case 'monthly':
                return self::nextMonthly($d, $step, $r);

            case 'yearly':
                return self::nextYearly($d, $step, $r);
        }
        return null;
    }

    /**
     * Weekly, optionally on named days.
     *
     * With no days named it is simply every N weeks on the same weekday. With
     * days named, the next named day is found by walking forward - and when the
     * week runs out, jumping to the start of the week N weeks on, so "every 2
     * weeks on Mon and Thu" does not quietly become every week.
     */
    private static function nextWeekly(DateTimeImmutable $d, int $step, string $weekdays): ?string
    {
        $days = array_values(array_filter(array_map('intval', explode(',', $weekdays)),
            fn($n) => $n >= 1 && $n <= 7));
        if (!$days) {
            return $d->modify('+' . (7 * $step) . ' day')->format('Y-m-d');
        }
        sort($days);

        $cur = (int)$d->format('N');
        foreach ($days as $wd) {
            if ($wd > $cur) {
                return $d->modify('+' . ($wd - $cur) . ' day')->format('Y-m-d');
            }
        }
        // Past the last named day this week: go to the Monday $step weeks on,
        // then out to the first named day of that week.
        $mondayNext = $d->modify('-' . ($cur - 1) . ' day')->modify('+' . (7 * $step) . ' day');
        return $mondayNext->modify('+' . ($days[0] - 1) . ' day')->format('Y-m-d');
    }

    /** Monthly, either on a day number or on the nth weekday. */
    private static function nextMonthly(DateTimeImmutable $d, int $step, array $r): ?string
    {
        // Move to the first of the target month FIRST. Adding a month to the
        // 31st in PHP lands in the month after next, which is the classic way
        // monthly recurrence quietly skips February.
        $firstOfTarget = $d->modify('first day of this month')->modify("+{$step} month");

        if (($r['month_mode'] ?? 'dom') === 'nth') {
            return self::nthWeekdayOf($firstOfTarget, (int)$r['nth'], (int)$r['nth_weekday']);
        }
        $dom = $r['day_of_month'] !== null ? (int)$r['day_of_month'] : (int)$d->format('j');
        return self::dayOfMonth($firstOfTarget, $dom);
    }

    /** Yearly. Same two shapes as monthly, a chosen month at a time. */
    private static function nextYearly(DateTimeImmutable $d, int $step, array $r): ?string
    {
        $target = $d->modify('first day of this month')->modify("+{$step} year");
        $month  = $r['month_of_year'] !== null ? (int)$r['month_of_year'] : (int)$d->format('n');
        $target = $target->setDate((int)$target->format('Y'), max(1, min(12, $month)), 1);

        if (($r['month_mode'] ?? 'dom') === 'nth') {
            return self::nthWeekdayOf($target, (int)$r['nth'], (int)$r['nth_weekday']);
        }
        $dom = $r['day_of_month'] !== null ? (int)$r['day_of_month'] : (int)$d->format('j');
        return self::dayOfMonth($target, $dom);
    }

    /**
     * A day number within the month $first belongs to.
     * -1 means the last day. Anything past the end of a short month CLAMPS to
     * its last day: "the 31st" in a 30-day month means the 30th, not the 1st of
     * the month after, which is what naive date arithmetic produces.
     */
    private static function dayOfMonth(DateTimeImmutable $first, int $dom): string
    {
        $len = (int)$first->format('t');
        if ($dom === -1 || $dom > $len) $dom = $len;
        if ($dom < 1) $dom = 1;
        return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), $dom)->format('Y-m-d');
    }

    /**
     * The nth given weekday of the month $first belongs to - "the 2nd Tuesday",
     * "the last Friday". $nth of -1 means the last one.
     *
     * A month has either four or five of any weekday, so a rule asking for the
     * 5th falls back to the last rather than spilling into the next month.
     */
    private static function nthWeekdayOf(DateTimeImmutable $first, int $nth, int $weekday): ?string
    {
        if ($weekday < 1 || $weekday > 7) return null;

        if ($nth === -1) {
            $last  = $first->modify('last day of this month');
            $shift = ((int)$last->format('N') - $weekday + 7) % 7;
            return $last->modify("-{$shift} day")->format('Y-m-d');
        }
        $nth   = max(1, min(5, $nth));
        $shift = ($weekday - (int)$first->format('N') + 7) % 7;
        $cand  = $first->modify('+' . ($shift + 7 * ($nth - 1)) . ' day');
        // Asked for the 5th and the month has only four: use the last one.
        if ((int)$cand->format('n') !== (int)$first->format('n')) {
            return self::nthWeekdayOf($first, -1, $weekday);
        }
        return $cand->format('Y-m-d');
    }

    /**
     * Has the series finished? Checked BEFORE creating, so a series that ends
     * "after 6 occurrences" produces six and not seven.
     */
    public static function isExhausted(array $rule, string $nextDate): bool
    {
        $mode = $rule['ends_mode'] ?? 'never';
        if ($mode === 'on_date') {
            return !empty($rule['ends_on']) && $nextDate > (string)$rule['ends_on'];
        }
        if ($mode === 'after_count') {
            $max = (int)($rule['max_occurrences'] ?? 0);
            return $max > 0 && (int)($rule['occurrences_created'] ?? 0) >= $max;
        }
        return false;
    }

    private static function mkDate(string $ymd): ?DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim($ymd), 0, 10), new DateTimeZone('UTC'));
        return $d ?: null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Making the next occurrence
    // ─────────────────────────────────────────────────────────────────────────

    /** The rule a task belongs to, or null. */
    public static function ruleForTask(PDO $conn, int $taskId): ?array
    {
        $s = $conn->prepare(
            "SELECT r.* FROM task_recurrences r
               JOIN tasks t ON t.recurrence_id = r.id
              WHERE t.id = ? AND r.is_active = 1"
        );
        $s->execute([$taskId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * A task has just been completed. If it repeats on completion, make the next
     * one and return its id.
     *
     * Called from BOTH ways a task can close - saving a status change, and
     * dragging the card on the board. moveTask() is documented "No workflow
     * event" and dispatches nothing, so hooking only the save path would have
     * meant a task that repeats when you tick it and silently does not when you
     * drag it, which is the worst of both.
     *
     * Never throws. A recurrence that cannot be produced must not take the
     * completion down with it: the person finished their work and that has to be
     * recorded whatever happens next.
     */
    public static function onTaskClosed(PDO $conn, int $taskId): ?int
    {
        try {
            $rule = self::ruleForTask($conn, $taskId);
            if (!$rule || $rule['mode'] !== 'completion') return null;

            // Counted from the day it was actually finished, not from the day it
            // was due. A monthly check finished four days late is next due a
            // month after you did it.
            $next = self::nextDate($rule, gmdate('Y-m-d'));
            if ($next === null || self::isExhausted($rule, $next)) {
                if ($next === null || self::isExhausted($rule, $next)) self::deactivate($conn, (int)$rule['id']);
                return null;
            }
            return self::createOccurrence($conn, $taskId, $rule, $next);
        } catch (Throwable $e) {
            error_log('TaskRecurrence::onTaskClosed failed for task ' . $taskId . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Produce every occurrence now due on a fixed schedule. Returns the ids made.
     *
     * Deliberately catches up rather than skipping: a series whose worker has not
     * run for a week produces the occurrences it owed, because a missed
     * compliance review is a fact somebody needs to see, not something to quietly
     * forget. Capped per series per run so a rule left dormant for two years
     * cannot create six hundred tasks in one go.
     */
    public static function runDue(PDO $conn, ?string $today = null, int $capPerSeries = 24,
                                  ?array $onlyRecurrenceIds = null): array
    {
        $today = $today ?: gmdate('Y-m-d');
        $made  = [];

        // ⚠️ $onlyRecurrenceIds exists because the tests need it. Without it a
        // test calling runDue() operates on EVERY series in the database, which
        // on a developer machine is the developer's real tasks — it created a
        // real occurrence of a real series while this very change was being
        // tested. It also makes the assertions honest: "it made 3" cannot be
        // satisfied by somebody else's series catching up at the same moment.
        $scope = '';
        $args  = [];
        if ($onlyRecurrenceIds !== null) {
            $ids = array_values(array_filter(array_map('intval', $onlyRecurrenceIds)));
            if (!$ids) return [];
            $scope = ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $args  = $ids;
        }

        // Deliberately NOT filtered on next_due_date. A series whose next
        // occurrence is due in a fortnight may still be ready to start today —
        // see startForDue() — so the date is decided per series below, with the
        // source task in hand, rather than in SQL that cannot see the gap.
        $s = $conn->prepare(
            "SELECT * FROM task_recurrences
              WHERE is_active = 1 AND mode = 'schedule' AND next_due_date IS NOT NULL" . $scope
        );
        $s->execute($args);

        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $rule) {
            $rid  = (int)$rule['id'];
            $due  = (string)$rule['next_due_date'];
            $seen = 0;

            while ($due !== '' && $seen < $capPerSeries) {
                if (self::isExhausted($rule, $due)) { self::deactivate($conn, $rid); break; }

                // The newest occurrence is what the next one is copied from, so
                // an edit to the series carries forward rather than resurrecting
                // whatever the first one said a year ago.
                $src = self::latestOccurrence($conn, $rid);
                if (!$src) { self::deactivate($conn, $rid); break; }

                // An occurrence is created when work on it can BEGIN, not when
                // it falls due. A task that needs a fortnight appears a
                // fortnight ahead; one with no start date is a point-in-time
                // task and appears on the day, as before. This is why the board
                // holds work you could actually be doing now: the lead time is
                // the task's own duration, which the user has already told us,
                // rather than a number somebody has to guess.
                $row    = self::taskRow($conn, $src);
                $begins = ($row ? self::startForDue($row, $due) : null) ?: $due;
                if ($begins > $today) break;   // not yet — a later run will take it

                try {
                    // Idempotency, and it is not theoretical. A series is seeded
                    // with next_due_date set to its FIRST occurrence's own due
                    // date, so without this the very first worker run produces a
                    // duplicate of the task the series was created from. The same
                    // guard covers the worker running twice — two cron entries, or
                    // someone running it by hand after it has already fired.
                    if (self::occurrenceExistsFor($conn, $rid, $due)) {
                        $id = null;
                    } else {
                        $id = self::createOccurrence($conn, $src, $rule, $due);
                    }
                    if ($id) { $made[] = $id; $rule['occurrences_created'] = (int)$rule['occurrences_created'] + 1; }
                } catch (Throwable $e) {
                    error_log("TaskRecurrence::runDue series $rid failed: " . $e->getMessage());
                    break;
                }

                $next = self::nextDate($rule, $due);
                if ($next === null || $next === $due) { self::deactivate($conn, $rid); break; }
                $due = $next;
                $seen++;
                $conn->prepare("UPDATE task_recurrences SET next_due_date = ? WHERE id = ?")->execute([$due, $rid]);
            }
        }
        return $made;
    }

    /**
     * Dates a FIXED SCHEDULE will land on between two dates, without creating
     * anything.
     *
     * The calendar's job is to show what is coming, and until this existed it
     * could only show what already existed - which on a fixed schedule is one
     * occurrence at a time, because the rest are not created until their dates
     * arrive. Setting up "every Monday, five times" and seeing a single entry is
     * a fair reason to think the feature is broken.
     *
     * So these are PROJECTIONS: real dates from the real rule, drawn faintly,
     * with no rows behind them. That keeps both halves honest - the board stays
     * clear of work that does not exist yet, and the calendar still shows the
     * plan.
     *
     * ⚠️ ONLY 'schedule' series. A series that repeats on completion has no
     * future to project: its next date is counted from the day somebody finishes
     * the current one, which has not happened. Drawing a guess there would be
     * inventing information, and it would move every time somebody was a day
     * late.
     */
    public static function project(PDO $conn, string $from, string $to, int $capPerSeries = 60): array
    {
        $out = [];
        $s = $conn->prepare(
            "SELECT r.*, t.id AS source_task_id, t.title, t.status_id, t.priority_id,
                    t.assigned_analyst_id, t.tenant_id,
                    t.start_date AS src_start, t.due_date AS src_due
               FROM task_recurrences r
               JOIN tasks t ON t.id = (
                    SELECT id FROM tasks WHERE recurrence_id = r.id AND parent_task_id IS NULL
                     ORDER BY id DESC LIMIT 1)
              WHERE r.is_active = 1 AND r.mode = 'schedule' AND r.next_due_date IS NOT NULL"
        );
        $s->execute();

        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $rule) {
            $due   = (string)$rule['next_due_date'];
            $made  = (int)$rule['occurrences_created'];
            $seen  = 0;

            while ($due !== '' && $due <= $to && $seen < $capPerSeries) {
                // Same accounting the worker uses, so what is drawn is what will
                // actually be produced - including a series stopping early.
                $probe = $rule;
                $probe['occurrences_created'] = $made;
                if (self::isExhausted($probe, $due)) break;

                if ($due >= $from) {
                    $out[] = [
                        'id'             => null,          // nothing exists yet
                        'projected'      => true,
                        'source_task_id' => (int)$rule['source_task_id'],
                        'recurrence_id'  => (int)$rule['id'],
                        'title'          => $rule['title'],
                        'due_date'       => $due,
                        // The same span the real occurrence will have, so a
                        // fortnight of work is drawn as a fortnight rather than
                        // as a single day it could never be done in.
                        'start_date'     => self::startForDue(
                            ['start_date' => $rule['src_start'], 'due_date' => $rule['src_due']],
                            $due
                        ),
                        'status_id'      => $rule['status_id'],
                        'priority_id'    => $rule['priority_id'],
                        'assigned_analyst_id' => $rule['assigned_analyst_id'],
                        'tenant_id'      => $rule['tenant_id'],
                    ];
                }
                $made++;
                $seen++;
                $next = self::nextDate($rule, $due);
                if ($next === null || $next === $due) break;
                $due = $next;
            }
        }
        return $out;
    }

    private static function deactivate(PDO $conn, int $recurrenceId): void
    {
        $conn->prepare("UPDATE task_recurrences SET is_active = 0, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
             ->execute([$recurrenceId]);
    }

    /** Does this series already have a top-level occurrence due on that date? */
    private static function occurrenceExistsFor(PDO $conn, int $recurrenceId, string $due): bool
    {
        $s = $conn->prepare(
            "SELECT 1 FROM tasks
              WHERE recurrence_id = ? AND parent_task_id IS NULL AND due_date = ? LIMIT 1"
        );
        $s->execute([$recurrenceId, $due]);
        return (bool)$s->fetchColumn();
    }

    /** The most recent task in a series - the one a new occurrence is copied from. */
    /** Keep a value inside a range, or null. */
    private static function clampInt($v, int $min, int $max): ?int
    {
        if ($v === null || $v === '') return null;
        return max($min, min($max, (int)$v));
    }

    /**
     * Build a rule from whatever the browser sent, discarding anything invalid.
     *
     * ⚠️ ONE home, because two endpoints read this shape: saving a rule, and
     * previewing one that has not been saved yet. A preview built from
     * differently-sanitised input would show dates the worker will not produce,
     * and a preview that lies is worse than no preview at all.
     */
    public static function ruleFromInput(array $in): array
    {
        $mode      = in_array(($in['mode'] ?? ''), ['completion', 'schedule'], true) ? $in['mode'] : 'completion';
        $freq      = in_array(($in['freq'] ?? ''), ['daily', 'weekly', 'monthly', 'yearly'], true) ? $in['freq'] : 'weekly';
        $monthMode = in_array(($in['month_mode'] ?? ''), ['dom', 'nth'], true) ? $in['month_mode'] : 'dom';
        $endsMode  = in_array(($in['ends_mode'] ?? ''), ['never', 'on_date', 'after_count'], true) ? $in['ends_mode'] : 'never';

        // Weekly days arrive as an array or a CSV; either way only ISO 1-7 survive.
        $weekdays = $in['weekdays'] ?? '';
        if (is_array($weekdays)) $weekdays = implode(',', $weekdays);
        $weekdays = implode(',', array_filter(array_map('intval', explode(',', (string)$weekdays)),
            fn($n) => $n >= 1 && $n <= 7));

        return [
            'mode'            => $mode,
            'freq'            => $freq,
            'interval_n'      => max(1, min(365, (int)($in['interval_n'] ?? 1))),
            'weekdays'        => $weekdays !== '' ? $weekdays : null,
            'month_mode'      => in_array($freq, ['monthly', 'yearly'], true) ? $monthMode : null,
            // -1 is meaningful (the last day of the month), so the floor is -1.
            'day_of_month'    => self::clampInt($in['day_of_month'] ?? null, -1, 31),
            'nth'             => self::clampInt($in['nth'] ?? null, -1, 5),
            'nth_weekday'     => self::clampInt($in['nth_weekday'] ?? null, 1, 7),
            'month_of_year'   => self::clampInt($in['month_of_year'] ?? null, 1, 12),
            'ends_mode'       => $endsMode,
            'ends_on'         => $endsMode === 'on_date' && !empty($in['ends_on'])
                                    ? substr((string)$in['ends_on'], 0, 10) : null,
            'max_occurrences' => $endsMode === 'after_count'
                                    ? self::clampInt($in['max_occurrences'] ?? null, 1, 1000) : null,
            'copy_description' => !empty($in['copy_description']) ? 1 : 0,
            'copy_subtasks'    => !empty($in['copy_subtasks'])    ? 1 : 0,
            'copy_assignee'    => !empty($in['copy_assignee'])    ? 1 : 0,
            'copy_tags'        => !empty($in['copy_tags'])        ? 1 : 0,
            'copy_links'       => !empty($in['copy_links'])       ? 1 : 0,
            'copy_attachments' => !empty($in['copy_attachments']) ? 1 : 0,
        ];
    }

    /**
     * Every date a rule will produce, for showing somebody BEFORE they commit.
     *
     * "Every second Tuesday of the month, 5 times" is not a sentence most people
     * can turn into dates in their head, and until they can, the only way to
     * find out what a repeat does is to save it and wait. So this walks the rule
     * with the same engine the worker uses and hands back the list.
     *
     * Occurrence 1 is the task in front of you, which is why it carries the
     * task's own dates rather than computed ones — the series is seeded from its
     * due date and it counts towards "5 times".
     *
     * ⚠️ A repeat set to fire AFTER COMPLETION gets one entry and no more. Its
     * next date is counted from the day somebody actually finishes, which has
     * not happened, so a list would be a guess dressed up as a schedule. The
     * caller is told the mode and says so in words instead.
     */
    public static function previewRule(array $rule, array $task, int $limit = 25): array
    {
        $anchor = !empty($task['due_date']) ? substr((string)$task['due_date'], 0, 10) : gmdate('Y-m-d');

        $out = [[
            'n'          => 1,
            'due_date'   => $anchor,
            'start_date' => !empty($task['start_date']) ? substr((string)$task['start_date'], 0, 10) : null,
            'first'      => true,
        ]];

        if (($rule['mode'] ?? 'completion') !== 'schedule') {
            return ['occurrences' => $out, 'truncated' => false];
        }

        $due = $anchor;
        $n   = 1;
        $truncated = false;

        while (true) {
            $next = self::nextDate($rule, $due);
            if ($next === null || $next === $due) break;

            // The same accounting the worker uses, so the list stops where the
            // series will actually stop.
            $probe = $rule;
            $probe['occurrences_created'] = $n;
            if (self::isExhausted($probe, $next)) break;

            if ($n >= $limit) { $truncated = true; break; }

            $n++;
            $out[] = [
                'n'          => $n,
                'due_date'   => $next,
                'start_date' => self::startForDue($task, $next),
                'first'      => false,
            ];
            $due = $next;
        }

        return ['occurrences' => $out, 'truncated' => $truncated];
    }

    /**
     * The day work on an occurrence due $due can BEGIN.
     *
     * A repeat keeps the gap between starting and being due, because "start a
     * week before it is due" is part of the plan rather than an accident of the
     * first occurrence. A fortnight of work due on the 31st therefore starts on
     * the 17th.
     *
     * ⚠️ That gap is also why the worker cannot wait for the due date. An
     * occurrence created on the day it is due would be born a fortnight past the
     * day it should have been started — a two-week bar lying entirely in the
     * past, that nobody ever had a chance to do. See runDue().
     *
     * Returns null when the source has no start date, or none worth keeping.
     * That is a point-in-time task, and it correctly appears on its due date.
     */
    public static function startForDue(array $task, string $due): ?string
    {
        if (empty($task['start_date']) || empty($task['due_date'])) return null;

        // ⚠️ All of this in UTC, and with DateTime rather than arithmetic on
        // timestamps. strtotime() reads a bare date as LOCAL midnight while
        // gmdate() writes UTC, so on an installation east of Greenwich — which
        // includes the UK for seven months of the year — subtracting the gap
        // landed a day early, and landed correctly in winter. A gap spanning a
        // clock change is the same trap from the other end: 14 days is 14 days
        // and an hour, and dividing by 86400 truncates it to 13.
        $tz    = new DateTimeZone('UTC');
        $start = new DateTimeImmutable((string)$task['start_date'], $tz);
        $dueOn = new DateTimeImmutable((string)$task['due_date'], $tz);
        if ($dueOn <= $start) return null;

        $gap = (int)$start->diff($dueOn)->days;
        if ($gap <= 0) return null;

        return (new DateTimeImmutable($due, $tz))->modify("-{$gap} days")->format('Y-m-d');
    }

    private static function taskRow(PDO $conn, int $id): ?array
    {
        $s = $conn->prepare("SELECT * FROM tasks WHERE id = ?");
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function latestOccurrence(PDO $conn, int $recurrenceId): ?int
    {
        $s = $conn->prepare(
            "SELECT id FROM tasks WHERE recurrence_id = ? AND parent_task_id IS NULL
              ORDER BY id DESC LIMIT 1"
        );
        $s->execute([$recurrenceId]);
        $id = $s->fetchColumn();
        return $id ? (int)$id : null;
    }

    /**
     * Copy $sourceTaskId into a new task due on $due, honouring the rule's
     * copy_* flags, and count it against the series.
     *
     * What is NEVER copied: the completed timestamp, and the status. A new
     * occurrence starts open even if the series was completed into some other
     * status - spawning one straight into a closed status would make it look
     * done on arrival, and in completion mode could loop.
     */
    private static function createOccurrence(PDO $conn, int $sourceTaskId, array $rule, string $due): ?int
    {
        $src = $conn->prepare("SELECT * FROM tasks WHERE id = ?");
        $src->execute([$sourceTaskId]);
        $t = $src->fetch(PDO::FETCH_ASSOC);
        if (!$t) return null;

        $statusId = self::defaultOpenStatusId($conn);
        if ($statusId === null) return null;   // no open status configured: do nothing rather than guess

        $start = self::startForDue($t, $due);

        $master = $t['recurrence_master_id'] ?: $t['id'];

        $conn->prepare(
            "INSERT INTO tasks (title, description, status_id, priority_id, start_date, due_date,
                                assigned_analyst_id, assigned_team_id,
                                ticket_id, change_id, contract_id, tenant_id,
                                recurrence_id, recurrence_master_id,
                                board_position, created_by_id, created_datetime, updated_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                     (SELECT * FROM (SELECT COALESCE(MAX(board_position), -1) + 1 FROM tasks
                                      WHERE status_id = ? AND parent_task_id IS NULL) x),
                     ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([
            $t['title'],
            (int)$rule['copy_description'] ? $t['description'] : null,
            $statusId,
            $t['priority_id'],
            $start,
            $due,
            (int)$rule['copy_assignee'] ? $t['assigned_analyst_id'] : null,
            (int)$rule['copy_assignee'] ? $t['assigned_team_id']    : null,
            (int)$rule['copy_links'] ? $t['ticket_id']   : null,
            (int)$rule['copy_links'] ? $t['change_id']   : null,
            (int)$rule['copy_links'] ? $t['contract_id'] : null,
            $t['tenant_id'],
            $rule['id'],
            $master,
            $statusId,
            $t['created_by_id'],
        ]);
        $newId = (int)$conn->lastInsertId();

        if ((int)$rule['copy_tags']) {
            $conn->prepare(
                "INSERT IGNORE INTO task_tag_map (task_id, tag_id)
                 SELECT ?, tag_id FROM task_tag_map WHERE task_id = ?"
            )->execute([$newId, $sourceTaskId]);
        }

        if ((int)$rule['copy_subtasks']) {
            // Titles and assignees carry; a subtask's own completion never does,
            // and neither do its dates - last month's dates mean nothing here.
            $subs = $conn->prepare(
                "SELECT title, assigned_analyst_id, assigned_team_id, priority_id
                   FROM tasks WHERE parent_task_id = ? ORDER BY board_position, id"
            );
            $subs->execute([$sourceTaskId]);
            $ins = $conn->prepare(
                "INSERT INTO tasks (title, status_id, priority_id, assigned_analyst_id, assigned_team_id,
                                    parent_task_id, tenant_id, created_by_id, created_datetime, updated_datetime)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            );
            foreach ($subs->fetchAll(PDO::FETCH_ASSOC) as $s2) {
                $ins->execute([
                    $s2['title'], $statusId, $s2['priority_id'],
                    (int)$rule['copy_assignee'] ? $s2['assigned_analyst_id'] : null,
                    (int)$rule['copy_assignee'] ? $s2['assigned_team_id']    : null,
                    $newId, $t['tenant_id'], $t['created_by_id'],
                ]);
            }
        }

        if ((int)$rule['copy_attachments']) {
            // Links the SAME documents to the new task. It does not duplicate
            // files: one procedure attached to twelve monthly checks should be
            // one document, edited once.
            try {
                $conn->prepare(
                    "INSERT INTO document_links (document_id, parent_type, parent_id, linked_by_id, created_datetime)
                     SELECT document_id, 'task', ?, linked_by_id, UTC_TIMESTAMP()
                       FROM document_links WHERE parent_type = 'task' AND parent_id = ?"
                )->execute([$newId, $sourceTaskId]);
            } catch (Throwable $e) {
                // An install without the documents tables still gets its task.
            }
        }

        // Fresh, unticked checklists for the new occurrence (discussion #138).
        // Always, not behind a copy_* option: a repeating task that HAS a
        // checklist is usually a repeating procedure, and the checklist is the
        // procedure. Does nothing while checklists on tasks are switched off.
        try {
            require_once __DIR__ . '/checklists.php';
            ChecklistsService::copyTaskChecklists($conn, $sourceTaskId, $newId);
        } catch (Throwable $e) {
            error_log('Recurrence: checklist copy failed for task ' . $newId . ': ' . $e->getMessage());
        }

        $conn->prepare(
            "UPDATE task_recurrences
                SET occurrences_created = occurrences_created + 1, updated_datetime = UTC_TIMESTAMP()
              WHERE id = ?"
        )->execute([(int)$rule['id']]);

        return $newId;
    }

    /**
     * The status a new occurrence starts in: the configured default, but only
     * among OPEN statuses.
     *
     * TasksService::lookupDefault deliberately does not filter on is_closed,
     * because an admin who makes a closed status the default has said what they
     * meant. That reasoning does not carry here - a recurrence that arrives
     * already closed is not work anybody will do, and on completion mode it
     * would complete itself.
     */
    private static function defaultOpenStatusId(PDO $conn): ?int
    {
        $id = $conn->query(
            "SELECT id FROM task_statuses WHERE is_active = 1 AND is_closed = 0
              ORDER BY is_default DESC, display_order, id LIMIT 1"
        )->fetchColumn();
        return $id ? (int)$id : null;
    }
}
