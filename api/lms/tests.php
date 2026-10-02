<?php
/**
 * LMS API: competency tests - tests, the question bank, candidates' sittings
 * and the Competency tests settings tab. Every action sits behind
 * Cap::LMS_TESTS (sensitive: candidates are people outside the organisation).
 * The rules live in includes/lms/competency_tests.php; this file is the door.
 *
 *   GET  ?action=tests | test&id= | bank&... | skills | sittings[&test_id=] | sitting&id= | settings
 *   POST {action: save_test | delete_test | generate | add_questions | remove_question | move_question
 *                 | save_question | set_status | delete_question
 *                 | create_sitting | new_link | cancel_sitting | delete_sitting | save_notes
 *                 | save_settings}
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/ai_settings.php';
require_once '../../includes/lms/competency_tests.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$conn = connectToDatabase();
requireModuleAccessJson('lms', $conn);
requireCapabilityJson(Cap::LMS_TESTS, $conn);
$me = (int)$_SESSION['analyst_id'];

function ctOut(array $a): void { echo json_encode(['success' => true] + $a, JSON_UNESCAPED_UNICODE); exit; }
function ctFail(string $e, int $code = 400): void { http_response_code($code); echo json_encode(['success' => false, 'error' => $e]); exit; }

/** A sitting row as the analyst pages see it - never the token hash. */
function ctSittingRow(array $s): array
{
    return [
        'id'                 => (int)$s['id'],
        'test_id'            => $s['test_id'] !== null ? (int)$s['test_id'] : null,
        'test_title'         => $s['test_title'] ?? (json_decode((string)$s['snapshot_json'], true)['title'] ?? ''),
        'candidate_name'     => $s['candidate_name'],
        'candidate_email'    => $s['candidate_email'],
        'state'              => lmsCtState($s),
        'time_limit_minutes' => $s['time_limit_minutes'] !== null ? (int)$s['time_limit_minutes'] : null,
        'created_datetime'   => $s['created_datetime'],
        'expires_datetime'   => $s['expires_datetime'],
        'started_datetime'   => $s['started_datetime'],
        'submitted_datetime' => $s['submitted_datetime'],
        'finish_reason'      => $s['finish_reason'],
        'score_percent'      => $s['score_percent'] !== null ? (float)$s['score_percent'] : null,
        'skills'             => json_decode((string)$s['skills_json'], true) ?: [],
    ];
}

function ctLoadSitting(PDO $conn, int $id): array
{
    $st = $conn->prepare("SELECT s.*, t.title AS test_title FROM lms_ct_sittings s LEFT JOIN lms_ct_tests t ON t.id = s.test_id WHERE s.id = ?");
    $st->execute([$id]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) ctFail('Candidate not found', 404);
    return $s;
}

