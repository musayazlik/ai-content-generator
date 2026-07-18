<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'AI Content Generator',
    'description' => 'AI-powered content generator for TYPO3 content elements. Adds an AI button to each content element in the page module.',
    'category' => 'backend',
    'author' => 'Musa Yazlık',
    'author_email' => 'info@musayazlik.com',
    'state' => 'beta',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-14.99.99',
            'php' => '8.2.0-8.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
