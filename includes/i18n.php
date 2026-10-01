<?php
/**
 * i18n — Internationalisation
 *
 * Lookup pattern: t('namespace.path.to.key', ['name' => 'Ed']) where the first
 * dot-separated segment is the file name under lang/{locale}/ and everything
 * after it walks into a nested array inside that file.
 *
 *   t('common.save')           -> lang/{locale}/common.php   key 'save'
 *   t('tickets.status.open')   -> lang/{locale}/tickets.php  key 'status'.'open'
 *
 * Resolution falls back **per key**, not per file. If lang/de/tickets.php is
 * present but doesn't include the 'status.open' key, the English value is used
 * for that single key; everything else in the German file still applies.
 *
 * Last-resort fallback returns the key itself so missing strings are visible
 * during development.
 *
 * Supported locales are deliberately hard-coded — adding a language is a code
 * change (add to SUPPORTED_LOCALES, create the lang/<code>/ folder), not a
 * runtime concern. This also prevents path-traversal via the locale parameter.
 */

class I18n {
    /** Map of locale code -> native-language display name. Add a new locale here AND create lang/<code>/. */
    const SUPPORTED_LOCALES = [
        'en'    => 'English',
        'af'    => 'Afrikaans',
        'fr'    => 'Français',
        'de'    => 'Deutsch',
        'es'    => 'Español',
        'pt-BR' => 'Português (Brasil)',
        'nl'    => 'Nederlands',
        'it'    => 'Italiano',
        'pl'    => 'Polski',
        // Norwegian ships as both written standards rather than one "Norwegian":
        // bokmål is what ~85-90% write, nynorsk is co-official and dominant in the
        // west. Naming them natively is the point of this map — a nynorsk reader
        // offered only "Norsk" cannot tell which one they are about to get.
        // ⚠️ A namespace file listed here must PARSE. loadNamespace() does
        // `file_exists($file) ? (require $file) : []`, so a file that is simply
        // ABSENT falls back to English cleanly and a locale may ship a subset —
        // fr, nl and it each carry 17 of the 24 namespaces today. A file that
        // exists and contains a parse error is a fatal on that module, which is
        // the case this warning is actually about.
        'nb'    => 'Norsk bokmål',
        'nn'    => 'Norsk nynorsk',
        // Danish, added for a Danish installation that reported two bugs in a
        // week (#117 and the demo data account deletion). Norwegian is close
        // enough to Danish to look right and be wrong, so nothing here was
        // seeded from nb — every namespace is translated or absent.
        'da'    => 'Dansk',
        'ru'    => 'Русский',
        'uk'    => 'Українська',
        'id'    => 'Bahasa Indonesia',
        // Malay and Indonesian are two national standards of one language, so they
        // sit together — but they are NOT interchangeable. Vocabulary diverges in
        // exactly the register software uses (Indonesian borrowed from Dutch, Malay
        // from English): kualitas/kualiti, perangkat lunak/perisian, unduh/muat turun.
        'ms'    => 'Bahasa Melayu',
        'hi'    => 'हिन्दी',
        'bn'    => 'বাংলা',
        'ta'    => 'தமிழ்',
        'te'    => 'తెలుగు',
        'mr'    => 'मराठी',
        'kn'    => 'ಕನ್ನಡ',
        'ml'    => 'മലയാളം',
        'gu'    => 'ગુજરાતી',
        'pa'    => 'ਪੰਜਾਬੀ',
    ];

    const DEFAULT_LOCALE  = 'en';
    const FALLBACK_LOCALE = 'en';

    private static $locale = self::DEFAULT_LOCALE;
    /** Two-level cache: [locale][namespace] => array */
    private static $cache = [];

    /** Set the active locale for this request. Unsupported values silently fall back to DEFAULT_LOCALE. */
    public static function setLocale($locale) {
        self::$locale = array_key_exists($locale, self::SUPPORTED_LOCALES) ? $locale : self::DEFAULT_LOCALE;
    }

