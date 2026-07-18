<?php

declare(strict_types=1);

namespace MusaYazlik\AiContentGenerator\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use MusaYazlik\AiContentGenerator\Service\AiService;

/**
 * AJAX controller for the AI Content Generator.
 *
 * Handles three endpoints:
 * 1. getFields  - Returns the available fields for a given content element
 * 2. generate   - Generates content using AI based on fields + user prompt
 * 3. save       - Saves the generated content to the tt_content record
 *
 * Fields come back from getFieldsAction() in four "kinds":
 * - text:       plain input/text/textarea/richtext/link fields, filled by AI from the user's prompt
 * - select:     fields with a fixed list of options; one is picked at random (no AI call needed)
 * - collection: `inline` fields (e.g. TYPO3 Content Blocks "Collection" fields) whose foreign_table
 *               gets 3-5 new child records, each filled by AI
 * - image:      `file` (FAL) fields; an image is generated via the image API, stored in the
 *               default storage (fileadmin) and attached as a sys_file_reference on save
 */
class AiContentGeneratorController
{
    protected AiService $aiService;
    protected ConnectionPool $connectionPool;

    /**
     * Fields that should never be offered for AI generation (system/internal fields).
     */
    protected const EXCLUDED_FIELDS = [
        'uid', 'pid', 'tstamp', 'crdate', 'cruser_id', 'deleted', 'hidden',
        'starttime', 'endtime', 'fe_group', 'sorting', 'CType', 'colPos',
        'tx_gridelements_container', 'tx_gridelements_columns', 'l18n_parent',
        'l18n_diffsource', 'l10n_parent', 't3_origuid', 'sys_language_uid', 'l10n_source',
        'l10n_state', 't3ver_oid', 't3ver_id', 't3ver_label', 't3ver_wsid',
        't3ver_state', 't3ver_stage', 't3ver_count', 't3ver_tstamp',
        't3ver_move_id', 't3ver_swapmode', 'perms_userid', 'perms_groupid',
        'perms_user', 'perms_group', 'perms_everybody', 'editlock',
        'categories', 'layout', 'space_before', 'space_after', 'sectionIndex',
        'linkToTop', 'file_collections', 'filelink_sorting', 'filelink_sorting_direction',
        'records', 'table_class', 'table_caption', 'table_delimiter',
        'table_enclosure', 'table_header_position', 'table_tabulator',
        'menu_type', 'pages', 'recursive',
        'bullets_type', 'uploads_type', 'accessibility_title',
        'accessibility_bypass', 'accessibility_bypass_text',
        'section_frame', 'frame_class', 'space_before_class', 'space_after_class',
        'rowDescription', 'backupColPos', 'tx_container_parent',
        'foreign_table_parent_uid',
    ];

    /**
     * Field types that are suitable for AI text generation.
     */
    protected const GENERATABLE_TYPES = ['input', 'text', 'textarea', 'richtext', 'link'];

    /**
     * Min/max number of child records generated for a collection (inline) field.
     */
    protected const COLLECTION_MIN_ITEMS = 3;
    protected const COLLECTION_MAX_ITEMS = 5;

    /**
     * Folder inside the default storage (fileadmin) where generated images are stored.
     */
    protected const IMAGE_FOLDER = 'ai_generated';

    public function __construct(
        AiService $aiService,
        ConnectionPool $connectionPool,
        protected readonly StorageRepository $storageRepository
    ) {
        $this->aiService = $aiService;
        $this->connectionPool = $connectionPool;
    }

