<?php

declare(strict_types=1);

# IMPORTANT: This file MUST sit at `Configuration/Icons.php` — TYPO3's icon
# registry only reads this exact location. A file at
# `Configuration/Backend/Icons.php` is silently ignored and every reference to
# these identifiers falls back to the red "default" record icon.

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'module-ai-content-generator' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:ai_content_generator/Resources/Public/Icons/Extension.svg',
    ],
];
