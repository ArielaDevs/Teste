<?php
/** Feature Bingo cards - System Wiki: the codebase scan that fills it, and keeping it current. See includes/feature_bingo.php for the format. */
return [
    [
        'id'       => 'wiki.first_scan',
        'module'   => 'wiki',
        'tier'     => 'extra',
        'category' => 'insight',
        'title'    => 'Scan the codebase',
        'what'     => 'Running the System Wiki scanner, which catalogues every file, function, class and database table FreeITSM uses so they can be browsed and searched.',
        'why'      => 'If you customise FreeITSM or support it in-house, the wiki answers "which pages touch this table?" or "where is this function called?" without reading the source by hand.',
        'done'     => 'At least one System Wiki scan has completed.',
        'link'     => 'system-wiki/scan.php',
        'check'    => ['rows', 'wiki_scan_runs', "status = 'completed'"],
    ],
    [
        'id'       => 'wiki.recent_scan',
        'module'   => 'wiki',
        'tier'     => 'extra',
        'category' => 'insight',
        'title'    => 'Keep the wiki current',
        'what'     => 'Re-running the scan after an upgrade, or on a schedule with the PowerShell scanner, so the wiki matches the code you are actually running.',
        'why'      => 'A catalogue of last year\'s code sends you to functions that have moved or no longer exist. A recent scan keeps the answers trustworthy.',
        'done'     => 'A System Wiki scan has completed in the last 30 days.',
        'link'     => 'system-wiki/scan.php',
        'check'    => ['rows', 'wiki_scan_runs', "status = 'completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"],
    ],
];
