<?php
/**
 * API: Knowledge — write this up (streaming).
 *
 * POST { ticket_id?: int, cluster_id?: int, answers?: string }
 *
 * One endpoint, two entry points. The Tickets module sends a ticket_id (the
 * analyst just solved something and pressed the button); the Knowledge assistant
 * sends a cluster_id (this question has been asked fourteen times). Both run the
 * same engine, because the judgement — is there actually an article here? — is
 * identical either way.
 *
 * SSE events:
 *   verdict {verdict:'article'|'not_enough'}  as soon as the first line lands
 *   text    {delta}                            token by token after that
 *   done    {verdict, questions[], explanation, tokens...}
 *   error   {message}
 *
 * ⚠️ THE VERDICT ARRIVES FIRST AND IS BUFFERED FOR. The model's opening line is
 * a machine-readable verdict, so the front end must not render anything until it
 * has been read and stripped — otherwise the words "VERDICT: ARTICLE" appear at
 * the top of the analyst's draft. Everything up to the first newline is held
 * back here; from then on deltas pass straight through.
 */

session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/rbac.php';
require_once '../../includes/rfp_ai.php';
require_once '../../includes/ai_settings.php';
require_once '../../includes/knowledge/writeup_ai.php';

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) ob_end_flush();
ob_implicit_flush(true);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-transform');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

set_time_limit(0);

function sse_send(string $event, array $data): void
{
    echo "event: {$event}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES) . "\n\n";
    @flush();
}

if (!isset($_SESSION['analyst_id'])) {
    sse_send('error', ['message' => 'Not authenticated']);
    exit;
}
// Writing an article is a Knowledge action wherever the button happens to live,
// so a Tickets-only analyst never gets here even though the button is rendered
// by the Tickets module.
requireModuleAccessJson('knowledge');

$analystId = (int)$_SESSION['analyst_id'];
$input     = json_decode(file_get_contents('php://input'), true) ?: [];
$ticketId  = (int)($input['ticket_id'] ?? 0);
$clusterId = (int)($input['cluster_id'] ?? 0);
$answers   = trim((string)($input['answers'] ?? ''));

if (mb_strlen($answers) > 8000) {
    $answers = mb_substr($answers, 0, 8000);
}
if ($ticketId <= 0 && $clusterId <= 0) {
    sse_send('error', ['message' => 'Nothing to write up']);
    exit;
}

