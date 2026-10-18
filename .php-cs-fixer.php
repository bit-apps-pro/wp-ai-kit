<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

/*
 * PSR-12 plus the PhpCsFixer set, risky rules off, no strict_types declarations:
 * the kit ships inside WordPress plugins whose hook callbacks receive untyped values.
 * bin/check-prefix-safety.php runs after this in `composer check`, so a rule that
 * re-introduces external `use` imports fails the build.
 */
return (new Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12'                      => true,
        '@PhpCsFixer'                 => true,
        'declare_strict_types'        => false,
        'php_unit_test_class_requires_covers' => false,
        'php_unit_internal_class'     => false,
        'phpdoc_to_comment'           => false,
        'yoda_style'                  => false,
        'concat_space'                => ['spacing' => 'one'],
        'binary_operator_spaces'      => ['default' => 'single_space', 'operators' => ['=>' => 'align_single_space_minimal']],
        'global_namespace_import'     => ['import_classes' => false, 'import_constants' => false, 'import_functions' => false],
        // Never turn an inline \Vendor\Class into a `use` import: consuming plugins prefix every
        // multi-segment `use` line (Imposter), which breaks references to WordPress-provided classes.
        'fully_qualified_strict_types' => false,
    ])
    ->setFinder(
        (new Finder())
            ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/bin'])
    );