    /**
     * Get the available fields for a content element.
     */
    public function getFieldsAction(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        $queryParams = $request->getQueryParams();

        $uid = (int)($parsedBody['uid'] ?? $queryParams['uid'] ?? 0);

        if ($uid === 0) {
            return new JsonResponse(['error' => 'No UID provided'], 400);
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $record = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid)))
            ->executeQuery()
            ->fetchAssociative();

        if (!$record) {
            return new JsonResponse(['error' => 'Record not found'], 404);
        }

        $cType = $record['CType'] ?? 'text';
        $fields = $this->getFieldsForCType($cType, $record);

        return new JsonResponse([
            'uid' => $uid,
            'cType' => $cType,
            'fields' => $fields,
        ]);
    }

    /**
     * Generate content using AI. `select` fields are resolved locally (random pick from
     * their fixed option list); `text` and `collection` fields go through the AI service.
     */
    public function generateAction(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        $queryParams = $request->getQueryParams();

        $fields = $parsedBody['fields'] ?? $queryParams['fields'] ?? [];
        $userPrompt = (string)($parsedBody['prompt'] ?? $queryParams['prompt'] ?? '');

        if (empty($fields)) {
            return new JsonResponse(['error' => 'No fields provided'], 400);
        }

        if (trim($userPrompt) === '') {
            return new JsonResponse(['error' => 'No prompt provided'], 400);
        }

        $generated = [];
        $aiFields = [];

        foreach ($fields as $field) {
            $kind = $field['kind'] ?? 'text';

            if ($kind === 'select') {
                $options = array_values(array_filter(
                    (array)($field['options'] ?? []),
                    static fn ($value): bool => $value !== ''
                ));
                if (!empty($options)) {
                    $generated[$field['name']] = $options[random_int(0, count($options) - 1)];
                }
                continue;
            }

            if ($kind === 'image') {
                // Image fields are generated via the dedicated generateImage endpoint.
                continue;
            }

            if ($kind === 'collection') {
                $field['count'] = random_int(self::COLLECTION_MIN_ITEMS, self::COLLECTION_MAX_ITEMS);
            }

            $aiFields[] = $field;
        }

        if (!empty($aiFields)) {
            if (!$this->aiService->isConfigured()) {
                return new JsonResponse([
                    'error' => 'AI API key is not configured. Please set it in Admin Tools > Settings > Extension Configuration > ai_content_generator.',
                ], 500);
            }

            try {
                $aiGenerated = $this->aiService->generateContent($aiFields, $userPrompt);
                $generated = array_merge($generated, $aiGenerated);
            } catch (\Throwable $e) {
                return new JsonResponse(['error' => $e->getMessage()], 500);
            }
        }

        return new JsonResponse(['generated' => $generated]);
    }

    /**
     * Generate a single image via the image API and store it in the default
     * storage (fileadmin/ai_generated/). Returns the sys_file uid + public URL
     * so the frontend can preview it and reference it on save.
     */
    public function generateImageAction(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();

        $prompt = trim((string)($parsedBody['prompt'] ?? ''));
        $fieldLabel = trim((string)($parsedBody['fieldLabel'] ?? ''));

        if ($prompt === '') {
            return new JsonResponse(['error' => 'No prompt provided'], 400);
        }

        if (!$this->aiService->isImageConfigured()) {
            return new JsonResponse([
                'error' => 'Image API key is not configured. Please set it in the AI Content Generator module.',
            ], 500);
        }

        $imagePrompt = $fieldLabel !== ''
            ? sprintf('%s — image for the "%s" field of a website content element.', $prompt, $fieldLabel)
            : $prompt;

        try {
            $binary = $this->aiService->generateImage($imagePrompt);
            $file = $this->storeGeneratedImage($binary);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }

        $publicUrl = (string)$file->getPublicUrl();
        if ($publicUrl !== '' && !str_starts_with($publicUrl, 'http') && !str_starts_with($publicUrl, '/')) {
            $publicUrl = '/' . $publicUrl;
        }

        return new JsonResponse([
            'fileUid' => $file->getUid(),
            'fileName' => $file->getName(),
            'publicUrl' => $publicUrl,
        ]);
    }

    /**
     * Write generated image binary into fileadmin (default storage) under ai_generated/.
     */
    protected function storeGeneratedImage(string $binary): File
    {
        $storage = $this->storageRepository->getDefaultStorage();
        if ($storage === null) {
            throw new \RuntimeException('No default file storage (fileadmin) available.', 1700000020);
        }

        $folder = $storage->hasFolder(self::IMAGE_FOLDER)
            ? $storage->getFolder(self::IMAGE_FOLDER)
            : $storage->createFolder(self::IMAGE_FOLDER);

        // Detect the actual image format — providers return png, webp or jpeg.
        $extension = 'png';
        $imageInfo = @getimagesizefromstring($binary);
        if (is_array($imageInfo)) {
            $extension = match ($imageInfo['mime'] ?? '') {
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                default => 'png',
            };
        }

        $tempFile = GeneralUtility::tempnam('ai_image_', '.' . $extension);
        if (file_put_contents($tempFile, $binary) === false) {
            throw new \RuntimeException('Could not write temporary image file.', 1700000021);
        }

        $fileName = 'ai-image-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $extension;

        return $storage->addFile($tempFile, $folder, $fileName, DuplicationBehavior::RENAME);
    }

    /**
     * Save the generated content to the tt_content record, creating child records
     * for any collection (inline) fields.
     */
    public function saveAction(ServerRequestInterface $request): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();
        $queryParams = $request->getQueryParams();

        $uid = (int)($parsedBody['uid'] ?? $queryParams['uid'] ?? 0);
        $data = (array)($parsedBody['data'] ?? $queryParams['data'] ?? []);
        $collections = (array)($parsedBody['collections'] ?? $queryParams['collections'] ?? []);
        $images = (array)($parsedBody['images'] ?? $queryParams['images'] ?? []);

        if ($uid === 0) {
            return new JsonResponse(['error' => 'No UID provided'], 400);
        }

        if (empty($data) && empty($collections) && empty($images)) {
            return new JsonResponse(['error' => 'No data provided'], 400);
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $parentRow = $queryBuilder
            ->select('uid', 'pid', 'CType')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid)))
            ->executeQuery()
            ->fetchAssociative();

        if (!$parentRow) {
            return new JsonResponse(['error' => 'Record not found'], 404);
        }

        // Re-derive the allowed field schema server-side rather than trusting the client's
        // field names/values outright — this is what actually gets written to the database.
        $fieldSchema = [];
        foreach ($this->getFieldsForCType((string)$parentRow['CType'], $parentRow) as $field) {
            $fieldSchema[$field['name']] = $field;
        }

        $sanitizedData = [];
        foreach ($data as $fieldName => $value) {
            $descriptor = $fieldSchema[$fieldName] ?? null;
            if ($descriptor === null) {
                continue;
            }
            if ($descriptor['kind'] === 'select' && !in_array((string)$value, $descriptor['options'] ?? [], true)) {
                continue;
            }
            if ($descriptor['kind'] === 'collection' || $descriptor['kind'] === 'image') {
                continue;
            }
            $sanitizedData[$fieldName] = (string)$value;
        }

        $dataMap = [
            'tt_content' => [
                $uid => $sanitizedData,
            ],
        ];

        foreach ($collections as $fieldName => $items) {
            $descriptor = $fieldSchema[$fieldName] ?? null;
            if ($descriptor === null || $descriptor['kind'] !== 'collection' || !is_array($items) || empty($items)) {
                continue;
            }

            $childTable = $descriptor['foreignTable'];
            $foreignField = $descriptor['foreignField'];
            $allowedSubFields = array_column($descriptor['itemFields'], 'name');

            $newIds = $this->getExistingChildUids($childTable, $foreignField, $uid);

            foreach (array_values($items) as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $childRow = ['pid' => (int)$parentRow['pid']];
                foreach ($item as $subFieldName => $subValue) {
                    if (!in_array($subFieldName, $allowedSubFields, true)) {
                        continue;
                    }
                    $childRow[$subFieldName] = (string)$subValue;
                }
                if (count($childRow) <= 1) {
                    continue;
                }
                // No underscores: DataHandler's remap stack treats "table_id"-shaped NEW ids as a
                // prefixed reference and would otherwise mis-parse this string when resolving it.
                $newId = 'NEW' . substr(md5(uniqid((string)($uid . $index), true)), 0, 20);
                $dataMap[$childTable][$newId] = $childRow;
                $newIds[] = $newId;
            }

            $dataMap['tt_content'][$uid][$fieldName] = implode(',', $newIds);
        }

        // Attach generated images as sys_file_reference records. For single-image
        // fields (maxitems=1) the new reference replaces existing ones; otherwise
        // it is appended to whatever is already attached.
        foreach ($images as $fieldName => $fileUid) {
            $descriptor = $fieldSchema[$fieldName] ?? null;
            $fileUid = (int)$fileUid;
            if ($descriptor === null || $descriptor['kind'] !== 'image' || $fileUid <= 0) {
                continue;
            }

            $newId = 'NEW' . substr(md5(uniqid('ref' . $fieldName, true)), 0, 20);
            $dataMap['sys_file_reference'][$newId] = [
                'table_local' => 'sys_file',
                'uid_local' => $fileUid,
                'uid_foreign' => $uid,
                'tablenames' => 'tt_content',
                'fieldname' => $fieldName,
                'pid' => (int)$parentRow['pid'],
            ];

            $referenceIds = [$newId];
            if ((int)($descriptor['maxItems'] ?? 0) !== 1) {
                $referenceIds = array_merge($this->getExistingFileReferenceUids($uid, $fieldName), $referenceIds);
            }
            $dataMap['tt_content'][$uid][$fieldName] = implode(',', $referenceIds);
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($dataMap, []);
        $dataHandler->process_datamap();

        if (!empty($dataHandler->errorLog)) {
            return new JsonResponse(['error' => 'Save failed: ' . implode(', ', $dataHandler->errorLog)], 500);
        }

        return new JsonResponse(['success' => true, 'uid' => $uid]);
    }

    /**
     * Existing sys_file_reference uids for a tt_content file field, so new images can
     * be appended to multi-image fields without detaching current ones.
     */
    protected function getExistingFileReferenceUids(int $parentUid, string $fieldName): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $uids = $queryBuilder
            ->select('uid')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($parentUid)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter('tt_content')),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($fieldName))
            )
            ->orderBy('sorting_foreign')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('strval', $uids);
    }

    /**
     * Existing child record uids for an inline/collection relation, so newly AI-generated
     * items are appended rather than replacing whatever content already exists.
     */
    protected function getExistingChildUids(string $childTable, string $foreignField, int $parentUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($childTable);
        $uids = $queryBuilder
            ->select('uid')
            ->from($childTable)
            ->where($queryBuilder->expr()->eq($foreignField, $queryBuilder->createNamedParameter($parentUid)))
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('strval', $uids);
    }

    /**
     * Extract the available fields for a given CType from TCA.
     */
    protected function getFieldsForCType(string $cType, array $record): array
    {
        $tca = $GLOBALS['TCA']['tt_content'] ?? [];
        $fields = [];

        if (!isset($tca['types'][$cType])) {
            $cType = 'text';
        }

        $typeConfig = $tca['types'][$cType] ?? [];
        $showItems = $typeConfig['showitem'] ?? '';

        // Parse showitem string: "field1, --palette--;;paletteName, field2, --div--;Tab, field3"
        $parts = GeneralUtility::trimExplode(',', $showItems, true);

        foreach ($parts as $part) {
            if (str_starts_with($part, '--palette--')) {
                $paletteParts = GeneralUtility::trimExplode(';;', $part, true);
                $paletteName = $paletteParts[1] ?? '';
                if ($paletteName !== '' && isset($tca['palettes'][$paletteName]['showitem'])) {
                    foreach (GeneralUtility::trimExplode(',', $tca['palettes'][$paletteName]['showitem'], true) as $paletteField) {
                        $fieldName = trim(explode(';;', $paletteField)[0]);
                        $described = $this->describeField($fieldName, $tca, $record);
                        if ($described !== null) {
                            $fields[] = $described;
                        }
                    }
                }
                continue;
            }

            if (str_starts_with($part, '--div--')) {
                continue;
            }

            $fieldName = trim(explode(';;', $part)[0]);
            $described = $this->describeField($fieldName, $tca, $record);
            if ($described !== null) {
                $fields[] = $described;
            }
        }

        return $fields;
    }

    /**
     * Describe a single TCA column as an AI-fillable field, or return null if it isn't one.
     * Returns one of three "kinds": text, select, or collection (see class docblock).
     */
    protected function describeField(string $fieldName, array $tca, ?array $record = null): ?array
    {
        if ($fieldName === '' || in_array($fieldName, self::EXCLUDED_FIELDS, true)) {
            return null;
        }

        if (!isset($tca['columns'][$fieldName])) {
            return null;
        }

        $columnConfig = $tca['columns'][$fieldName]['config'] ?? [];
        $type = $columnConfig['type'] ?? 'input';
        $label = $this->resolveLabel((string)($tca['columns'][$fieldName]['label'] ?? $fieldName));
        $label = $label !== '' ? $label : $fieldName;

        if ($type === 'select' && !empty($columnConfig['items'])) {
            $options = [];
            foreach ($columnConfig['items'] as $item) {
                $value = (string)($item['value'] ?? '');
                if ($value !== '') {
                    $options[] = $value;
                }
            }
            if (empty($options)) {
                return null;
            }
            return [
                'name' => $fieldName,
                'label' => $label,
                'kind' => 'select',
                'options' => $options,
            ];
        }

        if ($type === 'file') {
            // FAL file field (image, assets, media, Content Blocks file fields …).
            // Only offer it when images are accepted.
            $allowed = strtolower((string)($columnConfig['allowed'] ?? ''));
            $acceptsImages = $allowed === ''
                || str_contains($allowed, 'common-image-types')
                || str_contains($allowed, 'png')
                || str_contains($allowed, 'jpg')
                || str_contains($allowed, 'jpeg')
                || str_contains($allowed, 'webp')
                || str_contains($allowed, 'gif');
            if (!$acceptsImages) {
                return null;
            }
            return [
                'name' => $fieldName,
                'label' => $label,
                'kind' => 'image',
                'maxItems' => (int)($columnConfig['maxitems'] ?? 0),
            ];
        }

        if ($type === 'inline' && !empty($columnConfig['foreign_table'])) {
            $foreignTable = (string)$columnConfig['foreign_table'];
            $itemFields = $this->getForeignTableFields($foreignTable);
            if (empty($itemFields)) {
                return null;
            }
            return [
                'name' => $fieldName,
                'label' => $label,
                'kind' => 'collection',
                'foreignTable' => $foreignTable,
                'foreignField' => (string)($columnConfig['foreign_field'] ?? 'foreign_table_parent_uid'),
                'minItems' => self::COLLECTION_MIN_ITEMS,
                'maxItems' => self::COLLECTION_MAX_ITEMS,
                'itemFields' => $itemFields,
            ];
        }

        if (in_array($type, self::GENERATABLE_TYPES, true)) {
            return [
                'name' => $fieldName,
                'label' => $label,
                'kind' => 'text',
                'type' => $type,
                'value' => (string)(($record ?? [])[$fieldName] ?? ''),
            ];
        }

        return null;
    }

    /**
     * Generatable text fields of a collection's foreign_table (one level deep only —
     * nested collections/selects inside a collection item are not supported).
     */
    protected function getForeignTableFields(string $foreignTable): array
    {
        $childTca = $GLOBALS['TCA'][$foreignTable] ?? [];
        if (empty($childTca['columns'])) {
            return [];
        }

        $types = $childTca['types'] ?? [];
        $typeConfig = $types[array_key_first($types)] ?? [];
        $showItems = $typeConfig['showitem'] ?? '';

        if ($showItems !== '') {
            $fieldNames = [];
            foreach (GeneralUtility::trimExplode(',', $showItems, true) as $part) {
                if (str_starts_with($part, '--')) {
                    continue;
                }
                $fieldNames[] = trim(explode(';;', $part)[0]);
            }
        } else {
            $fieldNames = array_keys($childTca['columns']);
        }

        $itemFields = [];
        foreach ($fieldNames as $fieldName) {
            $described = $this->describeField($fieldName, $childTca);
            if ($described !== null && $described['kind'] === 'text') {
                $itemFields[] = $described;
            }
        }

        return $itemFields;
    }

    /**
     * Resolve a label reference to a human-readable string via the backend LanguageService.
     * TCA labels come in two forms: the classic "LLL:EXT:ext/path/labels.xlf:key" and, since
     * TYPO3 v14, a shorthand domain reference like "frontend.db.tt_content:header" with no
     * "LLL:" prefix. LanguageService::sL() natively resolves both and falls back to returning
     * the input unchanged if nothing is found, so it's always safe to try.
     */
    protected function resolveLabel(string $label): string
    {
        if ($label === '') {
            return $label;
        }

        $languageService = $GLOBALS['LANG'] ?? null;
        if ($languageService instanceof LanguageService) {
            $resolved = $languageService->sL($label);
            if ($resolved !== '') {
                return $resolved;
            }
        }

        // Fallback heuristic if no LanguageService is available in this context.
        if (str_starts_with($label, 'LLL:')) {
            $parts = explode(':', $label);
            if (count($parts) >= 3) {
                $keyParts = explode('.', end($parts));
                return ucfirst(end($keyParts));
            }
        }

        return $label;
    }
}
