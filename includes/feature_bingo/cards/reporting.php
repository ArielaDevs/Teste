<?php
/** Feature Bingo cards - Reporting: the Intune dashboard and the email import log. The module has no settings page and the ticket dashboards are not built yet. See includes/feature_bingo.php for the format. */
return [
    [
        'id'       => 'reporting.email_import_log',
        'module'   => 'reporting',
        'tier'     => 'extra',
        'category' => 'insight',
        'title'    => 'Email import log',
        'what'     => 'A record of every email FreeITSM has turned into a ticket or a reply - sender, subject, type and attachments - viewable under Reporting, Logs.',
        'why'      => 'When a user says "I emailed you and nothing happened", the log shows whether the message arrived and what it became.',
        'done'     => 'At least one email import has been logged, which happens once a mailbox is collecting mail.',
        'link'     => 'reporting/logs/',
        'check'    => ['rows', 'system_logs', "log_type = 'email_import'"],
    ],
];
