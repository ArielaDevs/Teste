<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. This test only READS the database
   and the attachment files, and sends to a fake mail server it starts itself on
   127.0.0.1 - but it still has no business answering an HTTP request. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * GH #158 - an analyst's attachments never reached the customer on an SMTP
 * (basic IMAP) or Gmail mailbox. Both sent text/html alone.
 *
 * What this proves, against the REAL code rather than a copy of it:
 *
 *   1. includes/mime_message.php builds the right shape: plain HTML when there
 *      is nothing else, multipart/related for inline images, multipart/mixed
 *      for attachments - and a browser-supplied content type or file name
 *      cannot add a header of its own.
 *   2. processInlineImages() (api/tickets/send_email.php) finds the pictures in
 *      a REAL quoted thread from this database. Before #158 its pattern matched
 *      "api/get_attachment.php", which no stored email contains, so it
 *      converted nothing on any provider.
 *   3. It refuses a file from ANOTHER ticket, even when the link names it.
 *   4. imapSmtpSend() - the real SMTP client - delivers all of it to a fake
 *      server on 127.0.0.1, and every byte comes back out identical: the
 *      attachment, each inline image, and the CC recipient.
 *   5. A thread's pictures stop at INLINE_THREAD_BUDGET, so a send Graph used to
 *      accept cannot grow past its 4 MB limit and start failing.
 *   6. A pasted screenshot (a data: image) becomes an inline part.
 *
 * It needs an install with at least one inbound email carrying an inline
 * picture (any mailbox) and at least two tickets with attachments; it says
 * SKIP rather than passing when the data is not there.
 *
 * Run: php tests/outbound-email-mime.php
 */

// --- fake SMTP server mode: php outbound-email-mime.php --fake-smtp <port> <outfile>
if (($argv[1] ?? '') === '--fake-smtp') {
    $srv = stream_socket_server('tcp://127.0.0.1:' . (int)$argv[2], $errno, $errstr);
    if (!$srv) { fwrite(STDERR, "listen failed: $errstr\n"); exit(1); }
    echo "ready\n"; fflush(STDOUT);
    $c = stream_socket_accept($srv, 20);
    if (!$c) exit(1);
    $log = ['rcpt' => [], 'data' => ''];
    fwrite($c, "220 fake ESMTP\r\n");
    $inData = false;
    while (($line = fgets($c)) !== false) {
        if ($inData) {
            if ($line === ".\r\n") { $inData = false; fwrite($c, "250 queued\r\n"); continue; }
            if (substr($line, 0, 2) === '..') $line = substr($line, 1); // un-dot-stuff
            $log['data'] .= $line;
            continue;
        }
        $cmd = strtoupper(substr($line, 0, 4));
        if ($cmd === 'EHLO') fwrite($c, "250-fake\r\n250 OK\r\n");
        elseif ($cmd === 'MAIL') fwrite($c, "250 OK\r\n");
        elseif ($cmd === 'RCPT') { preg_match('/<([^>]*)>/', $line, $m); $log['rcpt'][] = $m[1] ?? ''; fwrite($c, "250 OK\r\n"); }
        elseif ($cmd === 'DATA') { $inData = true; fwrite($c, "354 go\r\n"); }
        elseif ($cmd === 'QUIT') { fwrite($c, "221 bye\r\n"); break; }
        else fwrite($c, "250 OK\r\n");
    }
    file_put_contents($argv[3], json_encode($log));
    exit(0);
}

$root = dirname(__DIR__);
require $root . '/config.php';
require_once $root . '/includes/functions.php';
require_once $root . '/includes/ticket_numbering.php';
require_once $root . '/includes/mailbox_imap.php';
require_once $root . '/includes/mime_message.php';

// send_email.php is an endpoint - it runs on include. Load only its functions
// (everything from getMailboxForTicket() down), with __DIR__ pointed back at
// api/tickets so the attachment paths resolve exactly as they do in production.
$src = file_get_contents($root . '/api/tickets/send_email.php');
$at  = strpos($src, 'function getMailboxForTicket');
$at  = strrpos(substr($src, 0, $at), '/**');
eval(str_replace('__DIR__', var_export($root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'tickets', true), substr($src, $at)));

$pass = 0; $fail = 0;
function check($ok, $label) {
    global $pass, $fail;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    $ok ? $pass++ : $fail++;
}

