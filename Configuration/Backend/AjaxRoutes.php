<?php

declare(strict_types=1);

return [
    'ai_content_generator_get_fields' => [
        'path' => '/ai-content-generator/get-fields',
        'target' => \MusaYazlik\AiContentGenerator\Controller\AiContentGeneratorController::class . '::getFieldsAction',
    ],
    'ai_content_generator_generate' => [
        'path' => '/ai-content-generator/generate',
        'target' => \MusaYazlik\AiContentGenerator\Controller\AiContentGeneratorController::class . '::generateAction',
    ],
    'ai_content_generator_generate_image' => [
        'path' => '/ai-content-generator/generate-image',
        'target' => \MusaYazlik\AiContentGenerator\Controller\AiContentGeneratorController::class . '::generateImageAction',
    ],
    'ai_content_generator_save' => [
        'path' => '/ai-content-generator/save',
        'target' => \MusaYazlik\AiContentGenerator\Controller\AiContentGeneratorController::class . '::saveAction',
    ],
];
