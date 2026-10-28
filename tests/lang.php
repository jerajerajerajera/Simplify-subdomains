<?php

// Every language has exactly the English keys of every file, and keeps every :placeholder of the English text.
declare(strict_types=1);

function flat(array $lines, string $prefix = ''): array
{
    $out = [];
    foreach ($lines as $key => $value) {
        if (is_array($value)) {
            $out += flat($value, $prefix.$key.'.');
        } else {
            $out[$prefix.$key] = $value;
        }
    }

    return $out;
}

$root = __DIR__.'/../lang';
$failures = [];
$files = array_map('basename', glob($root.'/en/*.php'));
$locales = array_map('basename', glob($root.'/*', GLOB_ONLYDIR));
foreach ($locales as $locale) {
    foreach ($files as $file) {
        $path = "$root/$locale/$file";
        if (! is_file($path)) {
            $failures[] = "$locale: $file is missing";

            continue;
        }
        $en = flat(require "$root/en/$file");
        $lines = flat(require $path);
        foreach (array_diff_key($en, $lines) as $key => $_) {
            $failures[] = "$locale/$file: missing $key";
        }
        foreach (array_diff_key($lines, $en) as $key => $_) {
            $failures[] = "$locale/$file: unknown key $key";
        }
        foreach (array_intersect_key($lines, $en) as $key => $text) {
            preg_match_all('/:[a-z_]+/', $en[$key], $want);
            preg_match_all('/:[a-z_]+/', (string) $text, $have);
            $lost = array_diff($want[0], $have[0]);
            if ($lost) {
                $failures[] = "$locale/$file: $key lost ".implode(', ', $lost);
            }
        }
    }
}
if ($failures) {
    echo "FAIL translations:\n  ".implode("\n  ", $failures)."\n";
    exit(1);
}
echo 'PASS '.count($locales).' languages have every key and placeholder'."\n";