/** Minimal MIME reader: returns every leaf as [headers(lowercased keys), decoded body]. */
function mimeLeaves(string $raw): array {
    [$head, $body] = explode("\r\n\r\n", $raw, 2) + [1 => ''];
    $headers = [];
    foreach (preg_split('/\r\n(?![ \t])/', $head) as $h) {
        if (strpos($h, ':') === false) continue;
        [$k, $v] = explode(':', $h, 2);
        $headers[strtolower(trim($k))] = trim($v);
    }
    $ct = $headers['content-type'] ?? 'text/plain';
    if (stripos($ct, 'multipart/') === 0 && preg_match('/boundary="([^"]+)"/', $ct, $m)) {
        $leaves = [['__multipart' => strtolower(strtok($ct, ';'))] + $headers, null];
        $chunks = explode('--' . $m[1], $body);
        array_shift($chunks);                 // preamble
        array_pop($chunks);                   // "--" epilogue
        $out = [$leaves];
        foreach ($chunks as $ch) {
            $out = array_merge($out, mimeLeaves(substr(rtrim($ch, "\r\n"), 2)));
        }
        return $out;
    }
    $dec = (strtolower($headers['content-transfer-encoding'] ?? '') === 'base64') ? base64_decode($body) : $body;
    return [[$headers, $dec]];
}

$conn = connectToDatabase();

// ---------------------------------------------------------------------------
echo "1. The message builder\n";
$plain = mimeBuildMessage(['from' => 'a@example.test', 'to' => ['b@example.test'], 'subject' => 'Hi', 'html' => '<p>x</p>']);
$l = mimeLeaves($plain);
check(count($l) === 1 && stripos($l[0][0]['content-type'], 'text/html') === 0 && $l[0][1] === '<p>x</p>',
    'nothing attached: a single text/html part, as before #158');

$pdf = "%PDF-1.4 fake \x00\x01\x02 bytes";
$png = base64_decode('iVBORw0KGBgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$msg = mimeBuildMessage([
    'from' => 'a@example.test', 'fromName' => 'Service Desk', 'to' => ['b@example.test'], 'cc' => ['c@example.test'],
    'subject' => 'Rapport – août', 'html' => '<p>see <img src="cid:pic1"></p>',
    'parts' => [
        ['name' => 'Résumé "final".pdf', 'contentType' => "application/pdf\r\nBcc: evil@example.test", 'contentBytes' => base64_encode($pdf)],
        ['name' => 'pic.png', 'contentType' => 'image/png', 'contentBytes' => base64_encode($png), 'contentId' => 'pic1', 'isInline' => true],
    ],
]);
$l = mimeLeaves($msg);
$types = array_map(function ($x) { return $x[0]['__multipart'] ?? strtolower(strtok($x[0]['content-type'], ';')); }, $l);
check($types === ['multipart/mixed', 'multipart/related', 'text/html', 'image/png', 'application/octet-stream'],
    'attachment + inline image: mixed { related { html, image }, file } - got ' . implode(', ', $types));
check(stripos($msg, "\r\nBcc:") === false, 'a content type carrying a line break cannot add a Bcc header');
check($l[4][1] === $pdf, 'the attachment bytes come back identical');
check(strpos($l[4][0]['content-disposition'], "filename*=UTF-8''R%C3%A9sum%C3%A9%20final.pdf") !== false,
    'a UTF-8 file name is carried in full (RFC 2231), quotes removed');
check(($l[3][0]['content-id'] ?? '') === '<pic1>' && $l[3][1] === $png, 'the inline image carries its Content-ID and bytes');
check(strpos($msg, "\r\nCc: c@example.test") !== false, 'the Cc header is written');
check(strpos($msg, 'Subject: =?UTF-8?B?') !== false, 'a non-ASCII subject is encoded');

