<?php
/**
 * The system's name - Tickets -> Settings -> General -> "System name".
 *
 * It was saved and read by nothing: the setting's help promised the name in
 * the page titles, and 90 pages had "Service Desk" (or "FreeITSM") typed into
 * their <title> instead. They now ask here.
 *
 * Never saved, empty, or unreadable (no database yet, mid-upgrade) means
 * "Service Desk" - exactly what every title said before - so nothing changes
 * for an install that has not set it.
 *
 * Loaded from includes/i18n.php and includes/theme.php, one of which every
 * page with a title already loads.
 */

if (!function_exists('systemName')) {
    function systemName(): string
    {
        static $name = null;
        if ($name !== null) return $name;
        $name = 'Service Desk';
        try {
            if (!function_exists('connectToDatabase')) require_once __DIR__ . '/functions.php';
            $st = connectToDatabase()->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'system_name'");
            $st->execute();
            $v = trim((string)$st->fetchColumn());
            if ($v !== '') $name = $v;
        } catch (Throwable $e) {
            // keep the default
        }
        return $name;
    }
}
