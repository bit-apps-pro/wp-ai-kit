#!/usr/bin/env php
<?php

/**
 * Refuses source that breaks once a consuming plugin prefixes this package.
 *
 * Consumers run Imposter (rewrites `vendor/` in place) or Strauss (copies into
 * `dependencies/`). Imposter's prefixUse() rewrites EVERY multi-segment `use X\Y;`
 * line, so a `use WP\MCP\Core\McpAdapter;` becomes `use <Prefix>\WP\MCP\…` and
 * fatals on the sites it targets; Strauss rewrites class names in string
 * literals that Imposter leaves dangling. The rules this script enforces:
 *
 *  1. No `use` import of a namespace outside this package or PHP/PHPUnit itself
 *     — reference external classes inline with a leading backslash.
 *  2. No closure `use (…)` on a source line that also contains a backslash.
 *  3. No fully-qualified class names in string literals (`'Foo\\Bar'`); use ::class.
 *  4. No `files` autoload and no namespaced functions/constants in composer.json / src.
 *
 * Usage: php bin/check-prefix-safety.php src [more dirs]
 */
$roots = \array_slice($argv, 1) ?: ['src'];
// The kit's own namespace, and its one dependency, the Bit Apps ORM, which every consumer prefixes with it.
$allowedUsePrefixes = ['BitApps\AiKit\\', 'BitApps\WPDatabase\\', 'PHPUnit\\'];
$errors = [];

$composer = json_decode((string) file_get_contents(__DIR__ . '/../composer.json'), true);
if (isset($composer['autoload']['files'])) {
    $errors[] = 'composer.json: `autoload.files` is not allowed (imposter-plugin classmaps vendor autoloads; function files are never included).';
}

foreach ($roots as $root) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ('php' !== $file->getExtension()) {
            continue;
        }
        $path = $file->getPathname();
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        foreach ($lines as $i => $line) {
            $n = $i + 1;
            $trim = ltrim($line);

            if (preg_match('/^use\s+(?!const\s|function\s)([A-Za-z_\\\][A-Za-z0-9_\\\]*)/', $trim, $m)) {
                $target = $m[1];
                if (false !== strpos($target, '\\')) {
                    $ok = false;
                    foreach ($allowedUsePrefixes as $prefix) {
                        if (0 === strpos($target, $prefix)) {
                            $ok = true;

                            break;
                        }
                    }
                    if (!$ok) {
                        $errors[] = "{$path}:{$n}: `use {$target}` imports an external namespace; reference it inline as \\{$target} (Imposter rewrites every multi-segment use line).";
                    }
                }
            }

            if (preg_match('/^use\s+(function|const)\s/', $trim)) {
                $errors[] = "{$path}:{$n}: `use function/const` imports are not prefix-safe.";
            }

            if (preg_match('/\buse\s*\(/', $line) && false !== strpos($line, '\\')) {
                $errors[] = "{$path}:{$n}: closure `use (…)` on a line containing a backslash is rewritten by Imposter; split the line.";
            }

            if (preg_match('/[\'"][A-Za-z_][A-Za-z0-9_]*(\\\\\\\|\\\)[A-Za-z_][A-Za-z0-9_\\\]*[\'"]/', $line) && false === strpos($line, '* ') && false === strpos($trim, '//')) {
                $errors[] = "{$path}:{$n}: class name in a string literal; use ::class (Strauss rewrites literals, Imposter does not).";
            }

            if (preg_match('/^(function|const)\s+[A-Za-z_]/', $trim) && 0 === $i) {
                $errors[] = "{$path}:{$n}: namespaced function/const declarations are not prefix-safe.";
            }
        }

        $src = implode("\n", $lines);
        if (preg_match('/^namespace\s+[^;]+;.*?^(function|const)\s+[A-Za-z_]/ms', $src)) {
            $errors[] = "{$path}: namespaced function/const declarations are not prefix-safe.";
        }
    }
}

if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    fwrite(STDERR, "\n" . \count($errors) . " prefix-safety problem(s).\n");

    exit(1);
}

echo "prefix-safety: OK\n";
