<?php

declare(strict_types=1);

namespace MusaYazlik\AiContentGenerator\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use MusaYazlik\AiContentGenerator\Service\SettingsService;

/**
 * Service for communicating with an AI API (OpenAI-compatible) to generate content.
 */
class AiService
{
    protected array $config;

    public function __construct(SettingsService $settingsService)
    {
        $this->config = $settingsService->getAll();
    }

    /**
     * Generate content for the given fields based on the user's prompt.
     *
     * @param array $fields List of field definitions. Plain fields:
     *                      ['kind' => 'text', 'name' => 'header', 'label' => 'Title', 'type' => 'input', 'value' => ''].
     *                      Collection fields (repeatable child records):
     *                      ['kind' => 'collection', 'name' => 'gallery_items', 'label' => '...', 'count' => 4,
     *                       'itemFields' => [['name' => 'title', 'label' => '...', 'type' => 'input'], ...]]
     * @param string $userPrompt The user's instruction for content generation
     * @return array Generated content, e.g. ['header' => 'Generated Title', 'gallery_items' => [['title' => '...'], ...]]
     */
    public function generateContent(array $fields, string $userPrompt): array
    {
        $apiKey = $this->config['apiKey'] ?? '';
        $model = $this->config['model'] ?? 'gpt-4o-mini';
        $apiEndpoint = $this->config['apiEndpoint'] ?? 'https://api.openai.com/v1/chat/completions';
        $systemPrompt = $this->config['systemPrompt'] ?? 'You are a content generator for TYPO3 CMS.';

        if ($apiKey === '') {
            throw new \RuntimeException('AI API key is not configured. Please set it in Extension Settings.', 1700000001);
        }

        $fieldDescriptions = [];
        $collectionDescriptions = [];

        foreach ($fields as $field) {
            if (($field['kind'] ?? 'text') === 'collection') {
                $subFieldDescriptions = [];
                foreach ($field['itemFields'] ?? [] as $subField) {
                    $subFieldDescriptions[] = sprintf(
                        '"%s" (field name: "%s", type: %s)',
                        $subField['label'] ?? $subField['name'],
                        $subField['name'],
                        $subField['type'] ?? 'input'
                    );
                }
                $collectionDescriptions[] = sprintf(
                    '- "%s" (field name: "%s"): an ARRAY of exactly %d objects. Each object must have exactly these keys: %s.',
                    $field['label'] ?? $field['name'],
                    $field['name'],
                    (int)($field['count'] ?? 3),
                    implode(', ', $subFieldDescriptions)
                );
                continue;
            }

            $fieldDescriptions[] = sprintf(
                '- "%s" (field name: "%s", type: %s, current value: "%s")',
                $field['label'] ?? $field['name'],
                $field['name'],
                $field['type'] ?? 'input',
                mb_substr((string)($field['value'] ?? ''), 0, 200)
            );
        }

        $sections = [];
        if (!empty($fieldDescriptions)) {
            $sections[] = "Text fields to generate:\n" . implode("\n", $fieldDescriptions);
        }
        if (!empty($collectionDescriptions)) {
            $sections[] = "Repeatable list fields to generate:\n" . implode("\n", $collectionDescriptions);
        }

        $userMessage = sprintf(
            "%s\n\nUser instruction: %s\n\n" .
            "Return ONLY a valid JSON object where keys are field names. " .
            "For text fields, the value must be a plain string (or HTML string for bodytext-like fields). " .
            "For repeatable list fields, the value must be a JSON array of objects with exactly the specified keys and exactly the specified number of items. " .
            "Do not include any markdown formatting or explanation outside the JSON.",
            implode("\n\n", $sections),
            $userPrompt
        );

        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
                [
                    'role' => 'user',
                    'content' => $userMessage,
                ],
            ],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.7,
        ];

        try {
            $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
            $response = $requestFactory->request(
                $apiEndpoint,
                'POST',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
                    'timeout' => 60,
                ]
            );

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if (!isset($data['choices'][0]['message']['content'])) {
                throw new \RuntimeException('Invalid AI API response structure.', 1700000002);
            }

            $content = $data['choices'][0]['message']['content'];
            $generated = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('AI response is not valid JSON: ' . json_last_error_msg(), 1700000003);
            }

            // Defensively normalize collection fields to arrays truncated to the requested count,
            // in case the model returns something malformed or with extra items.
            foreach ($fields as $field) {
                if (($field['kind'] ?? 'text') !== 'collection') {
                    continue;
                }
                $name = $field['name'];
                $count = (int)($field['count'] ?? 3);
                $items = is_array($generated[$name] ?? null) ? array_values($generated[$name]) : [];
                $generated[$name] = array_slice($items, 0, $count);
            }

            return $generated;
        } catch (RequestException $e) {
            throw new \RuntimeException('AI API request failed: ' . $e->getMessage(), 1700000004);
        }
    }

    /**
     * Generate an image from a prompt using the (separately configured) image API.
     *
     * Supports three endpoint styles:
     * - OpenAI Images API (api.openai.com/v1/images/generations — gpt-image-1, dall-e-3)
     * - OpenRouter Images API (openrouter.ai/api/v1/images) — same { data: [{ b64_json }] } shape
     * - Chat completions with image output (openrouter.ai/api/v1/chat/completions with
     *   modalities: ["image","text"], e.g. google/gemini-2.5-flash-image) — the image
     *   comes back in choices[0].message.images[0].image_url.url as a data: URL
     *
     * @return string Raw binary image data
     */
    public function generateImage(string $prompt): string
    {
        $apiKey = $this->config['imageApiKey'] ?? '';
        $model = $this->config['imageModel'] ?? 'gpt-image-1';
        $apiEndpoint = $this->config['imageApiEndpoint'] ?? 'https://api.openai.com/v1/images/generations';
        $size = $this->config['imageSize'] ?? '1024x1024';

        if ($apiKey === '') {
            throw new \RuntimeException('Image API key is not configured. Please set it in the AI Content Generator module.', 1700000010);
        }

        $isChatEndpoint = str_contains($apiEndpoint, '/chat/completions');

        if ($isChatEndpoint) {
            $payload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'modalities' => ['image', 'text'],
            ];
        } else {
            $payload = [
                'model' => $model,
                'prompt' => $prompt,
            ];
            if (!str_contains($apiEndpoint, 'openrouter')) {
                // OpenAI-style extras; OpenRouter's /api/v1/images only documents model+prompt.
                $payload['n'] = 1;
                $payload['size'] = $size;
                // dall-e-* models need response_format to return base64; gpt-image-1
                // always returns base64 and rejects the parameter.
                if (str_starts_with($model, 'dall-e')) {
                    $payload['response_format'] = 'b64_json';
                }
            }
        }

        try {
            $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
            $response = $requestFactory->request(
                $apiEndpoint,
                'POST',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
                    'timeout' => 120,
                    'http_errors' => false,
                ]
            );

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            $apiError = $data['error']['message'] ?? $data['error'] ?? null;
            if ($response->getStatusCode() >= 400) {
                $message = is_string($apiError) ? $apiError : ('HTTP ' . $response->getStatusCode());
                throw new \RuntimeException('Image API error: ' . $message, 1700000012);
            }

            if ($isChatEndpoint) {
                $imageUrl = (string)($data['choices'][0]['message']['images'][0]['image_url']['url'] ?? '');
                if ($imageUrl === '') {
                    throw new \RuntimeException(
                        'The model did not return an image. Make sure the configured model supports image output (e.g. google/gemini-2.5-flash-image).',
                        1700000014
                    );
                }
                return $this->fetchImageBinary($imageUrl);
            }

            $item = $data['data'][0] ?? null;

            if (is_array($item) && ($item['b64_json'] ?? '') !== '') {
                $binary = base64_decode((string)$item['b64_json'], true);
                if ($binary === false) {
                    throw new \RuntimeException('Image API returned invalid base64 data.', 1700000011);
                }
                return $binary;
            }

            if (is_array($item) && ($item['url'] ?? '') !== '') {
                return $this->fetchImageBinary((string)$item['url']);
            }

            throw new \RuntimeException(
                is_string($apiError) ? 'Image API error: ' . $apiError : 'Invalid image API response structure.',
                1700000012
            );
        } catch (RequestException $e) {
            throw new \RuntimeException('Image API request failed: ' . $e->getMessage(), 1700000013);
        }
    }

    /**
     * Resolve an image reference to binary data — either a base64 data: URL or a
     * downloadable http(s) URL (some providers return temporary hosted URLs).
     */
    protected function fetchImageBinary(string $url): string
    {
        if (str_starts_with($url, 'data:')) {
            $commaPos = strpos($url, ',');
            if ($commaPos === false) {
                throw new \RuntimeException('Image API returned a malformed data URL.', 1700000015);
            }
            $binary = base64_decode(substr($url, $commaPos + 1), true);
            if ($binary === false) {
                throw new \RuntimeException('Image API returned invalid base64 data.', 1700000011);
            }
            return $binary;
        }

        $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
        $imageResponse = $requestFactory->request($url, 'GET', ['timeout' => 120]);
        return $imageResponse->getBody()->getContents();
    }

    /**
     * Check if the AI service is properly configured.
     */
    public function isConfigured(): bool
    {
        return ($this->config['apiKey'] ?? '') !== '';
    }

    /**
     * Check if the image generation side is configured.
     */
    public function isImageConfigured(): bool
    {
        return ($this->config['imageApiKey'] ?? '') !== '';
    }
}
