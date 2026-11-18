<?php
/**
 * Ensure english.php and farsi.php expose the same translation keys.
 *
 * Usage: php tests/check_lang_keys.php
 */

$root = dirname(__DIR__) . '/modules/addons/whmcsupdater/lang';

function load_keys(string $path): array
{
    $_ADDONLANG = [];
    include $path;
    return array_keys($_ADDONLANG);
}

$english = load_keys($root . '/english.php');
$farsi = load_keys($root . '/farsi.php');

sort($english);
sort($farsi);

$missingInFarsi = array_diff($english, $farsi);
$extraInFarsi = array_diff($farsi, $english);

$ok = true;

if ($missingInFarsi) {
    $ok = false;
    fwrite(STDERR, "Missing in farsi.php:\n  - " . implode("\n  - ", $missingInFarsi) . "\n");
}

if ($extraInFarsi) {
    $ok = false;
    fwrite(STDERR, "Extra in farsi.php (not in english.php):\n  - " . implode("\n  - ", $extraInFarsi) . "\n");
}

if ($ok) {
    echo 'OK: ' . count($english) . " keys match between english.php and farsi.php\n";
    exit(0);
}

exit(1);
