<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Register custom FormEngine element for the AI button in edit form
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1711234567] = [
    'nodeName' => 'aiContentGeneratorButton',
    'priority' => 30,
    'class' => \MusaYazlik\AiContentGenerator\Backend\Form\Element\AiContentGeneratorElement::class,
];
