<?php
/**
 * LMS competency tests (3.0.0) - the rules, in one place.
 *
 * A TEST describes a role and a list of skills, each with a difficulty, a
 * question count and a format. Its questions come from the QUESTION BANK
 * (lms_ct_questions): approved questions are reused first, and the AI is asked
 * only for the shortfall. Everything the AI writes lands as a DRAFT, and a test
 * cannot be sent while it holds a draft - a wrong "correct" answer is caught by
 * a person, not by a candidate.
 *
 * A SITTING is one candidate's attempt. When it is created, the test is frozen
 * into snapshot_json - the questions, their answers in a shuffled order, and
 * the marks - so editing or hiding a bank question later never changes anybody's
 * result, and two candidates sent the same test are scored on identical papers.
 *
 * TWO FORMATS
 *   choice - four options, one right (marks 1, the rest 0).
 *   graded - the ITIL-style scenario question: four defensible answers worth,
 *            by default, 5 / 3 / 1 / 0. The candidate picks ONE; they never see
 *            the marks.
 * Every question weighs the same in a result: each scores marks-chosen divided
 * by the best marks on offer, so a graded question cannot outweigh a choice one.
 *
 * THE LINK is the credential (like a CSAT survey). Only its SHA-256 hash is
 * stored; the raw link exists once, when it is made. Lost it? Make a new one -
 * which works only until the candidate starts.
 *
 * TIME is enforced by the server: the deadline is started + limit, answers saved
 * after it (plus a short grace for the last click in flight) are refused, and a
 * sitting left open past it is closed as "time up" with what was saved.
 */

require_once __DIR__ . '/../ai_provider.php';

const LMS_CT_DIFFICULTIES = ['beginner', 'intermediate', 'advanced'];
const LMS_CT_FORMATS      = ['choice', 'graded'];
const LMS_CT_STATUSES     = ['draft', 'approved', 'hidden'];

const LMS_CT_MAX_SKILL_QUESTIONS = 20;    // per skill, per generate
const LMS_CT_GRACE_SECONDS       = 60;    // a save already in flight when the clock runs out
const LMS_CT_UNTIMED_HOURS       = 24;    // an untimed sitting still closes eventually

// Settings (system_settings). Own endpoint: api/lms/tests.php.
const LMS_CT_LINK_DAYS      = 'lms_ct_link_days';        // a link not started within N days stops working
const LMS_CT_TIME_LIMIT     = 'lms_ct_time_limit';       // default minutes for a new test (0 = untimed)
const LMS_CT_GRADED_MARKS   = 'lms_ct_graded_marks';     // '5,3,1,0'
const LMS_CT_RETENTION_DAYS = 'lms_ct_retention_days';   // finished sittings are deleted after N days (0 = keep)
const LMS_CT_SHOW_SCORE     = 'lms_ct_show_score';       // '1' shows the candidate their overall score
const LMS_CT_LAST_PURGE     = 'lms_ct_last_purge';

/** The settings, defaults applied. */
function lmsCtSettings(PDO $conn): array
{
    $s = [
        LMS_CT_LINK_DAYS      => '7',
        LMS_CT_TIME_LIMIT     => '45',
        LMS_CT_GRADED_MARKS   => '5,3,1,0',
        LMS_CT_RETENTION_DAYS => '180',
        LMS_CT_SHOW_SCORE     => '0',
        LMS_CT_LAST_PURGE     => '',
    ];
    try {
        $in = implode(',', array_fill(0, count($s), '?'));
        $st = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)");
        $st->execute(array_keys($s));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['setting_value'] !== null && $r['setting_value'] !== '') $s[$r['setting_key']] = (string)$r['setting_value'];
        }
    } catch (Throwable $e) {}
    return $s;
}

function lmsCtSaveSetting(PDO $conn, string $key, string $value): void
{
    $st = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_datetime)
                          VALUES (?, ?, UTC_TIMESTAMP())
                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_datetime = UTC_TIMESTAMP()");
    $st->execute([$key, $value]);
}

/** '5,3,1,0' -> [5,3,1,0]: four whole numbers, best first, the best above zero. Null if unusable. */
function lmsCtParseMarks(string $raw): ?array
{
    $parts = array_map('trim', explode(',', $raw));
    if (count($parts) !== 4) return null;
    $m = [];
    foreach ($parts as $p) {
        if (!preg_match('/^\d{1,3}$/', $p)) return null;
        $m[] = (int)$p;
    }
    rsort($m);
    return $m[0] > 0 ? $m : null;
}

