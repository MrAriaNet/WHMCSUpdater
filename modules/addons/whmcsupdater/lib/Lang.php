<?php
/**
 * Language string resolution with English fallback for missing keys.
 */

namespace WHMCSUpdater;

class Lang
{
    /**
     * Merge WHMCS-provided language strings with english.php fallbacks.
     *
     * @param array $vars Addon output vars (must include _lang when available)
     * @return array<string, string>
     */
    public static function resolve(array $vars): array
    {
        $english = self::loadFile('english');
        $provided = [];

        if (isset($vars['_lang']) && is_array($vars['_lang'])) {
            $provided = $vars['_lang'];
        }

        // Prefer strings already loaded by WHMCS for the admin's language,
        // then fill any gaps from english.php so incomplete translations
        // never produce blank labels.
        return array_merge($english, $provided);
    }

    /**
     * Detect whether the active admin language is right-to-left.
     */
    public static function isRtl(array $vars, array $lang): bool
    {
        $candidates = [
            $vars['language'] ?? null,
            $lang['language'] ?? null,
            $_SESSION['Language'] ?? null,
            $_SESSION['adminlang'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $normalized = strtolower($candidate);
            if (in_array($normalized, ['farsi', 'persian', 'arabic', 'hebrew', 'urdu'], true)) {
                return true;
            }
        }

        // WHMCS language files commonly expose a direction marker.
        if (!empty($lang['locale']) && stripos((string) $lang['locale'], 'fa') === 0) {
            return true;
        }

        return !empty($lang['rtl']);
    }

    /**
     * @return array<string, string>
     */
    public static function loadFile(string $name): array
    {
        $path = dirname(__DIR__) . '/lang/' . $name . '.php';
        if (!is_file($path)) {
            return [];
        }

        $_ADDONLANG = [];
        include $path;

        return is_array($_ADDONLANG) ? $_ADDONLANG : [];
    }

    public static function get(array $lang, string $key, string $fallback = ''): string
    {
        if (isset($lang[$key]) && $lang[$key] !== '') {
            return (string) $lang[$key];
        }

        return $fallback !== '' ? $fallback : $key;
    }
}
