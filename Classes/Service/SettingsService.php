<?php

declare(strict_types=1);

namespace MusaYazlik\AiContentGenerator\Service;

use TYPO3\CMS\Core\Registry;

/**
 * Centralized settings storage for AI Content Generator.
 * Uses TYPO3 Registry to persist settings in the database (sys_registry table).
 */
class SettingsService
{
    protected const NAMESPACE = 'ai_content_generator';
    protected const KEY = 'settings';

    protected Registry $registry;

    protected array $defaults = [
        // Text generation
        'apiKey' => '',
        'model' => 'gpt-4o-mini',
        'apiEndpoint' => 'https://api.openai.com/v1/chat/completions',
        'systemPrompt' => '',
        // Image generation (separate provider credentials)
        'imageApiKey' => '',
        'imageModel' => 'gpt-image-1',
        'imageApiEndpoint' => 'https://api.openai.com/v1/images/generations',
        'imageSize' => '1024x1024',
    ];

    public function __construct(Registry $registry)
    {
        $this->registry = $registry;
    }

    public function getAll(): array
    {
        $stored = $this->registry->get(self::NAMESPACE, self::KEY, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return array_merge($this->defaults, $stored);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $settings = $this->getAll();
        return $settings[$key] ?? $default;
    }

    public function save(array $settings): void
    {
        // Merge over the stored values so partial saves never wipe other settings.
        $this->registry->set(self::NAMESPACE, self::KEY, array_merge($this->getAll(), $settings));
    }
}