try {
    $settings = lmsCtSettings($conn);

    // ================================================================ GET
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';

        if ($action === 'tests') {
            $rows = $conn->query("SELECT t.*,
                    (SELECT COUNT(*) FROM lms_ct_test_questions tq WHERE tq.test_id = t.id) AS question_count,
                    (SELECT COUNT(*) FROM lms_ct_test_questions tq JOIN lms_ct_questions q ON q.id = tq.question_id
                      WHERE tq.test_id = t.id AND q.status <> 'approved') AS unready_count,
                    (SELECT COUNT(*) FROM lms_ct_sittings s WHERE s.test_id = t.id) AS sitting_count,
                    (SELECT GROUP_CONCAT(ts.skill ORDER BY ts.sort_order SEPARATOR ', ') FROM lms_ct_test_skills ts WHERE ts.test_id = t.id) AS skills
                FROM lms_ct_tests t WHERE t.is_archived = 0 ORDER BY COALESCE(t.updated_datetime, t.created_datetime) DESC")->fetchAll(PDO::FETCH_ASSOC);
            ctOut(['tests' => $rows]);
        }

        if ($action === 'test') {
            $id = (int)($_GET['id'] ?? 0);
            $st = $conn->prepare("SELECT * FROM lms_ct_tests WHERE id = ?");
            $st->execute([$id]);
            $t = $st->fetch(PDO::FETCH_ASSOC);
            if (!$t) ctFail('Test not found', 404);
            $sk = $conn->prepare("SELECT * FROM lms_ct_test_skills WHERE test_id = ? ORDER BY sort_order, id");
            $sk->execute([$id]);
            $n = $conn->prepare("SELECT COUNT(*) FROM lms_ct_sittings WHERE test_id = ?");
            $n->execute([$id]);
            ctOut(['test' => $t, 'skills' => $sk->fetchAll(PDO::FETCH_ASSOC), 'questions' => lmsCtTestQuestions($conn, $id),
                   'sitting_count' => (int)$n->fetchColumn(), 'ai_ready' => (aiSettingsLoad($conn, 'lms_ai')['api_key'] ?? '') !== '']);
        }

        if ($action === 'bank') {
            $where = []; $args = [];
            $q = trim((string)($_GET['q'] ?? ''));
            if ($q !== '') {
                $where[] = '(question_text LIKE ? OR skill LIKE ? OR answers_json LIKE ? OR role_context LIKE ?)';
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
                array_push($args, $like, $like, $like, $like);
            }
            foreach (['skill', 'difficulty', 'format'] as $f) {
                $v = trim((string)($_GET[$f] ?? ''));
                if ($v !== '') { $where[] = "$f = ?"; $args[] = $v; }
            }
            $status = (string)($_GET['status'] ?? 'usable');
            if ($status === 'usable')                              $where[] = "status <> 'hidden'";
            elseif (in_array($status, LMS_CT_STATUSES, true))      { $where[] = 'status = ?'; $args[] = $status; }
            $sql = "SELECT q.*, (SELECT COUNT(*) FROM lms_ct_test_questions tq WHERE tq.question_id = q.id) AS used_in
                    FROM lms_ct_questions q" . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
                 . " ORDER BY q.skill, FIELD(q.difficulty,'beginner','intermediate','advanced'), q.id DESC LIMIT 500";
            $st = $conn->prepare($sql);
            $st->execute($args);
            $rows = array_map('lmsCtDecodeQuestion', $st->fetchAll(PDO::FETCH_ASSOC));
            ctOut(['questions' => $rows, 'capped' => count($rows) === 500]);
        }

        if ($action === 'skills') {
            $rows = $conn->query("SELECT skill, COUNT(*) AS n, SUM(status = 'approved') AS approved
                                  FROM lms_ct_questions GROUP BY skill ORDER BY skill")->fetchAll(PDO::FETCH_ASSOC);
            ctOut(['skills' => $rows]);
        }

        if ($action === 'sittings') {
            lmsCtFinaliseOverdue($conn);
            lmsCtPurge($conn, $settings);
            $tid = (int)($_GET['test_id'] ?? 0);
            $sql = "SELECT s.*, t.title AS test_title FROM lms_ct_sittings s LEFT JOIN lms_ct_tests t ON t.id = s.test_id"
                 . ($tid ? ' WHERE s.test_id = ?' : '') . ' ORDER BY s.created_datetime DESC LIMIT 1000';
            $st = $conn->prepare($sql);
            $st->execute($tid ? [$tid] : []);
            ctOut(['sittings' => array_map('ctSittingRow', $st->fetchAll(PDO::FETCH_ASSOC)),
                   'retention_days' => (int)$settings[LMS_CT_RETENTION_DAYS]]);
        }

        if ($action === 'sitting') {
            lmsCtFinaliseOverdue($conn);
            $s = ctLoadSitting($conn, (int)($_GET['id'] ?? 0));
            $snap = json_decode((string)$s['snapshot_json'], true) ?: [];
            $resp = json_decode((string)$s['responses_json'], true) ?: [];
            ctOut(['sitting' => ctSittingRow($s) + ['notes' => $s['notes']], 'snapshot' => $snap, 'responses' => $resp]);
        }

        if ($action === 'settings') {
            ctOut(['settings' => [
                'link_days'      => (int)$settings[LMS_CT_LINK_DAYS],
                'time_limit'     => (int)$settings[LMS_CT_TIME_LIMIT],
                'graded_marks'   => implode(', ', lmsCtGradedMarks($settings)),
                'retention_days' => (int)$settings[LMS_CT_RETENTION_DAYS],
                'show_score'     => $settings[LMS_CT_SHOW_SCORE] === '1',
            ], 'can_email' => (function () use ($conn) {
                require_once '../../includes/self_service_email.php';
                return ssGetSendingMailbox($conn) !== null;
            })()]);
        }

        ctFail('Unknown action');
    }

    // ================================================================ POST
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $in['action'] ?? '';

    // ---- Tests ----
    if ($action === 'save_test') {
        $id    = (int)($in['id'] ?? 0);
        $title = trim((string)($in['title'] ?? ''));
        $role  = trim((string)($in['role_description'] ?? ''));
        if ($title === '' || mb_strlen($title) > 200) ctFail('Give the test a title (up to 200 characters).');
        if (mb_strlen($role) > 8000) ctFail('The role description is too long (8,000 characters at most).');
        $limit = $in['time_limit_minutes'] === '' || $in['time_limit_minutes'] === null ? null : (int)$in['time_limit_minutes'];
        if ($limit !== null && ($limit < 1 || $limit > 600)) $limit = null;
        $pass  = $in['pass_mark'] === '' || $in['pass_mark'] === null ? null : max(0, min(100, (int)$in['pass_mark']));

        $skills = [];
        foreach ((array)($in['skills'] ?? []) as $i => $sk) {
            $name = trim((string)($sk['skill'] ?? ''));
            if ($name === '') continue;
            if (mb_strlen($name) > 120) ctFail('A skill name is longer than 120 characters.');
            $d = (string)($sk['difficulty'] ?? '');
            $f = (string)($sk['format'] ?? '');
            if (!in_array($d, LMS_CT_DIFFICULTIES, true) || !in_array($f, LMS_CT_FORMATS, true)) ctFail("Choose a difficulty and a format for \"{$name}\".");
            $skills[] = ['id' => (int)($sk['id'] ?? 0), 'skill' => $name, 'difficulty' => $d, 'format' => $f,
                         'question_count' => max(1, min(LMS_CT_MAX_SKILL_QUESTIONS, (int)($sk['question_count'] ?? 5)))];
        }

        $conn->beginTransaction();
        if ($id) {
            $st = $conn->prepare("UPDATE lms_ct_tests SET title = ?, role_description = ?, time_limit_minutes = ?, pass_mark = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?");
            $st->execute([$title, $role !== '' ? $role : null, $limit, $pass, $id]);
            $chk = $conn->prepare("SELECT COUNT(*) FROM lms_ct_tests WHERE id = ?");
            $chk->execute([$id]);
            if (!$chk->fetchColumn()) { $conn->rollBack(); ctFail('Test not found', 404); }
        } else {
            $st = $conn->prepare("INSERT INTO lms_ct_tests (title, role_description, time_limit_minutes, pass_mark, created_by_analyst_id, created_datetime) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())");
            $st->execute([$title, $role !== '' ? $role : null, $limit, $pass, $me]);
            $id = (int)$conn->lastInsertId();
        }
        // Skills: update the ones kept (their ids), add new, drop the rest. A
        // dropped skill's questions stay on the test - removing them is a
        // separate, visible choice.
        $keep = [];
        $upd = $conn->prepare("UPDATE lms_ct_test_skills SET skill = ?, difficulty = ?, format = ?, question_count = ?, sort_order = ? WHERE id = ? AND test_id = ?");
        $ins = $conn->prepare("INSERT INTO lms_ct_test_skills (test_id, skill, difficulty, format, question_count, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($skills as $i => $sk) {
            if ($sk['id']) {
                $upd->execute([$sk['skill'], $sk['difficulty'], $sk['format'], $sk['question_count'], $i, $sk['id'], $id]);
                $keep[] = $sk['id'];
            } else {
                $ins->execute([$id, $sk['skill'], $sk['difficulty'], $sk['format'], $sk['question_count'], $i]);
                $keep[] = (int)$conn->lastInsertId();
            }
        }
        $del = $conn->prepare("DELETE FROM lms_ct_test_skills WHERE test_id = ?" . ($keep ? ' AND id NOT IN (' . implode(',', array_map('intval', $keep)) . ')' : ''));
        $del->execute([$id]);
        $conn->commit();
        ctOut(['id' => $id]);
    }

    if ($action === 'delete_test') {
        $id = (int)($in['id'] ?? 0);
        // Candidates' results never depend on the test row (they keep a
        // snapshot), so a test with sittings is archived rather than deleted
        // only to keep the Candidates list able to name it.
        $n = $conn->prepare("SELECT COUNT(*) FROM lms_ct_sittings WHERE test_id = ?");
        $n->execute([$id]);
        if ((int)$n->fetchColumn() > 0) {
            $conn->prepare("UPDATE lms_ct_tests SET is_archived = 1, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$id]);
            ctOut(['archived' => true]);
        }
        $conn->prepare("DELETE FROM lms_ct_test_questions WHERE test_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM lms_ct_test_skills WHERE test_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM lms_ct_tests WHERE id = ?")->execute([$id]);
        ctOut(['archived' => false]);
    }

    // Fill one skill: approved bank questions first, then the AI for the shortfall.
    if ($action === 'generate') {
        $testId  = (int)($in['test_id'] ?? 0);
        $skillId = (int)($in['skill_id'] ?? 0);
        $useBank = !isset($in['use_bank']) || !empty($in['use_bank']);
        $st = $conn->prepare("SELECT ts.*, t.role_description FROM lms_ct_test_skills ts JOIN lms_ct_tests t ON t.id = ts.test_id WHERE ts.id = ? AND ts.test_id = ?");
        $st->execute([$skillId, $testId]);
        $sk = $st->fetch(PDO::FETCH_ASSOC);
        if (!$sk) ctFail('Save the test first, then fill its skills.');

        // What the test already holds for this skill counts towards the target.
        $have = $conn->prepare("SELECT COUNT(*) FROM lms_ct_test_questions tq JOIN lms_ct_questions q ON q.id = tq.question_id
                                WHERE tq.test_id = ? AND q.skill = ? AND q.difficulty = ? AND q.format = ? AND q.status <> 'hidden'");
        $have->execute([$testId, $sk['skill'], $sk['difficulty'], $sk['format']]);
        $need = (int)$sk['question_count'] - (int)$have->fetchColumn();
        if ($need <= 0) ctOut(['from_bank' => 0, 'generated' => 0, 'message' => 'This skill already has its questions.']);

        $fromBank = 0;
        if ($useBank) {
            $pick = $conn->prepare("SELECT q.id FROM lms_ct_questions q
                                    WHERE q.skill = ? AND q.difficulty = ? AND q.format = ? AND q.status = 'approved'
                                      AND q.id NOT IN (SELECT question_id FROM lms_ct_test_questions WHERE test_id = ?)
                                    ORDER BY RAND() LIMIT " . (int)$need);
            $pick->execute([$sk['skill'], $sk['difficulty'], $sk['format'], $testId]);
            $fromBank = lmsCtAttach($conn, $testId, $pick->fetchAll(PDO::FETCH_COLUMN));
            $need -= $fromBank;
        }

        $generated = 0;
        if ($need > 0) {
            $cfg = aiSettingsLoad($conn, 'lms_ai');
            if (($cfg['api_key'] ?? '') === '') {
                ctOut(['from_bank' => $fromBank, 'generated' => 0,
                       'message' => "The bank had {$fromBank}; {$need} more are needed and LMS AI is not set up (LMS → Settings → LMS AI). Write them by hand, or set up the AI."]);
            }
            $av = $conn->prepare("SELECT question_text FROM lms_ct_questions WHERE skill = ? ORDER BY id DESC LIMIT 40");
            $av->execute([$sk['skill']]);
            $drafts = lmsCtGenerate($cfg, (string)$sk['role_description'], $sk['skill'], $sk['difficulty'], $sk['format'],
                                    $need, $av->fetchAll(PDO::FETCH_COLUMN), lmsCtGradedMarks($settings));
            $ids = [];
            foreach (array_slice($drafts, 0, $need) as $d) {
                $ids[] = lmsCtInsertQuestion($conn, $d, 'ai', mb_substr((string)$sk['role_description'], 0, 255), $me);
            }
            $generated = lmsCtAttach($conn, $testId, $ids);
        }
        $conn->prepare("UPDATE lms_ct_tests SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$testId]);
        ctOut(['from_bank' => $fromBank, 'generated' => $generated]);
    }

    if ($action === 'add_questions') {
        $testId = (int)($in['test_id'] ?? 0);
        $chk = $conn->prepare("SELECT COUNT(*) FROM lms_ct_tests WHERE id = ?");
        $chk->execute([$testId]);
        if (!$chk->fetchColumn()) ctFail('Test not found', 404);
        $ids = array_values(array_filter(array_map('intval', (array)($in['question_ids'] ?? []))));
        if ($ids) {
            // Hidden questions are kept out of tests; the bank's own filter hides
            // them, and this refuses one posted by hand.
            $in_ = implode(',', array_fill(0, count($ids), '?'));
            $ok = $conn->prepare("SELECT id FROM lms_ct_questions WHERE id IN ($in_) AND status <> 'hidden'");
            $ok->execute($ids);
            $ids = $ok->fetchAll(PDO::FETCH_COLUMN);
        }
        ctOut(['added' => lmsCtAttach($conn, $testId, $ids)]);
    }

    if ($action === 'remove_question') {
        $st = $conn->prepare("DELETE FROM lms_ct_test_questions WHERE test_id = ? AND question_id = ?");
        $st->execute([(int)($in['test_id'] ?? 0), (int)($in['question_id'] ?? 0)]);
        ctOut(['removed' => $st->rowCount()]);
    }

    if ($action === 'move_question') {
        $testId = (int)($in['test_id'] ?? 0);
        $order  = array_values(array_map('intval', (array)($in['order'] ?? [])));
        $st = $conn->prepare("UPDATE lms_ct_test_questions SET sort_order = ? WHERE test_id = ? AND question_id = ?");
        foreach ($order as $i => $qid) $st->execute([$i + 1, $testId, $qid]);
        ctOut([]);
    }

    // ---- The bank ----
    if ($action === 'save_question') {
        [$q, $err] = lmsCtValidateQuestion((array)($in['question'] ?? []));
        if ($err) ctFail($err);
        $id = (int)($in['question']['id'] ?? 0);
        if ($id) {
            $st = $conn->prepare("UPDATE lms_ct_questions SET skill = ?, difficulty = ?, format = ?, question_text = ?, answers_json = ?,
                                  explanation = ?, status = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?");
            $st->execute([$q['skill'], $q['difficulty'], $q['format'], $q['question_text'],
                          json_encode($q['answers'], JSON_UNESCAPED_UNICODE), $q['explanation'], $q['status'], $id]);
        } else {
            $id = lmsCtInsertQuestion($conn, $q, 'manual', null, $me);
            if (!empty($in['test_id'])) lmsCtAttach($conn, (int)$in['test_id'], [$id]);
        }
        ctOut(['id' => $id]);
    }

    if ($action === 'set_status') {
        $status = (string)($in['status'] ?? '');
        if (!in_array($status, LMS_CT_STATUSES, true)) ctFail('Unknown status');
        $ids = array_values(array_filter(array_map('intval', (array)($in['ids'] ?? []))));
        if (!$ids) ctFail('Nothing selected');
        $in_ = implode(',', array_fill(0, count($ids), '?'));
        $st = $conn->prepare("UPDATE lms_ct_questions SET status = ?, updated_datetime = UTC_TIMESTAMP() WHERE id IN ($in_)");
        $st->execute(array_merge([$status], $ids));
        ctOut(['changed' => $st->rowCount()]);
    }

    if ($action === 'delete_question') {
        // Only a draft is deleted outright - a rejected AI question. An approved
        // one may sit in old results' history, so it is hidden instead.
        $st = $conn->prepare("DELETE FROM lms_ct_questions WHERE id = ? AND status = 'draft'");
        $st->execute([(int)($in['id'] ?? 0)]);
        if (!$st->rowCount()) ctFail('Only a draft question can be deleted - hide an approved one instead.');
        ctOut([]);
    }

    // ---- Candidates ----
    if ($action === 'create_sitting') {
        $testId = (int)($in['test_id'] ?? 0);
        $name   = trim((string)($in['candidate_name'] ?? ''));
        $email  = trim((string)($in['candidate_email'] ?? ''));
        if ($name === '' || mb_strlen($name) > 200) ctFail('Give the candidate\'s name.');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) ctFail('That email address does not look right.');
        try { $snap = lmsCtBuildSnapshot($conn, $testId); }
        catch (RuntimeException $e) { ctFail($e->getMessage()); }

        $t = $conn->prepare("SELECT time_limit_minutes FROM lms_ct_tests WHERE id = ?");
        $t->execute([$testId]);
        $limit = $t->fetchColumn();
        [$raw, $hash] = lmsCtNewToken();
        $days = max(1, (int)$settings[LMS_CT_LINK_DAYS]);
        $st = $conn->prepare("INSERT INTO lms_ct_sittings (test_id, candidate_name, candidate_email, token_hash, snapshot_json,
                              time_limit_minutes, expires_datetime, created_by_analyst_id, created_datetime)
                              VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL ? DAY, ?, UTC_TIMESTAMP())");
        $st->execute([$testId, $name, $email !== '' ? $email : null, $hash, json_encode($snap, JSON_UNESCAPED_UNICODE),
                      $limit !== null && $limit !== false ? (int)$limit : null, $days, $me]);
        $sid = (int)$conn->lastInsertId();

        require_once '../../includes/public_url.php';
        $link = publicAbsoluteUrl($conn, lmsCtLinkPath($raw));
        $emailed = null;
        if (!empty($in['send_email']) && $email !== '') {
            require_once '../../includes/self_service_email.php';
            $sys  = htmlspecialchars(systemName());
            $mins = $limit ? (int)$limit . ' minutes' : 'no time limit';
            $body = '<p>Hello ' . htmlspecialchars($name) . ',</p>'
                  . '<p>You have been invited to sit a short competency test: <strong>' . htmlspecialchars($snap['title']) . '</strong> '
                  . '(' . count($snap['questions']) . ' questions, ' . $mins . ').</p>'
                  . '<p><a href="' . htmlspecialchars($link) . '">Open the test</a></p>'
                  . '<p>The link is for you only and can be used once. It stays open for ' . $days . ' day(s); '
                  . 'the clock starts only when you press Start.</p><p>' . $sys . '</p>';
            $emailed = ssSendSystemEmail($conn, $email, 'Your competency test: ' . $snap['title'], $body, 'training');
        }
        ctOut(['id' => $sid, 'link' => $link, 'emailed' => $emailed, 'expires_days' => $days]);
    }

    if ($action === 'new_link') {
        $s = ctLoadSitting($conn, (int)($in['id'] ?? 0));
        if (!empty($s['started_datetime']) || (int)$s['is_cancelled']) ctFail('This candidate has already started (or was cancelled), so the link cannot be replaced.');
        [$raw, $hash] = lmsCtNewToken();
        $days = max(1, (int)$settings[LMS_CT_LINK_DAYS]);
        $conn->prepare("UPDATE lms_ct_sittings SET token_hash = ?, expires_datetime = UTC_TIMESTAMP() + INTERVAL ? DAY WHERE id = ? AND started_datetime IS NULL")
             ->execute([$hash, $days, (int)$s['id']]);
        require_once '../../includes/public_url.php';
        ctOut(['link' => publicAbsoluteUrl($conn, lmsCtLinkPath($raw)), 'expires_days' => $days]);
    }

    if ($action === 'cancel_sitting') {
        $conn->prepare("UPDATE lms_ct_sittings SET is_cancelled = 1 WHERE id = ? AND submitted_datetime IS NULL")->execute([(int)($in['id'] ?? 0)]);
        ctOut([]);
    }

    if ($action === 'delete_sitting') {
        $st = $conn->prepare("DELETE FROM lms_ct_sittings WHERE id = ?");
        $st->execute([(int)($in['id'] ?? 0)]);
        ctOut(['deleted' => $st->rowCount()]);
    }

    if ($action === 'save_notes') {
        $notes = trim((string)($in['notes'] ?? ''));
        $conn->prepare("UPDATE lms_ct_sittings SET notes = ? WHERE id = ?")->execute([$notes !== '' ? mb_substr($notes, 0, 8000) : null, (int)($in['id'] ?? 0)]);
        ctOut([]);
    }

    // ---- Settings ----
    if ($action === 'save_settings') {
        $link  = (int)($in['link_days'] ?? 0);
        $limit = (int)($in['time_limit'] ?? -1);
        $ret   = (int)($in['retention_days'] ?? -1);
        $marks = lmsCtParseMarks((string)($in['graded_marks'] ?? ''));
        if ($link < 1 || $link > 90)      ctFail('Links stay open: choose between 1 and 90 days.');
        if ($limit < 0 || $limit > 600)   ctFail('Time limit: 0 (untimed) up to 600 minutes.');
        if ($ret < 0 || $ret > 3650)      ctFail('Keep results: 0 (forever) up to 3,650 days.');
        if (!$marks)                      ctFail('Graded marks: four whole numbers separated by commas, best first, e.g. 5, 3, 1, 0.');
        lmsCtSaveSetting($conn, LMS_CT_LINK_DAYS, (string)$link);
        lmsCtSaveSetting($conn, LMS_CT_TIME_LIMIT, (string)$limit);
        lmsCtSaveSetting($conn, LMS_CT_RETENTION_DAYS, (string)$ret);
        lmsCtSaveSetting($conn, LMS_CT_GRADED_MARKS, implode(',', $marks));
        lmsCtSaveSetting($conn, LMS_CT_SHOW_SCORE, !empty($in['show_score']) ? '1' : '0');
        ctOut(['graded_marks' => implode(', ', $marks)]);
    }

    ctFail('Unknown action');
} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    error_log('api/lms/tests.php: ' . $e->getMessage());
    // AI and rule failures read as sentences and are shown; a database error
    // can carry table and column names, so it is logged, never shown.
    $msg = ($e instanceof PDOException || $e->getMessage() === '') ? 'Something went wrong' : $e->getMessage();
    echo json_encode(['success' => false, 'error' => $msg]);
}
