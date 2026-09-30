<?php
/**
 * Build a raw RFC 2822 / MIME email — shared by the two send paths that hand
 * the provider a finished message: SMTP (basic IMAP mailboxes) and the Gmail
 * API. Microsoft Graph takes JSON instead and builds its own MIME.
 *
 * $parts is a list of files in the SAME shape the Graph path already uses
 * (buildEmailMessage() in api/tickets/send_email.php), so one list feeds all
 * three providers:
 *   ['name' => 'report.pdf', 'contentType' => 'application/pdf',
 *    'contentBytes' => '<base64>', 'contentId' => 'x', 'isInline' => true]
 * A part with isInline + contentId is an image the HTML shows via src="cid:x";
 * anything else is an ordinary attachment.
 *
 * Shape of the result, only as deep as it needs to be:
 *   no parts            -> text/html                       (what was always sent)
 *   inline images only  -> multipart/related { html, images }
 *   attachments         -> multipart/mixed { html | related, files }
 *
 * GH #158: before this, SMTP and Gmail sent text/html only, so an analyst's
 * attachment appeared on the ticket and never reached the customer.
 */

/** RFC 2047 encode a header value if it contains non-ASCII. */
function mimeEncodeHeader(string $value): string {
    $value = str_replace(["\r", "\n"], ' ', $value);
    if (preg_match('/[\x80-\xFF]/', $value)) {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
    return $value;
}

/**
 * A globally unique Message-ID on the sender's own domain, e.g.
 * <3f9c...e1.1790805999@example.com>. Falls back to a fixed domain if the
 * From address has none we can use.
 */
function mimeMessageId(string $from): string {
    $domain = strtolower((string)substr(strrchr($from, '@') ?: '', 1));
    if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain)) {
        $domain = 'freeitsm.local';
    }
    return '<' . bin2hex(random_bytes(12)) . '.' . time() . '@' . $domain . '>';
}

/**
 * @param array $m from, fromName, to (list), cc (list), subject, html, parts (list),
 *                 envelope (bool: add Date + Message-ID - SMTP only, see below)
 */
function mimeBuildMessage(array $m): string {
    $from     = (string)($m['from'] ?? '');
    $fromName = (string)($m['fromName'] ?? '');
    $to       = $m['to'] ?? [];
    $cc       = $m['cc'] ?? [];

    $headers = [];
    if ($from !== '') {
        $headers[] = 'From: ' . ($fromName !== '' ? mimeEncodeHeader($fromName) . ' <' . $from . '>' : $from);
    }
    $headers[] = 'To: ' . implode(', ', $to);
    if (!empty($cc)) {
        $headers[] = 'Cc: ' . implode(', ', $cc);
    }
    $headers[] = 'Subject: ' . mimeEncodeHeader((string)($m['subject'] ?? ''));
    // Date and Message-ID are required by RFC 5322, and spam filters score a
    // message without them. Only when asked: the Gmail API stamps both itself
    // (seen on real sent copies), so only SMTP passes 'envelope'.
    if (!empty($m['envelope'])) {
        $headers[] = 'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000';
        $headers[] = 'Message-ID: ' . mimeMessageId($from);
    }
    $headers[] = 'MIME-Version: 1.0';

    $inline = [];
    $files  = [];
    foreach ($m['parts'] ?? [] as $p) {
        if (!empty($p['isInline']) && !empty($p['contentId'])) {
            $inline[] = $p;
        } else {
            $files[] = $p;
        }
    }

    $body = mimeHtmlPart((string)($m['html'] ?? ''));
    if ($inline) {
        $body = mimeMultipart('related', array_merge([$body], array_map('mimeFilePart', $inline)), 'type="text/html"');
    }
    if ($files) {
        $body = mimeMultipart('mixed', array_merge([$body], array_map('mimeFilePart', $files)));
    }

    // $body is [headers, content]; its headers become the message's own.
    return implode("\r\n", array_merge($headers, $body[0])) . "\r\n\r\n" . $body[1];
}

/** The HTML body as a [headers, content] entity. */
function mimeHtmlPart(string $html): array {
    return [
        ['Content-Type: text/html; charset=UTF-8', 'Content-Transfer-Encoding: base64'],
        rtrim(chunk_split(base64_encode($html), 76, "\r\n")),
    ];
}

/** One file (inline image or attachment) as a [headers, content] entity. */
function mimeFilePart(array $p): array {
    $raw = base64_decode(preg_replace('/\s+/', '', (string)($p['contentBytes'] ?? '')), true);
    $name = (string)($p['name'] ?? '');
    if ($raw === false) {
        throw new Exception('The attachment "' . $name . '" could not be read.');
    }

    // Only a plain type/subtype reaches the header — nothing a browser-supplied
    // value could use to add headers of its own.
    $type = strtolower((string)($p['contentType'] ?? ''));
    if (!preg_match('#^[a-z0-9][a-z0-9!\#$&^_.+-]*/[a-z0-9][a-z0-9!\#$&^_.+-]*$#', $type)) {
        $type = 'application/octet-stream';
    }

    // Filename: an ASCII fallback everyone understands, plus RFC 2231 for the
    // real UTF-8 name when it has anything outside plain ASCII.
    $name = str_replace(["\r", "\n", '"', '\\'], '', $name);
    if ($name === '') {
        $name = 'attachment';
    }
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name);
    $fileParam = 'filename="' . $ascii . '"';
    if ($ascii !== $name) {
        $fileParam .= "; filename*=UTF-8''" . rawurlencode($name);
    }

    $isInline = !empty($p['isInline']) && !empty($p['contentId']);
    $headers = [
        'Content-Type: ' . $type . '; name="' . $ascii . '"',
        'Content-Transfer-Encoding: base64',
        'Content-Disposition: ' . ($isInline ? 'inline' : 'attachment') . '; ' . $fileParam,
    ];
    if ($isInline) {
        $headers[] = 'Content-ID: <' . preg_replace('/[<>\r\n\s]/', '', (string)$p['contentId']) . '>';
    }
    return [$headers, rtrim(chunk_split(base64_encode($raw), 76, "\r\n"))];
}

/** Wrap [headers, content] entities in a multipart/<subtype> entity. */
function mimeMultipart(string $subtype, array $entities, string $extraParams = ''): array {
    $boundary = '=_freeitsm_' . bin2hex(random_bytes(12));
    $out = '';
    foreach ($entities as [$h, $c]) {
        $out .= '--' . $boundary . "\r\n" . implode("\r\n", $h) . "\r\n\r\n" . $c . "\r\n";
    }
    $out .= '--' . $boundary . '--';
    $ct = 'Content-Type: multipart/' . $subtype . '; boundary="' . $boundary . '"'
        . ($extraParams !== '' ? '; ' . $extraParams : '');
    return [[$ct], $out];
}
