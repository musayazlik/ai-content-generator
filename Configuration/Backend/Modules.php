<?php

declare(strict_types=1);

use MusaYazlik\AiContentGenerator\Controller\SettingsModuleController;

return [
    'ai_content_generator_settings' => [
        'parent' => 'system',
        'position' => ['after' => 'settings'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/system/ai-content-generator',
        'iconIdentifier' => 'module-ai-content-generator',
        'labels' => 'LLL:EXT:ai_content_generator/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => SettingsModuleController::class . '::handleRequest',
            ],
        ],
    ],
];
