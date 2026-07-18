<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// Add a virtual field for the AI Content Generator button (not stored in DB)
$GLOBALS['TCA']['tt_content']['columns']['ai_content_generator'] = [
    'label' => 'AI Content Generator',
    'config' => [
        'type' => 'user',
        'renderType' => 'aiContentGeneratorButton',
    ],
];

// Add a new "AI Content Generator" tab to all tt_content types
// Placed before the "Notes" tab
ExtensionManagementUtility::addToAllTCAtypes(
    'tt_content',
    '--div--;AI Content Generator,ai_content_generator',
    '',
    'before:notes'
);