function lmsCtGradedMarks(array $settings): array
{
    return lmsCtParseMarks($settings[LMS_CT_GRADED_MARKS] ?? '') ?? [5, 3, 1, 0];
}

/**
 * Check a question from the editor or the AI. Returns [$clean, null] or [null, 'why'].
 * A choice question has exactly one answer worth anything (stored as 1); a graded
 * one needs a best answer worth more than zero.
 */
function lmsCtValidateQuestion(array $in): array
{
    $skill = trim((string)($in['skill'] ?? ''));
    $diff  = (string)($in['difficulty'] ?? '');
    $fmt   = (string)($in['format'] ?? '');
    $text  = trim((string)($in['question_text'] ?? ''));
    if ($skill === '' || mb_strlen($skill) > 120)            return [null, 'Give the question a skill (up to 120 characters).'];
    if (!in_array($diff, LMS_CT_DIFFICULTIES, true))         return [null, 'Choose a difficulty.'];
    if (!in_array($fmt, LMS_CT_FORMATS, true))               return [null, 'Choose a format.'];
    if ($text === '' || mb_strlen($text) > 4000)             return [null, 'Write the question (up to 4,000 characters).'];

    $answers = [];
    foreach ((array)($in['answers'] ?? []) as $a) {
        $t = trim((string)($a['text'] ?? ''));
        if ($t === '') continue;
        if (mb_strlen($t) > 1000) return [null, 'An answer is longer than 1,000 characters.'];
        $marks = (int)($a['marks'] ?? 0);
        if ($marks < 0 || $marks > 100) return [null, 'Marks must be between 0 and 100.'];
        $answers[] = ['text' => $t, 'marks' => $marks];
    }
    if (count($answers) < 2 || count($answers) > 6) return [null, 'A question needs between 2 and 6 answers.'];

    $scoring = count(array_filter($answers, fn($a) => $a['marks'] > 0));
    if ($fmt === 'choice') {
        if ($scoring !== 1) return [null, 'A multiple-choice question needs exactly one correct answer.'];
        foreach ($answers as &$a) $a['marks'] = $a['marks'] > 0 ? 1 : 0;
        unset($a);
    } elseif ($scoring === 0) {
        return [null, 'A graded question needs a best answer worth more than 0 marks.'];
    }

    $status = (string)($in['status'] ?? 'draft');
    if (!in_array($status, LMS_CT_STATUSES, true)) $status = 'draft';
    $expl = trim((string)($in['explanation'] ?? ''));

    return [[
        'skill' => $skill, 'difficulty' => $diff, 'format' => $fmt,
        'question_text' => $text, 'answers' => $answers,
        'explanation' => $expl !== '' ? mb_substr($expl, 0, 4000) : null,
        'status' => $status,
    ], null];
}

/** The best marks a question offers - what a full score on it means. */
function lmsCtMaxMarks(array $answers): int
{
    $m = 0;
    foreach ($answers as $a) $m = max($m, (int)($a['marks'] ?? 0));
    return $m;
}

/** A bank row with its answers decoded. */
function lmsCtDecodeQuestion(array $r): array
{
    $r['answers'] = json_decode((string)$r['answers_json'], true) ?: [];
    unset($r['answers_json']);
    $r['id'] = (int)$r['id'];
    return $r;
}

