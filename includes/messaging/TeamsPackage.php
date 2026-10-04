<?php
/**
 * Builds the Microsoft Teams app package (a zip) for one Teams channel, ready to
 * upload in Teams Admin Center → Manage apps. The package is generated from the
 * channel's own App ID, so the admin never has to hand-edit a manifest.
 *
 * Zip layout, at the root (Teams rejects a package with a folder in front):
 *   manifest.json, color.png (192×192), outline.png (32×32, transparent)
 */

require_once __DIR__ . '/messaging.php';

/**
 * Write the package for $channel to $outPath. Returns the zip's file name.
 * Throws with a message the admin can act on when the channel is not ready.
 */
function teamsPackageBuild(PDO $conn, array $channel, string $outPath): string
{
    $appId = trim((string)($channel['credentials']['app_id'] ?? ''));
    if (!preg_match('/^[0-9a-fA-F-]{36}$/', $appId)) {
        throw new Exception('Save the channel with its Bot App ID first — the package needs it.');
    }
    $icons = [
        'color.png'   => dirname(__DIR__, 2) . '/assets/teams/color.png',
        'outline.png' => dirname(__DIR__, 2) . '/assets/teams/outline.png',
    ];
    foreach ($icons as $f) {
        if (!is_file($f)) {
            throw new Exception('The Teams icon files are missing from this install (assets/teams).');
        }
    }

    $name  = trim((string)($channel['name'] ?? '')) ?: 'Service desk';
    $base  = rtrim(messagingPublicBaseUrl($conn), '/');
    $short = mb_substr($name, 0, 30);

    // Microsoft requires https URLs for these. Point them at the install's own
    // root until the admin supplies real ones; Teams validation rejects http.
    $manifest = [
        '$schema'         => 'https://developer.microsoft.com/en-us/json-schemas/teams/v1.17/MicrosoftTeams.schema.json',
        'manifestVersion' => '1.17',
        'version'         => '1.0.0',
        'id'              => $appId,
        'packageName'     => 'com.freeitsm.' . strtolower(preg_replace('/[^a-z0-9]+/i', '', $appId)),
        'developer'       => [
            'name'           => $short,
            'websiteUrl'     => $base . '/',
            'privacyUrl'     => $base . '/',
            'termsOfUseUrl'  => $base . '/',
        ],
        'name'            => ['short' => $short, 'full' => mb_substr($name, 0, 100)],
        'description'     => [
            'short' => 'Chat with the service desk',
            'full'  => 'Chat with the service desk. Each message becomes a ticket, and the service desk replies here.',
        ],
        'icons'           => ['outline' => 'outline.png', 'color' => 'color.png'],
        'accentColor'     => '#4F6BED',
        'bots'            => [[
            'botId'              => $appId,
            'scopes'             => ['personal'],
            'supportsFiles'      => false,
            'isNotificationOnly' => false,
        ]],
        'permissions'     => ['identity'],
        'validDomains'    => [],
    ];

    $files = ['manifest.json' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    foreach ($icons as $entry => $file) {
        $files[$entry] = file_get_contents($file);
    }
    if (file_put_contents($outPath, teamsZipStore($files)) === false) {
        throw new Exception('The Teams package could not be written.');
    }

    return 'freeitsm-teams-' . substr(preg_replace('/[^a-z0-9]+/i', '', $appId), 0, 8) . '.zip';
}

/**
 * A plain zip archive with every entry stored (no compression). Written by hand so
 * the package does not depend on the PHP zip extension, which this image lacks.
 * Three small files gain nothing from compression, and stored entries are the
 * simplest form every zip reader, Teams included, accepts.
 *
 * @param array<string,string> $files  entry name => bytes
 */
function teamsZipStore(array $files): string
{
    $local = '';
    $central = '';
    $offset = 0;
    $count = 0;
    $dosTime = 0;             // 00:00:00
    $dosDate = (1 << 5) | 1;  // 1980-01-01, the earliest date zip can store
    foreach ($files as $name => $data) {
        $crc = crc32($data) & 0xffffffff;
        $size = strlen($data);
        $nameLen = strlen($name);
        $header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLen, 0);
        $local .= $header . $name . $data;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size,
            $nameLen, 0, 0, 0, 0, 0, $offset) . $name;
        $offset += strlen($header) + $nameLen + $size;
        $count++;
    }
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $offset, 0);
    return $local . $central . $end;
}

