<?php

declare(strict_types=1);

namespace MusaYazlik\AiContentGenerator\EventListener;

use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Loads the AI Content Generator JS module and CSS into the backend Page module (Web>Layout).
 *
 * TYPO3 v14 removed the classic SC_OPTIONS['Backend/PageLayout/PageLayoutController']['beforeRenderHook'];
 * PageLayoutController now dispatches ModifyPageLayoutContentEvent instead.
 */
#[AsEventListener('ai-content-generator/page-layout-assets')]
final class PageLayoutAssetsEventListener
{
    public function __construct(
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $this->pageRenderer->loadJavaScriptModule('@musayazlik/ai-content-generator/ai-content-generator.js');
        $this->pageRenderer->addCssFile('EXT:ai_content_generator/Resources/Public/Css/ai-content-generator.css');
    }
}