/** Insert a validated question into the bank. */
function lmsCtInsertQuestion(PDO $conn, array $q, string $source, ?string $role, ?int $analystId): int
{
    $st = $conn->prepare("INSERT INTO lms_ct_questions
        (skill, difficulty, format, question_text, answers_json, explanation, status, source, role_context, created_by_analyst_id, created_datetime)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
    $st->execute([
        $q['skill'], $q['difficulty'], $q['format'], $q['question_text'],
        json_encode($q['answers'], JSON_UNESCAPED_UNICODE), $q['explanation'], $q['status'], $source,
        $role !== null && $role !== '' ? mb_substr($role, 0, 255) : null, $analystId,
    ]);
    return (int)$conn->lastInsertId();
}

/** The questions on a test, in order, decoded. */
function lmsCtTestQuestions(PDO $conn, int $testId): array
{
    $st = $conn->prepare("SELECT q.*, tq.sort_order
                          FROM lms_ct_test_questions tq
                          JOIN lms_ct_questions q ON q.id = tq.question_id
                          WHERE tq.test_id = ?
                          ORDER BY tq.sort_order, tq.id");
    $st->execute([$testId]);
    return array_map('lmsCtDecodeQuestion', $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Next sort position on a test. */
function lmsCtNextSort(PDO $conn, int $testId): int
{
    $st = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM lms_ct_test_questions WHERE test_id = ?");
    $st->execute([$testId]);
    return (int)$st->fetchColumn() + 1;
}

/** Put bank questions on a test (already-there ones are skipped). Returns how many were added. */
function lmsCtAttach(PDO $conn, int $testId, array $questionIds): int
{
    $sort = lmsCtNextSort($conn, $testId);
    $ins = $conn->prepare("INSERT IGNORE INTO lms_ct_test_questions (test_id, question_id, sort_order) VALUES (?, ?, ?)");
    $n = 0;
    foreach ($questionIds as $qid) {
        $ins->execute([$testId, (int)$qid, $sort++]);
        $n += $ins->rowCount();
    }
    return $n;
}

/**
 * Freeze a test into what a candidate will sit. Throws, with a sentence an
 * analyst can act on, when the test is not ready: no questions, or any that
 * are still drafts or have been hidden since they were added.
 */
function lmsCtBuildSnapshot(PDO $conn, int $testId): array
{
    $st = $conn->prepare("SELECT * FROM lms_ct_tests WHERE id = ?");
    $st->execute([$testId]);
    $test = $st->fetch(PDO::FETCH_ASSOC);
    if (!$test) throw new RuntimeException('Test not found.');

    $qs = lmsCtTestQuestions($conn, $testId);
    if (!$qs) throw new RuntimeException('This test has no questions yet.');
    $drafts = count(array_filter($qs, fn($q) => $q['status'] === 'draft'));
    $hidden = count(array_filter($qs, fn($q) => $q['status'] === 'hidden'));
    if ($drafts) throw new RuntimeException("Approve or remove the {$drafts} draft question(s) first - nothing goes to a candidate unchecked.");
    if ($hidden) throw new RuntimeException("Remove the {$hidden} hidden question(s) from the test first.");

    $out = [];
    foreach ($qs as $q) {
        $answers = $q['answers'];
        // Fisher-Yates with a CSPRNG: where the best answer sits must not be learnable.
        for ($i = count($answers) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$answers[$i], $answers[$j]] = [$answers[$j], $answers[$i]];
        }
        $out[] = [
            'question_id' => $q['id'],
            'skill'       => $q['skill'],
            'difficulty'  => $q['difficulty'],
            'format'      => $q['format'],
            'text'        => $q['question_text'],
            'explanation' => $q['explanation'],
            'answers'     => array_map(fn($a) => ['text' => $a['text'], 'marks' => (int)$a['marks']], $answers),
            'max'         => lmsCtMaxMarks($answers),
        ];
    }
    return [
        'test_id'   => (int)$test['id'],
        'title'     => $test['title'],
        'role'      => $test['role_description'],
        'pass_mark' => $test['pass_mark'] !== null ? (int)$test['pass_mark'] : null,
        'questions' => $out,
    ];
}

/** A new raw token and its hash. The raw one is shown once and never stored. */
function lmsCtNewToken(): array
{
    $raw = bin2hex(random_bytes(32));
    return [$raw, hash('sha256', $raw)];
}

function lmsCtLinkPath(string $rawToken): string
{
    return 'lms/test.php?t=' . $rawToken;
}

/** The sitting a raw token opens, or null. Malformed tokens never reach the database. */
function lmsCtFindSitting(PDO $conn, string $raw): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $raw)) return null;
    $st = $conn->prepare("SELECT * FROM lms_ct_sittings WHERE token_hash = ?");
    $st->execute([hash('sha256', $raw)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** When a started sitting's time runs out (UTC unix time), or null if not started. */
function lmsCtDeadline(array $s): ?int
{
    if (empty($s['started_datetime'])) return null;
    $start = strtotime($s['started_datetime'] . ' UTC');
    $mins  = (int)($s['time_limit_minutes'] ?? 0);
    return $start + ($mins > 0 ? $mins * 60 : LMS_CT_UNTIMED_HOURS * 3600);
}

/**
 * Where a sitting stands: cancelled | submitted | expired (never started, link
 * out of date) | timeup (started, clock run out, not yet closed) | ready | in_progress.
 */
function lmsCtState(array $s, ?int $now = null): string
{
    $now = $now ?? time();
    if ((int)$s['is_cancelled'])          return 'cancelled';
    if (!empty($s['submitted_datetime'])) return 'submitted';
    if (empty($s['started_datetime'])) {
        return strtotime($s['expires_datetime'] . ' UTC') < $now ? 'expired' : 'ready';
    }
    return lmsCtDeadline($s) + LMS_CT_GRACE_SECONDS < $now ? 'timeup' : 'in_progress';
}

/**
 * Score a snapshot against the candidate's answers ({question index: answer index}).
 * Each question counts equally: marks chosen / best marks on offer. Unanswered
 * scores 0. Returns [overall percent, per-skill breakdown].
 */
function lmsCtScore(array $snapshot, array $responses): array
{
    $total = 0.0; $n = 0; $skills = [];
    foreach ($snapshot['questions'] as $i => $q) {
        $pick = $responses[(string)$i] ?? $responses[$i] ?? null;
        $got  = ($pick !== null && isset($q['answers'][(int)$pick])) ? (int)$q['answers'][(int)$pick]['marks'] : 0;
        $frac = $q['max'] > 0 ? $got / $q['max'] : 0;
        $total += $frac; $n++;
        $key = $q['skill'] . '|' . $q['difficulty'];
        if (!isset($skills[$key])) $skills[$key] = ['skill' => $q['skill'], 'difficulty' => $q['difficulty'], 'questions' => 0, 'answered' => 0, 'sum' => 0.0];
        $skills[$key]['questions']++;
        if ($pick !== null) $skills[$key]['answered']++;
        $skills[$key]['sum'] += $frac;
    }
    $out = [];
    foreach ($skills as $k) {
        $out[] = ['skill' => $k['skill'], 'difficulty' => $k['difficulty'], 'questions' => $k['questions'],
                  'answered' => $k['answered'], 'percent' => round($k['sum'] / $k['questions'] * 100, 1)];
    }
    return [$n ? round($total / $n * 100, 1) : 0.0, $out];
}

/** Close a sitting and store its score. Only the first close counts. */
function lmsCtFinalise(PDO $conn, array $s, string $reason): bool
{
    $snap = json_decode((string)$s['snapshot_json'], true) ?: ['questions' => []];
    $resp = json_decode((string)$s['responses_json'], true) ?: [];
    [$pct, $skills] = lmsCtScore($snap, $resp);
    $when = $reason === 'time_up' ? gmdate('Y-m-d H:i:s', (int)lmsCtDeadline($s)) : gmdate('Y-m-d H:i:s');
    $st = $conn->prepare("UPDATE lms_ct_sittings
                          SET submitted_datetime = ?, finish_reason = ?, score_percent = ?, skills_json = ?
                          WHERE id = ? AND submitted_datetime IS NULL");
    $st->execute([$when, $reason, $pct, json_encode($skills, JSON_UNESCAPED_UNICODE), (int)$s['id']]);
    return $st->rowCount() === 1;
}

/** Close every sitting whose clock has run out. Cheap; called whenever results are read. */
function lmsCtFinaliseOverdue(PDO $conn): int
{
    $rows = $conn->query("SELECT * FROM lms_ct_sittings
                          WHERE started_datetime IS NOT NULL AND submitted_datetime IS NULL AND is_cancelled = 0")->fetchAll(PDO::FETCH_ASSOC);
    $n = 0;
    foreach ($rows as $s) {
        if (lmsCtState($s) === 'timeup' && lmsCtFinalise($conn, $s, 'time_up')) $n++;
    }
    return $n;
}

/**
 * Retention: candidates' details are personal data. A sitting that is over -
 * submitted, cancelled, or a link never used and out of date - is deleted once
 * it is older than the retention setting. At most once a day; 0 keeps everything.
 */
function lmsCtPurge(PDO $conn, array $settings, bool $force = false): int
{
    $days = (int)$settings[LMS_CT_RETENTION_DAYS];
    if ($days <= 0) return 0;
    $today = gmdate('Y-m-d');
    if (!$force && $settings[LMS_CT_LAST_PURGE] === $today) return 0;
    $st = $conn->prepare("DELETE FROM lms_ct_sittings
                          WHERE COALESCE(submitted_datetime, expires_datetime) < UTC_TIMESTAMP() - INTERVAL ? DAY
                            AND (submitted_datetime IS NOT NULL OR is_cancelled = 1
                                 OR (started_datetime IS NULL AND expires_datetime < UTC_TIMESTAMP()))");
    $st->execute([$days]);
    lmsCtSaveSetting($conn, LMS_CT_LAST_PURGE, $today);
    return $st->rowCount();
}

/**
 * Ask the AI for $count questions on one skill. Returns validated DRAFTS (not
 * saved). A graded question comes back best-to-worst and is given the install's
 * marks in that order; the candidate sees them shuffled.
 */
function lmsCtGenerate(array $cfg, string $role, string $skill, string $difficulty, string $format, int $count, array $avoid, array $marks): array
{
    $count = max(1, min(LMS_CT_MAX_SKILL_QUESTIONS, $count));
    $level = [
        'beginner'     => 'BEGINNER: someone in their first year - core terms, the everyday task done the standard way.',
        'intermediate' => 'INTERMEDIATE: a few years in - choosing between approaches, diagnosing a realistic fault, knowing the usual pitfalls.',
        'advanced'     => 'ADVANCED: a senior or specialist - trade-offs, unusual failures, design decisions and their consequences.',
    ][$difficulty];

    $system = "You write competency test questions used to assess job candidates in IT. British English. "
            . "Questions must be factually correct, unambiguous, and test practical judgement rather than trivia or wording. "
            . "Never refer to a specific company's internal systems. Level: {$level} "
            // A live run showed the tell every exam writer knows: the right answer
            // was always the longest and most qualified. Shuffling hides its
            // position, not its length.
            . "Keep every question's answers similar in length, detail and tone, so the best one never stands out by being longer, more qualified or more cautious. ";
    if ($format === 'choice') {
        $system .= "Write {$count} multiple-choice questions. Each has exactly four answers, exactly ONE of them correct; "
                 . "the wrong ones must be believable mistakes a less experienced person would make. "
                 . 'Respond ONLY as JSON: {"questions": [{"question": "...", "explanation": "why the right answer is right", '
                 . '"answers": [{"text": "...", "correct": true}, {"text": "...", "correct": false}, ...]}]}';
    } else {
        $system .= "Write {$count} scenario questions in the graded style of the ITIL intermediate exams: a short realistic situation "
                 . "and four answers that are all plausible but not equally good. Order them BEST FIRST: the best and most complete "
                 . "action, a reasonable but weaker one, a poor one, and one that is wrong or harmful. "
                 . 'Respond ONLY as JSON: {"questions": [{"question": "...", "explanation": "why the best answer is best and the others fall short", '
                 . '"answers": ["best", "second", "third", "worst"]}]}';
    }

    $user = "Role being recruited for:\n" . ($role !== '' ? $role : '(not described)') . "\n\nSkill to test: {$skill}";
    if ($avoid) {
        $user .= "\n\nThe bank already has these questions on this skill - do not repeat or closely paraphrase them:\n- "
               . implode("\n- ", array_map(fn($t) => mb_substr($t, 0, 200), array_slice($avoid, 0, 40)));
    }

    $resp = aiProviderChat($cfg, ['system' => $system, 'user' => $user, 'max_tokens' => min(8000, 600 + $count * 450)]);
    return lmsCtParseDrafts((string)($resp['content'] ?? ''), $skill, $difficulty, $format, $marks);
}

/**
 * The model's reply -> validated drafts. Separate from the call so the parsing
 * can be tested against sample replies without spending anything.
 */
function lmsCtParseDrafts(string $content, string $skill, string $difficulty, string $format, array $marks): array
{
    require_once __DIR__ . '/../../api/cmdb/_ai_helpers.php';   // parseClaudeJson()
    try { $draft = parseClaudeJson(trim($content)); }
    catch (Exception $e) { $draft = null; }   // a refusal or prose reply - said plainly below
    if (!$draft || empty($draft['questions']) || !is_array($draft['questions'])) {
        throw new RuntimeException('The AI did not return any usable questions. Try again, or reword the skill.');
    }

    $clean = [];
    foreach ($draft['questions'] as $q) {
        $answers = [];
        if ($format === 'choice') {
            foreach ((array)($q['answers'] ?? []) as $a) {
                $answers[] = ['text' => (string)($a['text'] ?? ''), 'marks' => !empty($a['correct']) ? 1 : 0];
            }
        } else {
            $list = array_values((array)($q['answers'] ?? []));
            if (count($list) !== 4) continue;
            foreach ($list as $i => $a) {
                $answers[] = ['text' => is_array($a) ? (string)($a['text'] ?? '') : (string)$a, 'marks' => $marks[$i]];
            }
        }
        // Anything malformed is dropped, never "fixed" - a choice question with
        // two correct answers is a bad question, not a labelling slip.
        [$ok] = lmsCtValidateQuestion([
            'skill' => $skill, 'difficulty' => $difficulty, 'format' => $format,
            'question_text' => (string)($q['question'] ?? ''), 'explanation' => (string)($q['explanation'] ?? ''),
            'answers' => $answers, 'status' => 'draft',
        ]);
        if ($ok) $clean[] = $ok;
    }
    if (!$clean) throw new RuntimeException('The AI\'s questions did not pass the checks (each needs four answers and a clear best one). Try again.');
    return $clean;
}
