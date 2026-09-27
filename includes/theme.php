<?php
/**
 * Theme / palette registry + resolver.
 *
 * A palette is a set of CSS colour *tokens* applied via <html data-theme="<id>">.
 * Adding a palette is two small steps:
 *   1. add an entry to self::THEMES below
 *   2. add a [data-theme="<id>"] block to assets/css/theme.css
 * Modules opt in by consuming the tokens (var(--…)) in their own CSS, so the
 * rollout is one module at a time — unconverted modules simply stay default.
 *
 * Resolution order for a given page/module:
 *   per-module preference  (theme_<module>)  →  global preference (theme)  →  default
 * so a user can run one palette everywhere, or pick a different one per module
 * (e.g. "English Summer" on Tickets, "Dark" on Problem Management).
 */
class Theme
{
    // 'mode' is the palette's light/dark base. It drives anything that can't read
    // our CSS tokens directly — e.g. the TinyMCE editor (iframe + its own skin
    // files), which picks oxide vs oxide-dark by mode. A new palette just declares
    // its mode here and everything downstream adapts; no per-component code change.
    const THEMES = [
        'default' => ['label' => 'Light', 'mode' => 'light'],
        'dark'    => ['label' => 'Dark',  'mode' => 'dark'],
    ];
    const DEFAULT_THEME = 'default';

    private static $cache = [];

    /** All registered palettes: id => ['label' => …]. Used to build the picker. */
    public static function all() { return self::THEMES; }

    public static function isValid($id) { return is_string($id) && array_key_exists($id, self::THEMES); }

    public static function label($id) { return self::THEMES[$id]['label'] ?? $id; }

    /** The light/dark base of the active palette for $module. Drives the TinyMCE skin. */
    public static function mode($module = null)
    {
        $id = self::active($module);
        return self::THEMES[$id]['mode'] ?? 'light';
    }

    /**
     * The active palette id for $module (null = global only). Reads the analyst's
     * preference from the DB; falls back to the global pref, then the default.
     * Result is cached per module for the request.
     */
    public static function active($module = null)
    {
        $ckey = $module === null ? '*' : $module;
        if (array_key_exists($ckey, self::$cache)) return self::$cache[$ckey];

        $theme = self::DEFAULT_THEME;

        // Self-load the DB helper so this works on pages that didn't load functions.php.
        if (!function_exists('connectToDatabase') && is_file(__DIR__ . '/functions.php')) {
            require_once __DIR__ . '/functions.php';
        }

        // Self-service portal users. They have no analyst_id, so without this
        // branch a portal user could only ever get the default palette — the
        // portal would be tokenised but the tokens unreachable. Their choice
        // lives on the user row (users.theme_preference); the session copy is
        // just a cache so a signed-in page doesn't hit the DB every request.
        // 🔴 THIS USED TO REQUIRE analyst_id TO BE EMPTY, AND THAT WAS THE BUG.
        //
        // The portal and the app are one host and one PHPSESSID. An analyst who
        // also has a portal account - every developer, and most admins testing
        // their own portal - carries both ids at once, so the condition was
        // false and the portal rendered the ANALYST's palette. The portal choice
        // was saved correctly and then ignored on every render, which from the
        // outside is indistinguishable from "it did not save".
        //
        // What decides it is WHICH PRODUCT the page belongs to, not who happens
        // to be signed in elsewhere. self-service/includes/header.php defines
        // the constant before anything asks.
        $onPortal = defined('FREEITSM_SELF_SERVICE') && FREEITSM_SELF_SERVICE;
        if (!empty($_SESSION['ss_user_id']) && ($onPortal || empty($_SESSION['analyst_id']))) {
            if (isset($_SESSION['ss_theme']) && self::isValid($_SESSION['ss_theme'])) {
                return self::$cache[$ckey] = $_SESSION['ss_theme'];
            }
            if (function_exists('connectToDatabase')) {
                try {
                    $conn = connectToDatabase();
                    $stmt = $conn->prepare("SELECT theme_preference FROM users WHERE id = ?");
                    $stmt->execute([(int) $_SESSION['ss_user_id']]);
                    $val = $stmt->fetchColumn();
                    if ($val !== false && self::isValid($val)) {
                        $_SESSION['ss_theme'] = $val;   // cache for later requests
                        return self::$cache[$ckey] = $val;
                    }
                } catch (Throwable $e) {
                    // column not added yet, or DB unavailable — fall through to default
                }
            }
            return self::$cache[$ckey] = $theme;
        }

        if (!empty($_SESSION['analyst_id']) && function_exists('connectToDatabase')) {
            try {
                $keys = [];
                if ($module) $keys[] = 'theme_' . $module;
                $keys[] = 'theme';
                $placeholders = implode(',', array_fill(0, count($keys), '?'));

                $conn = connectToDatabase();
                $stmt = $conn->prepare(
                    "SELECT preference_key, preference_value FROM user_preferences
                     WHERE analyst_id = ? AND preference_key IN ($placeholders)"
                );
                $stmt->execute(array_merge([(int)$_SESSION['analyst_id']], $keys));
                $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

                // Honour the most specific preference that resolves to a real palette.
                foreach ($keys as $k) {
                    if (!empty($rows[$k]) && self::isValid($rows[$k])) { $theme = $rows[$k]; break; }
                }
            } catch (Throwable $e) {
                // fall back to default
            }
        }

        return self::$cache[$ckey] = $theme;
    }
}

// systemName() - the install's name for page titles (Tickets -> Settings -> General).
require_once __DIR__ . '/system_name.php';
