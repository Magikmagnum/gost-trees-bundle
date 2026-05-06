<?php

declare(strict_types=1);

/**
 * PHP CS Fixer — configuration Ghost Trees Bundle.
 *
 * Cible : PSR-12 + règles Symfony + quelques règles opinionated.
 * Lancer : ./vendor/bin/php-cs-fixer fix --diff --dry-run   (vérification)
 *          ./vendor/bin/php-cs-fixer fix                     (application)
 */

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->name('*.php')
    ->notName('*.blade.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        // ── Ensembles de base ───────────────────────────────────────────────
        '@PSR12'                   => true,
        '@Symfony'                 => true,
        '@Symfony:risky'           => true,
        '@PHP82Migration'          => true,
        '@PHP82Migration:risky'    => true,

        // ── Imports ────────────────────────────────────────────────────────
        'ordered_imports'          => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'        => true,
        'global_namespace_import'  => [
            'import_classes'    => false,
            'import_constants'  => false,
            'import_functions'  => false,
        ],

        // ── Déclarations strict ────────────────────────────────────────────
        'declare_strict_types'     => true,
        'strict_param'             => true,
        'strict_comparison'        => true,

        // ── Style ──────────────────────────────────────────────────────────
        'concat_space'             => ['spacing' => 'one'],
        'binary_operator_spaces'   => ['default' => 'single_space'],
        'blank_line_before_statement' => [
            'statements' => ['return', 'throw', 'try', 'if', 'foreach', 'while'],
        ],
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'single_quote'             => true,
        'phpdoc_align'             => false,
        'phpdoc_summary'           => false,
        'yoda_style'               => false,

        // ── PHP 8+ ─────────────────────────────────────────────────────────
        'nullable_type_declaration_for_default_null_value' => true,
        'use_arrow_functions'      => true,
    ])
    ->setFinder($finder);