try {
    $conn = connectToDatabase();

    if (!writeupSchemaReady($conn) && $clusterId > 0) {
        sse_send('error', ['message' => 'Run System → Database Verification first.']);
        exit;
    }

    // Its own namespace if configured, otherwise the module's existing Knowledge
    // AI key — see writeupAiConfig().
    $aiCfg = writeupAiConfig($conn);
    if (($aiCfg['api_key'] ?? '') === '') {
        sse_send('error', ['message' => 'The Knowledge assistant is not set up yet. Add a provider and key in Knowledge → Settings → Assistant.']);
        exit;
    }

    $mode          = 'single';
    $otherSubjects = [];
    $clusterCount  = 0;

    if ($clusterId > 0) {
        // A cluster id is a guess away — scope the read.
        [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'c');
        $st = $conn->prepare("SELECT c.* FROM knowledge_gap_clusters c WHERE c.id = ? {$tSql}");
        $st->execute(array_merge([$clusterId], $tArgs));
        $cluster = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cluster) {
            sse_send('error', ['message' => 'Not found']);
            exit;
        }

        // Draft from the RICHEST ticket in the cluster, never the newest. The
        // whole design rests on this: the thin "reset password" tickets prove the
        // question is worth answering, and the one detailed ticket supplies the
        // answer.
        $ticketId = (int)$cluster['best_ticket_id'];
        if ($ticketId <= 0) {
            sse_send('error', ['message' => 'That group has no ticket detailed enough to write from.']);
            exit;
        }
        $clusterCount = (int)$cluster['ticket_count'];
        $mode = 'cluster';

        // The other tickets' subjects go to the model too - never a confidential
        // one's (discussion #62).
        require_once __DIR__ . '/../../includes/ticket_sensitivity.php';
        $st = $conn->prepare(
            "SELECT t.subject FROM knowledge_gap_cluster_tickets ct
               JOIN tickets t ON t.id = ct.ticket_id
              WHERE ct.cluster_id = ? AND ct.ticket_id <> ?" . ticketAiExclusionSql($conn, 't') . "
              LIMIT 15"
        );
        $st->execute([$clusterId, $ticketId]);
        $otherSubjects = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'subject');
    }

    // Both paths converge here, and both are scoped: a ticket reached via a
    // cluster still has to be one this analyst may read.
    if (!analystCanAccessTicket($conn, $analystId, $ticketId)) {
        sse_send('error', ['message' => 'Ticket not found']);
        exit;
    }
    // A confidential ticket is not sent to an AI provider unless Tickets -> Settings
    // says so (discussion #62) - checked here, on the server, whatever the page does.
    require_once __DIR__ . '/../../includes/ticket_sensitivity.php';
    if (!ticketAiAllowed($conn, $ticketId)) {
        sse_send('error', ['message' => TICKET_AI_CONFIDENTIAL_ERROR, 'confidential' => true]);
        exit;
    }

    $bundle = writeupTicketBundle($conn, $ticketId);
    if (!$bundle) {
        sse_send('error', ['message' => 'Ticket not found']);
        exit;
    }

    // Answers to a previous round of questions change the prompt: the analyst's
    // account of what happened outranks our reading of the thread.
    $promptMode = $answers !== '' ? 'answers' : $mode;
    $system     = writeupSystemPrompt($promptMode);
    $userMsg    = $mode === 'cluster'
        ? writeupClusterUserMessage($bundle, $otherSubjects, $clusterCount, $answers)
        : writeupUserMessage($bundle, $answers);

    /* ------------------------------------------------------------------ *
     * Stream, holding back the verdict line
     * ------------------------------------------------------------------ */
    $raw          = '';
    $verdict      = null;
    $headerBuffer = '';

    $emit = function (string $delta) use (&$raw, &$verdict, &$headerBuffer) {
        $raw .= $delta;

        if ($verdict !== null) {
            sse_send('text', ['delta' => $delta]);
            return;
        }

        // Still waiting on the first line. Hold everything until we have it —
        // a partial "VERD" must never reach the analyst's editor.
        $headerBuffer .= $delta;
        $nl = strpos($headerBuffer, "\n");
        if ($nl === false) {
            // Guard against a model that never sends a newline: once the buffer
            // is clearly longer than any verdict line, stop waiting and treat
            // what we have as the answer rather than streaming nothing for ever.
            if (mb_strlen($headerBuffer) < 200) {
                return;
            }
            $nl = mb_strlen($headerBuffer) - 1;
        }

        $parsed  = writeupParseResponse($headerBuffer);
        $verdict = $parsed['verdict'];
        sse_send('verdict', ['verdict' => $verdict]);

        if ($parsed['body'] !== '') {
            sse_send('text', ['delta' => $parsed['body']]);
        }
        $headerBuffer = '';
    };

    $onEvent = function (string $eventType, array $data) use ($emit) {
        if ($eventType === 'text') {
            $emit((string)($data['delta'] ?? ''));
        } elseif ($eventType === 'usage') {
            sse_send('usage', $data);
        }
    };

    $opts = [
        'system'      => $system,
        'user'        => $userMsg,
        'max_tokens'  => 2048,
        // Low, not zero: this is technical writing that must not drift from the
        // ticket, but a little variation reads better than a template.
        'temperature' => 0.2,
    ];

    if ($aiCfg['provider'] === 'anthropic') {
        $resp = rfpAiCallAnthropicStreaming($conn, $opts, $onEvent, [
            'provider'   => 'anthropic',
            'api_key'    => $aiCfg['api_key'],
            'model'      => $aiCfg['model'],
            'verify_ssl' => $aiCfg['verify_ssl'] ? '1' : '0',
        ]);
    } else {
        require_once '../../includes/ai_provider.php';
        $one = aiProviderChat($aiCfg, $opts);
        $onEvent('text', ['delta' => $one['content']]);
        $resp = $one + ['cache_read' => null, 'cache_write' => null];
    }

    // A response short enough to never contain a newline leaves the verdict
    // unresolved — settle it from the complete text rather than reporting
    // nothing at all.
    $final = writeupParseResponse($raw);
    if ($verdict === null) {
        $verdict = $final['verdict'];
        sse_send('verdict', ['verdict' => $verdict]);
        if ($final['body'] !== '') {
            sse_send('text', ['delta' => $final['body']]);
        }
    }

    sse_send('done', [
        'verdict'     => $verdict,
        'questions'   => $verdict === 'not_enough' ? writeupExtractQuestions($final['body']) : [],
        'explanation' => $verdict === 'not_enough' ? writeupExtractExplanation($final['body']) : '',
        'ticket_id'   => $ticketId,
        'ticket_ref'  => $bundle['ref'],
        'cluster_id'  => $clusterId ?: null,
        'richness'    => $bundle['richness'],
        'tokens_in'   => $resp['tokens_in']  ?? null,
        'tokens_out'  => $resp['tokens_out'] ?? null,
        'duration_ms' => $resp['duration_ms'] ?? null,
    ]);

} catch (Throwable $e) {
    sse_send('error', ['message' => $e->getMessage()]);
}
