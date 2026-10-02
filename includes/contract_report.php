<?php
/**
 * The equipment report for a contract: what it covers, on one page.
 *
 * ⚠️ ONE RENDERER, THREE DESTINATIONS — the printable page, the emailed copy
 * and (via the same data) the CSV. This is the shape asset handover already
 * uses, and for the same reason: a report that looks different depending on how
 * it left the building is a report people stop trusting.
 *
 * The CSS is a PHP function rather than a .css file because a mail client will
 * not fetch an external stylesheet. It has to be inlined into the message, so
 * it has to be a string.
 */

require_once __DIR__ . '/contract_assets.php';

function contractReportCss(): string
{
    // Deliberately plain and self-contained: no custom properties, no theme.
    // A printed page is white, and a mail client resolves neither var() nor a
    // dark-mode media query reliably. Colours are literal for the same reason.
    return <<<'CSS'
.cr-doc { font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1f2937; font-size: 13px; line-height: 1.5; }
.cr-doc h1 { font-size: 20px; margin: 0 0 4px; color: #111827; }
.cr-sub { color: #6b7280; font-size: 13px; margin: 0 0 18px; }
.cr-meta { border-collapse: collapse; margin: 0 0 20px; }
.cr-meta td { padding: 3px 18px 3px 0; vertical-align: top; }
.cr-meta td:first-child { color: #6b7280; white-space: nowrap; }
.cr-notice { color: #92400e; font-weight: 600; }
.cr-table { border-collapse: collapse; width: 100%; }
.cr-table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 0 10px 6px 0; }
.cr-table td { padding: 7px 10px 7px 0; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
.cr-table tr:last-child td { border-bottom: none; }
.cr-ref { color: #6b7280; }
.cr-empty { color: #6b7280; padding: 14px 0; }
.cr-foot { margin-top: 22px; padding-top: 10px; border-top: 1px solid #e5e7eb; color: #9ca3af; font-size: 11px; }
CSS;
}

/** Extra rules that only matter on paper. */
function contractReportPrintCss(): string
{
    return '@page { margin: 14mm; }'
         . '.cr-noprint { display: none !important; }'
         // A table split across a page break loses its headings, so repeat them.
         . 'thead { display: table-header-group; }'
         . 'tr { page-break-inside: avoid; }';
}

/**
 * The report body. No <html> wrapper — the page and the email each supply their
 * own, because one needs a stylesheet link and the other needs it inlined.
 *
 * @param array $contract Row from `contracts`, plus supplier_name / owner_name.
 * @param array $assets   Rows from contractAssetsFor().
 */
function contractReportBody(array $contract, array $assets): string
{
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

    $title = trim(($contract['contract_number'] ?? '') . ' ' . ($contract['title'] ?? ''));

    $meta = [
        t('contracts.report.supplier')  => $contract['supplier_trading_name'] ?: ($contract['supplier_name'] ?? ''),
        t('contracts.report.owner')     => $contract['owner_name'] ?? '',
        t('contracts.report.starts')    => $contract['contract_start'] ?? '',
        t('contracts.report.ends')      => $contract['contract_end'] ?? '',
    ];

    $rows = '';
    foreach ($meta as $label => $value) {
        if ($value === '' || $value === null) continue;   // an empty row says nothing
        $rows .= '<tr><td>' . $e($label) . '</td><td>' . $e($value) . '</td></tr>';
    }
    // The notice date is the one people miss, so it is never dropped for being
    // empty — "none recorded" is itself worth reading on a report about a
    // contract you may be about to renew by accident.
    $rows .= '<tr><td>' . $e(t('contracts.report.notice_by')) . '</td><td class="cr-notice">'
           . $e($contract['notice_date'] ?: t('contracts.report.no_notice_date')) . '</td></tr>';

    $body = '<div class="cr-doc">'
          . '<h1>' . $e(t('contracts.report.heading')) . '</h1>'
          . '<p class="cr-sub">' . $e($title) . '</p>'
          . '<table class="cr-meta">' . $rows . '</table>';

    if (!$assets) {
        $body .= '<p class="cr-empty">' . $e(t('contracts.report.no_equipment')) . '</p>';
    } else {
        $body .= '<table class="cr-table"><thead><tr>'
               . '<th>' . $e(t('contracts.report.col_equipment')) . '</th>'
               . '<th>' . $e(t('contracts.report.col_type')) . '</th>'
               . '<th>' . $e(t('contracts.report.col_serial')) . '</th>'
               . '<th>' . $e(t('contracts.report.col_tag')) . '</th>'
               . '<th>' . $e(t('contracts.report.col_location')) . '</th>'
               . '<th>' . $e(t('contracts.report.col_reference')) . '</th>'
               . '</tr></thead><tbody>';

        foreach ($assets as $a) {
            $body .= '<tr>'
                   . '<td>' . $e(contractReportAssetName($a)) . '</td>'
                   . '<td>' . $e($a['type_name'] ?? '') . '</td>'
                   . '<td class="cr-ref">' . $e($a['service_tag'] ?? '') . '</td>'
                   . '<td class="cr-ref">' . $e($a['asset_tag'] ?? '') . '</td>'
                   . '<td>' . $e($a['location_name'] ?? '') . '</td>'
                   . '<td>' . $e($a['reference'] ?? '') . '</td>'
                   . '</tr>';
        }
        $body .= '</tbody></table>';
    }

    $body .= '<p class="cr-foot">'
           . $e(t('contracts.report.footer', [
                 'count' => count($assets),
                 'date'  => gmdate('Y-m-d H:i') . ' UTC',
             ]))
           . '</p></div>';

    return $body;
}

/**
 * What to call a piece of equipment on the report.
 *
 * A hostname is the usual answer and a SIM card has none, so the fallbacks
 * matter. Falling through to a bare id would be a line nobody can identify —
 * which on a printed report nobody can then look up either.
 */
function contractReportAssetName(array $a): string
{
    $name = $a['hostname'] ?: ($a['asset_tag'] ?: ($a['service_tag'] ?: trim(
        ($a['manufacturer'] ?? '') . ' ' . ($a['model'] ?? '')
    )));
    return $name !== '' ? $name : ('#' . (int)($a['asset_id'] ?? 0));
}

/**
 * The contract, with the supplier and owner names the report shows.
 * Returns null when there is no such contract.
 */
function contractReportLoad(PDO $conn, int $contractId, ?int $analystId = null): ?array
{
    // A customer contract this analyst cannot see loads as nothing (#153).
    require_once __DIR__ . '/contract_party.php';
    [$partyCols, $partyJoins] = contractPartySql($conn, 'c');
    [$vis, $visParams] = $analystId !== null ? contractVisibilitySql($conn, $analystId, 'c') : ['', []];
    $stmt = $conn->prepare(
        "SELECT c.*, s.legal_name AS supplier_name, s.trading_name AS supplier_trading_name,
                a.full_name AS owner_name, a.email AS owner_email
                $partyCols
           FROM contracts c
      LEFT JOIN suppliers s ON s.id = c.supplier_id
      LEFT JOIN analysts  a ON a.id = c.contract_owner_id
                $partyJoins
          WHERE c.id = ?$vis"
    );
    $stmt->execute(array_merge([$contractId], $visParams));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['party_label'] = contractPartyLabel($row);
    return $row;
}
