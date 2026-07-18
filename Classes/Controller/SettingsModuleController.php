<?php

declare(strict_types=1);

namespace MusaYazlik\AiContentGenerator\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use MusaYazlik\AiContentGenerator\Service\SettingsService;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Backend module controller for AI Content Generator settings.
 *
 * Provides a settings page in the left navigation menu where administrators
 * can configure the AI API key, model, endpoint, and system prompt.
 */
class SettingsModuleController
{
    protected ModuleTemplateFactory $moduleTemplateFactory;
    protected SettingsService $settingsService;

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        SettingsService $settingsService,
        protected readonly PageRenderer $pageRenderer
    ) {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->settingsService = $settingsService;
    }

    /**
     * Handle the module request — show form on GET, save settings on POST.
     */
    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $saved = false;
        $errorMessage = '';

        if ($request->getMethod() === 'POST') {
            $parsedBody = $request->getParsedBody();

            if (isset($parsedBody['save'])) {
                try {
                    $this->settingsService->save([
                        // Text generation
                        'apiKey' => (string)($parsedBody['apiKey'] ?? ''),
                        'model' => (string)($parsedBody['model'] ?? 'gpt-4o-mini'),
                        'apiEndpoint' => (string)($parsedBody['apiEndpoint'] ?? 'https://api.openai.com/v1/chat/completions'),
                        'systemPrompt' => (string)($parsedBody['systemPrompt'] ?? ''),
                        // Image generation
                        'imageApiKey' => (string)($parsedBody['imageApiKey'] ?? ''),
                        'imageModel' => (string)($parsedBody['imageModel'] ?? 'gpt-image-1'),
                        'imageApiEndpoint' => (string)($parsedBody['imageApiEndpoint'] ?? 'https://api.openai.com/v1/images/generations'),
                        'imageSize' => (string)($parsedBody['imageSize'] ?? '1024x1024'),
                    ]);

                    $saved = true;
                } catch (\Throwable $e) {
                    $errorMessage = $e->getMessage();
                }
            }
        }

        $config = $this->settingsService->getAll();

        $this->pageRenderer->addCssFile('EXT:ai_content_generator/Resources/Public/Css/ai-content-generator.css');

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle('AI Content Generator Settings');
        $moduleTemplate->assignMultiple([
            'settings' => $config,
            'saved' => $saved,
            'errorMessage' => $errorMessage,
            'token' => $request->getQueryParams()['token'] ?? '',
        ]);

        return $moduleTemplate->renderResponse('SettingsModule/Index');
    }
}
