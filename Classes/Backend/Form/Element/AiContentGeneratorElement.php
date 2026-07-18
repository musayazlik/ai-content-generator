<?php

declare(strict_types=1);

namespace MusaYazlik\AiContentGenerator\Backend\Form\Element;

use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Custom FormEngine element that renders an "AI Generate" button
 * inside the content element editing form on a dedicated tab.
 */
class AiContentGeneratorElement extends AbstractFormElement
{
    protected PageRenderer $pageRenderer;

    public function __construct(PageRenderer $pageRenderer)
    {
        $this->pageRenderer = $pageRenderer;
    }

    public function render(): array
    {
        $result = $this->initializeResultArray();

        // Load JS module and CSS for the edit form context
        $this->pageRenderer->loadJavaScriptModule('@musayazlik/ai-content-generator/ai-content-generator.js');
        $this->pageRenderer->addCssFile('EXT:ai_content_generator/Resources/Public/Css/ai-content-generator.css');

        $row = $this->data['databaseRow'] ?? [];
        $rawUid = $row['uid'] ?? 0;
        $uid = is_array($rawUid) ? (int)($rawUid[0] ?? 0) : (int)$rawUid;
        $rawCType = $row['CType'] ?? '';
        $cType = is_array($rawCType) ? (string)($rawCType[0] ?? '') : (string)$rawCType;

        if ($uid === 0) {
            $result['html'] = '<div class="alert alert-info">Kayıt kaydedildikten sonra AI ile içerik oluşturabilirsiniz.</div>';
            return $result;
        }

        $result['html'] = <<<HTML
<div class="ai-form-tab-wrapper" style="padding: 12px 0;">
    <p class="ai-form-tab-description" style="margin-bottom: 12px; color: #666; font-size: 13px;">
        AI ile bu içerik elementinin alanlarını otomatik doldurun. Aşağıdaki butona tıklayın, alanları seçin ve prompt'unuzu yazın.
    </p>
    <button
        type="button"
        class="btn btn-primary ai-form-generate-btn"
        data-uid="{$uid}"
        data-ctype="{$cType}"
        style="display: inline-flex; align-items: center; gap: 6px;"
    >
        <span class="t3js-icon" aria-hidden="true">
            <typo3-backend-icon identifier="actions-wand-sparkles" size="small"></typo3-backend-icon>
        </span>
        AI ile İçerik Oluştur
    </button>
</div>
HTML;

        return $result;
    }
}