    public static function getLocale() {
        return self::$locale;
    }

    public static function getSupportedLocales() {
        return self::SUPPORTED_LOCALES;
    }

    /**
     * Initialise the locale for this request, in priority order:
     *   1. Logged-in analyst's `interface_language` user preference
     *   2. Browser Accept-Language header (best match)
     *   3. DEFAULT_LOCALE
     *
     * Safe to call without a database — fails silently if config/DB aren't loaded yet.
     */
    public static function initFromSession() {
        // The saved interface_language preference is read from the DB via
        // connectToDatabase() (defined in functions.php). Many module pages load
        // config.php + i18n.php but NOT functions.php before calling this, which
        // silently dropped the preference and fell back to Accept-Language → en.
        // Self-load the helper here so the user's language is honoured everywhere.
        if (!function_exists('connectToDatabase') && is_file(__DIR__ . '/functions.php')) {
            require_once __DIR__ . '/functions.php';
        }

        // 1. User preference (if logged in and DB is reachable)
        if (!empty($_SESSION['analyst_id']) && function_exists('connectToDatabase')) {
            try {
                $conn = connectToDatabase();
                $stmt = $conn->prepare(
                    "SELECT preference_value FROM user_preferences
                     WHERE analyst_id = ? AND preference_key = 'interface_language' LIMIT 1"
                );
                $stmt->execute([(int)$_SESSION['analyst_id']]);
                $value = $stmt->fetchColumn();
                if ($value && array_key_exists($value, self::SUPPORTED_LOCALES)) {
                    self::$locale = $value;
                    return;
                }
            } catch (Throwable $e) {
                // Fall through to header / default
            }
        }

        // 2. Accept-Language header
        if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $detected = self::matchAcceptLanguage($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            if ($detected) {
                self::$locale = $detected;
                return;
            }
        }

        // 3. Default
        self::$locale = self::DEFAULT_LOCALE;
    }

    /**
     * Look up a translation by dotted key. Returns the key itself if nothing
     * resolves so missing strings are visible during development rather than
     * silently rendering as empty.
     */
    public static function t($key, $params = []) {
        $parts = explode('.', $key, 2);
        if (count($parts) < 2) {
            // No namespace - probably a usage error. Return as-is with any interpolation.
            return self::interpolate($key, $params);
        }
        [$namespace, $path] = $parts;

        $value = self::resolve($namespace, $path, self::$locale);
        if ($value === null && self::$locale !== self::FALLBACK_LOCALE) {
            $value = self::resolve($namespace, $path, self::FALLBACK_LOCALE);
        }
        if ($value === null) {
            return $key; // Last resort: surface the unfilled key
        }
        return self::interpolate($value, $params);
    }

    /**
     * Same lookup as t(), but for an EXPLICIT locale rather than this request's
     * active one — for text that goes to someone who isn't the logged-in user,
     * e.g. a Telegram bot reply in the customer's own language_code, where
     * there is no session locale for them at all (the webhook has no session).
     * An unsupported/unknown locale code falls back to English, same as t().
     */
    public static function tFor($locale, $key, $params = []) {
        $parts = explode('.', $key, 2);
        if (count($parts) < 2) {
            return self::interpolate($key, $params);
        }
        [$namespace, $path] = $parts;

        $locale = array_key_exists($locale, self::SUPPORTED_LOCALES) ? $locale : self::FALLBACK_LOCALE;
        $value = self::resolve($namespace, $path, $locale);
        if ($value === null && $locale !== self::FALLBACK_LOCALE) {
            $value = self::resolve($namespace, $path, self::FALLBACK_LOCALE);
        }
        if ($value === null) {
            return $key;
        }
        return self::interpolate($value, $params);
    }

