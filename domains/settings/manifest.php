<?php
/**
 * Domains — settings manifest.
 *
 * THE single declaration of this module's settings tabs, and therefore of its
 * capabilities. The tab bar, the tick-boxes on System → Roles and their descriptions
 * are all derived from this file. See includes/capabilities.php.
 *
 * ⚠️ NO 'setting_keys' ON ANY TAB, deliberately. Every setting here is saved through
 * api/domains/settings.php, which validates the value and checks the SAME capability
 * as the tab — the LMS reminders pattern. Declaring the keys here as well would let
 * the generic api/settings/save_system_settings.php write them with no validation
 * (a days list of "banana", a recipient mode that does not exist).
 */

require_once __DIR__ . '/../../includes/capabilities.php';

return [
    'module' => 'domains',
    'label'  => 'Domains',

    'umbrella' => [
        'cap'       => Cap::DOMAINS_MANAGE,
        'grant'     => 'Manage everything in Domains settings',
        'sensitive' => true,   // implies the auth codes
    ],

    'tabs' => [
        [
            'id'        => 'statuses',
            'cap'       => Cap::DOMAINS_STATUSES,
            'label_key' => 'domains.settings.tab_statuses',
            'grant'     => 'Maintain the list of domain statuses',
        ],
        [
            'id'        => 'alerts',
            'cap'       => Cap::DOMAINS_ALERTS,
            'label_key' => 'domains.settings.tab_alerts',
            'grant'     => 'Configure expiry, certificate and change alerts, and where renewals are shown',
            'sensitive' => true,   // decides who is emailed, and can raise tickets and tasks
        ],
        [
            'id'        => 'monitoring',
            'cap'       => Cap::DOMAINS_MONITORING,
            'label_key' => 'domains.settings.tab_monitoring',
            'grant'     => 'Configure registry lookups, DNS and certificate checks, and look-alike scanning',
        ],
        [
            'id'        => 'auth-codes',
            'cap'       => Cap::DOMAINS_AUTH_CODES,
            'label_key' => 'domains.settings.tab_auth_codes',
            'grant'     => 'See and change registrar auth codes (the secret that lets a domain be transferred away)',
            'sensitive' => true,
        ],
    ],
];
