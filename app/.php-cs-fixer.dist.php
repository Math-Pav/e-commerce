<?php

declare(strict_types=1);

// Formatage du code (voir docs/adr/0014-analyse-statique-et-formatage.md).
// Vérifier : composer cs ; corriger : composer cs-fix
$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
;

return (new PhpCsFixer\Config())
    // declare_strict_types est une règle « risquée » : elle peut changer le comportement
    // d'un fichier qui comptait sur la conversion automatique des types.
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        'declare_strict_types' => true,
        // Imports triés par ordre alphabétique : classes, puis fonctions, puis constantes
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => ['class', 'function', 'const'],
        ],
    ])
    ->setFinder($finder)
;