    /**
     * Build a flat translations object for the JS bridge: pre-merges English
     * fallback into the active locale **per key** so the frontend can do plain
     * dotted lookups without re-implementing the fallback chain. Caller passes
     * the namespaces the current page actually needs.
     *
     *   I18n::exportForJs(['common', 'process-mapper'])
     */
    public static function exportForJs(array $namespaces) {
        $out = [];
        foreach ($namespaces as $ns) {
            if (!self::isValidNamespace($ns)) continue;
            $en  = self::loadNamespace($ns, self::FALLBACK_LOCALE);
            $loc = self::$locale === self::FALLBACK_LOCALE ? [] : self::loadNamespace($ns, self::$locale);
            $out[$ns] = self::deepMerge($en, $loc);
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private static function resolve($namespace, $path, $locale) {
        $translations = self::loadNamespace($namespace, $locale);
        $cursor = $translations;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) return null;
            $cursor = $cursor[$segment];
        }
        return is_string($cursor) ? $cursor : null;
    }

    private static function loadNamespace($namespace, $locale) {
        if (!self::isValidNamespace($namespace)) return [];
        if (!array_key_exists($locale, self::SUPPORTED_LOCALES)) return [];

        if (!isset(self::$cache[$locale])) self::$cache[$locale] = [];
        if (!array_key_exists($namespace, self::$cache[$locale])) {
            $file = __DIR__ . "/../lang/{$locale}/{$namespace}.php";
            self::$cache[$locale][$namespace] = file_exists($file) ? (require $file) : [];
            if (!is_array(self::$cache[$locale][$namespace])) {
                self::$cache[$locale][$namespace] = [];
            }
        }
        return self::$cache[$locale][$namespace];
    }

    /** Only allow simple namespace identifiers — prevents path traversal via t() arg. */
    private static function isValidNamespace($namespace) {
        return is_string($namespace) && preg_match('/^[a-z0-9_-]+$/', $namespace) === 1;
    }

    /** {name}-style substitution. Unknown placeholders are left as-is so they're visible. */
    private static function interpolate($value, $params) {
        if (!is_array($params) || empty($params)) return $value;
        foreach ($params as $k => $v) {
            $value = str_replace('{' . $k . '}', (string)$v, $value);
        }
        return $value;
    }

    /** Overlay merges into base, recursively. Used to lay the active locale on top of English fallback. */
    private static function deepMerge(array $base, array $overlay) {
        foreach ($overlay as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
                $base[$k] = self::deepMerge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    /** Pick the best supported locale from an Accept-Language header. Exact matches first, then primary subtag. */
    private static function matchAcceptLanguage($header) {
        $entries = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $pieces = explode(';', $part);
            $tag = trim($pieces[0]);
            $q = 1.0;
            for ($i = 1; $i < count($pieces); $i++) {
                if (preg_match('/q=([0-9.]+)/', trim($pieces[$i]), $m)) {
                    $q = (float)$m[1];
                }
            }
            $entries[] = ['tag' => $tag, 'q' => $q];
        }
        // Sort by quality, highest first
        usort($entries, function ($a, $b) {
            return $b['q'] <=> $a['q'];
        });

        $supported = array_keys(self::SUPPORTED_LOCALES);
        // Build a lowercase lookup of supported locales for case-insensitive match
        $supportedLower = [];
        foreach ($supported as $s) $supportedLower[strtolower($s)] = $s;

        foreach ($entries as $e) {
            $tagLower = strtolower($e['tag']);
            if (isset($supportedLower[$tagLower])) return $supportedLower[$tagLower];
            // Primary subtag match (e.g. "pt" matches "pt-BR")
            $primary = explode('-', $tagLower, 2)[0];
            foreach ($supported as $s) {
                if (strtolower(explode('-', $s, 2)[0]) === $primary) return $s;
            }
        }
        return null;
    }
}

/** Global shorthand. Recommended call style in views and modules. */
function t($key, $params = []) {
    return I18n::t($key, $params);
}

// systemName() - the install's name for page titles (Tickets -> Settings -> General).
require_once __DIR__ . '/system_name.php';