// ---------------------------------------------------------------------------
echo "2. Pictures in a real quoted thread\n";
$row = $conn->query("SELECT e.id, e.ticket_id FROM emails e
                      WHERE e.direction = 'Inbound' AND e.ticket_id IS NOT NULL
                        AND e.body_content LIKE '%get_attachment.php?cid=%'
                        AND EXISTS (SELECT 1 FROM email_attachments a WHERE a.email_id = e.id)
                      ORDER BY e.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    echo "  SKIP  no inbound email with an inline picture on this install\n";
} else {
    $ticketId = (int)$row['ticket_id'];
    $thread = buildFullEmailBody($conn, $ticketId, '<p>Forwarding this.</p>', 'forward');
    preg_match_all('/get_attachment\.php\?/', $thread, $before);
    $res = processInlineImages($thread, $ticketId);
    preg_match_all('/src="cid:([^"]+)"/', $res['body'], $cids);
    check(count($before[0]) > 0, 'the forwarded thread for ticket ' . $ticketId . ' links ' . count($before[0]) . ' stored picture(s)');
    check(count($cids[1]) === count($before[0]) && !preg_match('/src="[^"]*get_attachment\.php/', $res['body']),
        'every one becomes a cid: reference (' . count($cids[1]) . ') - none left as a link');
    $ids = array_column($res['attachments'], 'contentId');
    check(!array_diff(array_unique($cids[1]), $ids), 'every cid: in the HTML has a matching inline part');

    // Bytes match the file on disk, via the same lookup get_attachment.php uses.
    $okBytes = true;
    foreach ($res['attachments'] as $p) {
        $bytes = base64_decode($p['contentBytes']);
        $hit = $conn->prepare("SELECT a.file_path FROM email_attachments a JOIN emails e ON e.id = a.email_id
                                WHERE e.ticket_id = ? AND a.filename = ?");
        $hit->execute([$ticketId, $p['name']]);
        $found = false;
        foreach ($hit->fetchAll(PDO::FETCH_COLUMN) as $fp) {
            $f = $root . '/tickets/attachments/' . $fp;
            if (is_file($f) && file_get_contents($f) === $bytes) { $found = true; break; }
        }
        $okBytes = $okBytes && $found;
    }
    check($okBytes, 'each inline part is byte-for-byte the stored file');

    // --- 3. another ticket's file is refused
    echo "3. A file from another ticket\n";
    $other = $conn->prepare("SELECT a.id FROM email_attachments a JOIN emails e ON e.id = a.email_id
                              WHERE e.ticket_id <> ? AND e.ticket_id IS NOT NULL ORDER BY a.id LIMIT 1");
    $other->execute([$ticketId]);
    $otherId = $other->fetchColumn();
    if (!$otherId) {
        echo "  SKIP  no attachment on a second ticket\n";
    } else {
        $sneaky = '<p><img src="/api/tickets/get_attachment.php?id=' . (int)$otherId . '"></p>';
        $r = processInlineImages($sneaky, $ticketId);
        check($r['body'] === $sneaky && !$r['attachments'], 'a link to attachment ' . $otherId . ' (another ticket) is left alone, nothing attached');
        $own = $conn->prepare("SELECT a.id FROM email_attachments a JOIN emails e ON e.id = a.email_id WHERE e.ticket_id = ? LIMIT 1");
        $own->execute([$ticketId]);
        $ownId = (int)$own->fetchColumn();
        $r = processInlineImages('<img src="../api/tickets/get_attachment.php?id=' . $ownId . '">', $ticketId);
        check(count($r['attachments']) === 1, 'positive control: the same link to this ticket\'s own attachment ' . $ownId . ' IS embedded');
    }

    // --- 4. round trip through the real SMTP client
    echo "4. Through the real SMTP client to a fake server\n";
    $port = 42500 + random_int(0, 999);
    $out  = tempnam(sys_get_temp_dir(), 'smtp');
    $proc = proc_open([PHP_BINARY, __FILE__, '--fake-smtp', (string)$port, $out], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $ready = trim((string)fgets($pipes[1]));
    $mailbox = ['smtp_server' => '127.0.0.1', 'smtp_port' => $port, 'smtp_encryption' => 'none',
                'smtp_username' => '', 'imap_username' => '', 'target_mailbox' => 'desk@example.test', 'name' => 'Service Desk'];
    $parts = array_merge($res['attachments'], uploadedFileParts([['name' => 'report.pdf', 'type' => 'application/pdf', 'content' => base64_encode($pdf)]]));
    $sendErr = '';
    try {
        imapSmtpSend($mailbox, 'customer@example.test', 'boss@example.test', 'Test', $res['body'], $parts);
    } catch (Exception $e) { $sendErr = $e->getMessage(); }
    proc_close($proc);
    $cap = json_decode((string)@file_get_contents($out), true) ?: [];
    @unlink($out);
    check($ready === 'ready' && $sendErr === '' && !empty($cap['data']), 'sent without error' . ($sendErr ? ": $sendErr" : ''));
    if (!empty($cap['data'])) {
        check($cap['rcpt'] === ['customer@example.test', 'boss@example.test'], 'the CC recipient is delivered to');
        $l = mimeLeaves($cap['data']);
        $top = $l[0][0]['__multipart'] ?? '';
        check($top === 'multipart/mixed', 'the message is multipart/mixed (was text/html in the bug report)');
        $file = array_values(array_filter($l, function ($x) { return stripos($x[0]['content-disposition'] ?? '', 'attachment') === 0; }));
        check(count($file) === 1 && $file[0][1] === $pdf, 'the attached PDF arrives, bytes identical');
        $html = array_values(array_filter($l, function ($x) { return stripos($x[0]['content-type'] ?? '', 'text/html') === 0; }));
        preg_match_all('/src="cid:([^"]+)"/', $html[0][1] ?? '', $sent);
        $partIds = [];
        foreach ($l as $x) {
            if (isset($x[0]['content-id'])) $partIds[trim($x[0]['content-id'], '<>')] = $x[1];
        }
        $allThere = true;
        foreach ($res['attachments'] as $p) {
            $allThere = $allThere && isset($partIds[$p['contentId']]) && $partIds[$p['contentId']] === base64_decode($p['contentBytes']);
        }
        check($sent[1] && !array_diff($sent[1], array_keys($partIds)) && $allThere,
            'every picture the HTML shows travels inside the message (' . count($partIds) . ' inline part(s), bytes identical)');
    }
}

// ---------------------------------------------------------------------------
echo "5. The 2 MB cap on a thread's pictures\n";
// Graph refuses a send over 4 MB; embedding without a cap could make a send that
// used to work fail. Find a ticket whose own files exceed the cap and link them all.
$big = $conn->query("SELECT e.ticket_id, a.id, a.file_path FROM email_attachments a JOIN emails e ON e.id = a.email_id
                      WHERE e.ticket_id IS NOT NULL ORDER BY e.ticket_id, a.id")->fetchAll(PDO::FETCH_ASSOC);
$byTicket = [];
foreach ($big as $b) {
    $f = $root . '/tickets/attachments/' . $b['file_path'];
    if (is_file($f)) $byTicket[$b['ticket_id']][] = [(int)$b['id'], filesize($f)];
}
$capTicket = null;
foreach ($byTicket as $t => $files) {
    // Over the cap in total, but with at least one file that fits under it.
    if (array_sum(array_column($files, 1)) > INLINE_THREAD_BUDGET && min(array_column($files, 1)) < INLINE_THREAD_BUDGET) {
        $capTicket = $t; break;
    }
}
if ($capTicket === null) {
    echo "  SKIP  no ticket holds more than " . INLINE_THREAD_BUDGET . " bytes of files\n";
} else {
    $html = '';
    foreach ($byTicket[$capTicket] as [$id]) $html .= '<img src="/api/tickets/get_attachment.php?id=' . $id . '">';
    $r = processInlineImages($html, (int)$capTicket);
    $embedded = array_sum(array_map(function ($p) { return strlen(base64_decode($p['contentBytes'])); }, $r['attachments']));
    preg_match_all('/get_attachment\.php/', $r['body'], $left);
    check($embedded <= INLINE_THREAD_BUDGET && count($r['attachments']) > 0 && count($left[0]) > 0,
        'ticket ' . $capTicket . ': ' . count($r['attachments']) . ' embedded (' . $embedded . ' bytes, under the cap), ' . count($left[0]) . ' left as links');
}

// ---------------------------------------------------------------------------
echo "6. A pasted screenshot\n";
$r = processInlineImages('<p>Here:</p><img src="data:image/png;base64,' . base64_encode($png) . '" width="10">', 0);
check(count($r['attachments']) === 1 && $r['attachments'][0]['contentType'] === 'image/png'
    && base64_decode($r['attachments'][0]['contentBytes']) === $png
    && preg_match('/<img src="cid:inline_image_1_\d+" width="10">/', $r['body']),
    'a data: image becomes an inline part with a cid: reference');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
