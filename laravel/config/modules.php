<?php

return [
    'default_status' => 'legacy',
    'definitions' => [
        'tickets' => [
            'title' => 'Tickets',
            'description' => 'Incident and request handling inbox.',
            'legacy_entry' => 'tickets/index.php',
        ],
        'asset-management' => [
            'title' => 'Asset Management',
            'description' => 'Hardware and software asset lifecycle.',
            'legacy_entry' => 'asset-management/index.php',
        ],
        'knowledge' => [
            'title' => 'Knowledge',
            'description' => 'Knowledge base and article workflows.',
            'legacy_entry' => 'knowledge/index.php',
        ],
        'change-management' => [
            'title' => 'Change Management',
            'description' => 'Change planning and CAB flow.',
            'legacy_entry' => 'change-management/index.php',
        ],
        'problem-management' => [
            'title' => 'Problem Management',
            'description' => 'Root-cause and known error workflows.',
            'legacy_entry' => 'problem-management/index.php',
        ],
        'tasks' => [
            'title' => 'Tasks',
            'description' => 'Internal task execution and planning.',
            'legacy_entry' => 'tasks/index.php',
        ],
        'workflow' => [
            'title' => 'Workflow',
            'description' => 'Cross-module automations and triggers.',
            'legacy_entry' => 'workflow/index.php',
        ],
        'cmdb' => [
            'title' => 'CMDB',
            'description' => 'Configuration items and relationships.',
            'legacy_entry' => 'cmdb/index.php',
        ],
        'self-service' => [
            'title' => 'Self Service',
            'description' => 'End-user portal and catalogue.',
            'legacy_entry' => 'self-service/index.php',
        ],
        'watchtower' => [
            'title' => 'Watchtower',
            'description' => 'Unified operational overview dashboard.',
            'legacy_entry' => 'watchtower/index.php',
        ],
        'system' => [
            'title' => 'System',
            'description' => 'Administrative controls and settings.',
            'legacy_entry' => 'system/index.php',
        ],
        'system-wiki' => [
            'title' => 'System Wiki',
            'description' => 'Internal system and codebase wiki.',
            'legacy_entry' => 'system-wiki/index.php',
        ],
    ],
];
