<?php

declare(strict_types=1);

# Importmap for the AI Content Generator extension.
#
# IMPORTANT: This file MUST sit at `Configuration/JavaScriptModules.php` (root of
# the Configuration directory). TYPO3's ImportMap resolver only reads this exact
# location — a file at `Configuration/Backend/JavaScriptModules.php` is NOT loaded
# and the module specifier below will fail with "could not be resolved".

return [
    'dependencies' => ['core', 'backend'],
    'tags' => [],
    'imports' => [
        '@musayazlik/ai-content-generator/' => 'EXT:ai_content_generator/Resources/Public/JavaScript/',
    ],
];
