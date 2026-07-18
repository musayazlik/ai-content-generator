/**
 * AI Content Generator — TYPO3 v14 backend module
 *
 * Injects an "AI Generate" button into each content element in the page module.
 * When clicked, opens a modal dialog that:
 *   1. Lists the available fields of the content element
 *   2. Lets the user enter a prompt
 *   3. Generates content via AI (AJAX call to backend)
 *   4. Shows the generated content (editable)
 *   5. Saves the content to the record on "Fill"
 */

import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Icons from '@typo3/backend/icons.js';

const SELECTORS = {
  // TYPO3 v14 page module content element containers
  contentElement: '.t3-page-ce',
  contentElementDataUid: '[data-uid]',
  contentElementHeader: '.t3-page-ce-header',
  contentElementActions: '.t3-page-ce-actions',
  // Control buttons in header — edit, delete, and more (3-dot) menu
  editButton: '.t3js-page-ce-edit, a[data-action="edit"], .btn[data-action="edit"]',
  deleteButton: '.t3js-page-ce-delete, a[data-action="delete"], button[data-action="delete"], .btn[data-action="delete"], .t3-page-ce-actions .btn[title*="Delete"], .t3-page-ce-actions .btn[title*="Sil"], .t3-page-ce-actions a[href*="delete"]',
  moreButton: '.t3js-page-ce-more, .dropdown-toggle, .btn[title*="More"], .btn[title*="Daha"]',
  // Fallback selectors for different TYPO3 versions
  contentElementAlt: '.element-preview',
  contentElementHeaderAlt: '.element-preview-header',
};

/**
 * Markup for the in-progress state shown inside the results area — the toast
 * notifications are easy to miss, so this stays on screen for the whole
 * generation step (can take well over a minute for image models).
 */
function renderLoadingState(message) {
  return `<div class="ai-loading-state"><typo3-backend-spinner size="large"></typo3-backend-spinner><p>${message}</p></div>`;
}

const BUTTON_CLASS = 'ai-content-generator-btn';
// NOTE: 'actions-magic' does not exist in the TYPO3 v14 core icon set — the
// registry silently falls back to the default record icon. Use the wand icon.
const BUTTON_ICON = 'actions-wand-sparkles';

// Bump this on every CSS/markup change. The TYPO3 backend is a long-lived
// single page (only the module iframe's src changes when navigating) and the
// top frame document is never reloaded on its own, so without a version query
// string a stylesheet <link> injected before an edit would keep serving the
// browser's cached, stale CSS for the rest of that tab's session.
const CSS_VERSION = '2';

// Resolved against this module's own public URL so it works in both classic and
// composer mode (_assets/<hash>/JavaScript/ → _assets/<hash>/Css/).
const CSS_URL = new URL('../Css/ai-content-generator.css', import.meta.url).href + '?v=' + CSS_VERSION;
const CSS_MARKER = 'data-ai-content-generator-css';

/**
 * Inject (or refresh) the extension stylesheet in the given document.
 *
 * Needed because TYPO3's Modal singleton lives in the top frame: when this module
 * runs inside the module iframe (list_frame), Modal.advanced() appends the
 * <typo3-backend-modal> element to the TOP frame's document.body — where CSS
 * loaded via PageRenderer::addCssFile() (iframe document only) never applies.
 *
 * Always re-points an existing link's href to the current CSS_URL (rather than
 * no-op'ing once a link exists) so bumping CSS_VERSION busts the browser cache
 * on the very next modal open, without requiring a full backend reload.
 */
function ensureStyles(doc) {
  try {
    if (!doc) {
      return;
    }
    let link = doc.querySelector(`link[${CSS_MARKER}]`);
    if (!link) {
      link = doc.createElement('link');
      link.rel = 'stylesheet';
      link.setAttribute(CSS_MARKER, '');
      doc.head.appendChild(link);
    }
    if (link.href !== CSS_URL) {
      link.href = CSS_URL;
    }
  } catch (e) {
    // Cross-origin or detached document — nothing we can do.
  }
}

// Small inline icons for the modal section headers — kept as plain SVG so they
// render immediately without waiting on a TYPO3 icon-set lookup.
const ICON_CLASS = 'ai-section-icon';
const SECTION_ICONS = {
  fields: `<svg class="${ICON_CLASS}" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>`,
  prompt: `<svg class="${ICON_CLASS}" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>`,
  results: `<svg class="${ICON_CLASS}" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M9.9 2.6 8.6 5.7a4 4 0 0 1-2.9 2.9L2.6 9.9l3.1 1.3a4 4 0 0 1 2.9 2.9l1.3 3.1 1.3-3.1a4 4 0 0 1 2.9-2.9l3.1-1.3-3.1-1.3a4 4 0 0 1-2.9-2.9L9.9 2.6Z"/><path d="M18.5 15.5v3m-1.5-1.5h3"/></svg>`,
};

/**
 * Extract a human-readable message from a failed AjaxRequest.
 *
 * TYPO3's AjaxRequest throws the AjaxResponse object itself (not an Error) for
 * non-2xx responses, so `error.message` is undefined — resolve its JSON body to
 * surface the actual server-side error message instead.
 */
async function extractErrorMessage(error) {
  if (error && typeof error.resolve === 'function') {
    try {
      const data = await error.resolve();
      if (data && typeof data.error === 'string' && data.error !== '') {
        return data.error;
      }
    } catch (e) {
      // Body was not JSON — fall through to the status code.
    }
    if (error.response && error.response.status) {
      return `HTTP ${error.response.status}`;
    }
  }
  return error && error.message ? error.message : String(error);
}

/**
 * Initialize the module.
 */
function init() {
  // Make sure our styles exist in this frame (edit form, page module, …)
  ensureStyles(document);

  // Run in page module (inject buttons into content elements)
  const pageModule = document.querySelector('.typo3-TCEforms, #PageModuleDocument, .module[data-module-name="web_layout"]');
  const hasContentElements = document.querySelector('.t3-page-ce, .element-preview');

  if (pageModule || hasContentElements) {
    injectButtons();
    observePageChanges();
  }

  // Also run in edit form context (AI tab in content element editing)
  bindEditFormButton();
  observeEditFormForButton();
}

/**
 * Bind click handler to the AI button rendered in the edit form tab.
 */
function bindEditFormButton() {
  const buttons = document.querySelectorAll('.ai-form-generate-btn:not([data-bound])');
  buttons.forEach((btn) => {
    btn.setAttribute('data-bound', 'true');
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const uid = parseInt(btn.getAttribute('data-uid') || '0', 10);
      if (uid > 0) {
        openAiModal(uid);
      } else {
        Notification.warning('Warning', 'AI can be used after the record has been saved.', 5);
      }
    });
  });
}

/**
 * Observe the edit form for dynamically loaded AI buttons (e.g. when switching tabs).
 */
function observeEditFormForButton() {
  const observer = new MutationObserver(() => {
    bindEditFormButton();
  });
  observer.observe(document.body, { childList: true, subtree: true });
}

/**
 * Inject AI Generate buttons into all content elements.
 */
function injectButtons() {
  const contentElements = document.querySelectorAll(
    `${SELECTORS.contentElement}, ${SELECTORS.contentElementAlt}`
  );

  contentElements.forEach((element) => {
    if (element.querySelector(`.${BUTTON_CLASS}`)) {
      return; // Already has a button
    }

    const uid = element.getAttribute('data-uid') || extractUidFromElement(element);
    if (!uid) {
      return;
    }

    const button = createAiButton(uid);

    // Strategy: Place AI button as the leftmost action button, before Edit
    // TYPO3 v14 header order is typically: [edit] [toggle] [delete] [more-menu]
    // We want: [AI] [edit] [toggle] [delete] [more-menu]

    // 1. Try inserting before the edit button
    const editBtn = element.querySelector(SELECTORS.editButton);
    if (editBtn && editBtn.parentNode) {
      editBtn.parentNode.insertBefore(button, editBtn);
      return;
    }

    // 2. Fallback: prepend to actions container or header
    const actionsContainer = element.querySelector(
      `${SELECTORS.contentElementActions}, ${SELECTORS.contentElementHeader}, ${SELECTORS.contentElementHeaderAlt}`
    );
    if (actionsContainer) {
      actionsContainer.insertBefore(button, actionsContainer.firstChild);
    }
  });
}

/**
 * Extract the UID from a content element by looking at edit links.
 */
function extractUidFromElement(element) {
  const editLink = element.querySelector('a[href*="edit"][href*="tt_content"]');
  if (editLink) {
    const href = editLink.getAttribute('href') || '';
    const match = href.match(/uid=(\d+)/) || href.match(/\[tt_content\]\[(\d+)\]/);
    if (match) {
      return match[1];
    }
  }
  // Try data-id attribute
  const dataId = element.getAttribute('data-id') || element.getAttribute('data-uid');
  return dataId;
}

/**
 * Create the AI Generate button element.
 */
function createAiButton(uid) {
  const button = document.createElement('button');
  button.className = `btn btn-default btn-sm ${BUTTON_CLASS}`;
  button.setAttribute('data-uid', uid);
  button.setAttribute('title', 'Generate content with AI');
  button.type = 'button';

  // Add icon (loaded asynchronously)
  Icons.getIcon(BUTTON_ICON, Icons.sizes.small).then((icon) => {
    button.innerHTML = icon + '<span class="ai-btn-label">AI</span>';
  });

  button.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    openAiModal(parseInt(uid, 10));
  });

  return button;
}

/**
 * Observe page changes (for dynamic content loading).
 */
function observePageChanges() {
  const observer = new MutationObserver((mutations) => {
    let shouldReinject = false;
    for (const mutation of mutations) {
      if (mutation.addedNodes.length > 0) {
        shouldReinject = true;
        break;
      }
    }
    if (shouldReinject) {
      observer.disconnect();
      injectButtons();
      observePageChanges();
    }
  });

  const target = document.querySelector('.typo3-TCEforms, #PageModuleDocument, .module') || document.body;
  observer.observe(target, { childList: true, subtree: true });
}

/**
 * Open the AI Content Generator modal dialog.
 */
async function openAiModal(uid) {
  // Step 1: Fetch fields for this content element
  Notification.info('Loading', 'Fetching content element fields...', 2);

  try {
    const fieldsResponse = await new AjaxRequest(TYPO3.settings.ajaxUrls.ai_content_generator_get_fields)
      .post({ uid });

    const fieldsData = await fieldsResponse.resolve();

    if (fieldsData.error) {
      Notification.error('Error', fieldsData.error, 5);
      return;
    }

    if (!fieldsData.fields || fieldsData.fields.length === 0) {
      Notification.warning('Warning', 'No AI-fillable fields were found for this content element.', 5);
      return;
    }

    showGenerationModal(uid, fieldsData.fields, fieldsData.cType);
  } catch (error) {
    Notification.error('Error', 'An error occurred while loading fields: ' + (await extractErrorMessage(error)), 5);
  }
}

/**
 * Show the main AI generation modal.
 */
function showGenerationModal(uid, fields, cType) {
  const modalTitle = 'AI Content Generator';

  // Build modal content
  const content = document.createElement('div');
  content.className = 'ai-content-generator-modal';

  // Step 1: Field selection
  const fieldSection = document.createElement('div');
  fieldSection.className = 'ai-section';

  const fieldHeader = document.createElement('div');
  fieldHeader.className = 'ai-section-header';
  fieldHeader.innerHTML = SECTION_ICONS.fields;
  const fieldTitle = document.createElement('h4');
  fieldTitle.className = 'ai-section-title';
  fieldTitle.textContent = 'Fields to Fill';
  fieldHeader.appendChild(fieldTitle);
  fieldSection.appendChild(fieldHeader);

  const fieldDescription = document.createElement('p');
  fieldDescription.className = 'ai-section-desc';
  fieldDescription.textContent = 'Select the fields you want AI to fill:';
  fieldSection.appendChild(fieldDescription);

  const fieldList = document.createElement('div');
  fieldList.className = 'ai-field-grid';

  fields.forEach((field) => {
    const fieldItem = document.createElement('label');
    fieldItem.className = 'ai-field-card';
    fieldItem.htmlFor = `ai-field-${field.name}`;

    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.checked = true;
    checkbox.setAttribute('data-field-name', field.name);
    checkbox.id = `ai-field-${field.name}`;
    checkbox.className = 'ai-field-checkbox';
    checkbox.addEventListener('change', () => {
      fieldItem.classList.toggle('is-checked', checkbox.checked);
    });

    const labelText = document.createElement('span');
    labelText.className = 'ai-field-text';

    const nameSpan = document.createElement('span');
    nameSpan.className = 'ai-field-name';
    nameSpan.textContent = field.label;
    labelText.appendChild(nameSpan);

    const meta = document.createElement('span');
    meta.className = 'ai-field-badge';
    if (field.kind === 'select') {
      meta.textContent = 'Select list';
    } else if (field.kind === 'collection') {
      meta.textContent = `${field.minItems}-${field.maxItems} records`;
    } else if (field.kind === 'image') {
      meta.textContent = 'AI Image';
    } else {
      meta.textContent = field.type;
    }
    labelText.appendChild(meta);

    fieldItem.appendChild(checkbox);
    fieldItem.appendChild(labelText);
    fieldItem.classList.toggle('is-checked', checkbox.checked);
    fieldList.appendChild(fieldItem);
  });

  fieldSection.appendChild(fieldList);
  content.appendChild(fieldSection);

  // Step 2: Prompt input
  const promptSection = document.createElement('div');
  promptSection.className = 'ai-section';

  const promptHeader = document.createElement('div');
  promptHeader.className = 'ai-section-header';
  promptHeader.innerHTML = SECTION_ICONS.prompt;
  const promptTitle = document.createElement('h4');
  promptTitle.className = 'ai-section-title';
  promptTitle.textContent = 'Content Instruction';
  promptHeader.appendChild(promptTitle);
  promptSection.appendChild(promptHeader);

  const promptDesc = document.createElement('p');
  promptDesc.className = 'ai-section-desc';
  promptDesc.textContent = 'Describe the content you want to generate:';
  promptSection.appendChild(promptDesc);

  const promptInput = document.createElement('textarea');
  promptInput.className = 'ai-prompt-input';
  promptInput.rows = 4;
  promptInput.placeholder = 'E.g.: Write a compelling promotional text for this product. Target audience: small and medium-sized businesses.';
  promptSection.appendChild(promptInput);

  content.appendChild(promptSection);

  // Step 3: Results area (hidden initially)
  const resultsSection = document.createElement('div');
  resultsSection.className = 'ai-section ai-results-section is-hidden';

  const resultsHeader = document.createElement('div');
  resultsHeader.className = 'ai-section-header';
  resultsHeader.innerHTML = SECTION_ICONS.results;
  const resultsTitle = document.createElement('h4');
  resultsTitle.className = 'ai-section-title';
  resultsTitle.textContent = 'Generated Content';
  resultsHeader.appendChild(resultsTitle);
  resultsSection.appendChild(resultsHeader);

  const resultsDesc = document.createElement('p');
  resultsDesc.className = 'ai-section-desc';
  resultsDesc.textContent = 'You can edit the content. It will be saved when you press "Fill".';
  resultsSection.appendChild(resultsDesc);

  const resultsContainer = document.createElement('div');
  resultsContainer.className = 'ai-results-container';
  resultsSection.appendChild(resultsContainer);

  content.appendChild(resultsSection);

  // Create modal
  const modal = Modal.advanced({
    title: modalTitle,
    content: content,
    size: Modal.sizes.large,
    buttons: [
      {
        text: 'Generate Content',
        name: 'generate',
        icon: BUTTON_ICON,
        btnClass: 'btn-primary',
        trigger: () => onGenerateClick(modal, uid, fields, fieldList, promptInput, resultsSection, resultsContainer),
      },
      {
        text: 'Fill',
        name: 'fill',
        icon: 'actions-save',
        btnClass: 'btn-success',
        active: false,
        trigger: () => onFillClick(modal, uid, resultsContainer),
      },
      {
        text: 'Close',
        name: 'close',
        icon: 'actions-close',
        btnClass: 'btn-default',
        trigger: () => Modal.dismiss(),
      },
    ],
  });

  // The modal is appended to the top frame's document when this module runs in
  // an iframe — inject our stylesheet there, otherwise the modal is unstyled.
  ensureStyles(modal.ownerDocument);
}

/**
 * Handle "Generate" button click.
 */
async function onGenerateClick(modal, uid, fields, fieldList, promptInput, resultsSection, resultsContainer) {
  const prompt = promptInput.value.trim();

  if (!prompt) {
    Notification.warning('Warning', 'Please enter a content instruction.', 3);
    return;
  }

  // Get selected fields — scoped to this modal's own field list, since field names
  // (e.g. "header") repeat across content element types and a plain document-wide
  // getElementById lookup could resolve to a stale checkbox from another modal instance.
  const checkboxes = Array.from(fieldList.querySelectorAll('input[type="checkbox"][data-field-name]'));
  const selectedFields = fields.filter((field) => {
    const checkbox = checkboxes.find((cb) => cb.getAttribute('data-field-name') === field.name);
    return checkbox && checkbox.checked;
  });

  if (selectedFields.length === 0) {
    Notification.warning('Warning', 'Select at least one field.', 3);
    return;
  }

  const imageFields = selectedFields.filter((field) => field.kind === 'image');
  const textFields = selectedFields.filter((field) => field.kind !== 'image');

  // Show loading state
  const generateBtn = modal.querySelector('[name="generate"]');
  if (generateBtn) {
    generateBtn.setAttribute('disabled', 'disabled');
    generateBtn.innerHTML = '<typo3-backend-spinner size="small"></typo3-backend-spinner> Generating...';
  }

  // Visible for as long as generation takes (image models can take well over
  // a minute) — the toast notifications alone are too easy to miss.
  resultsSection.classList.remove('is-hidden');
  resultsContainer.innerHTML = renderLoadingState('Generating content with AI...');

  Notification.info('AI', 'Generating content with AI...', 3);

  try {
    let generated = {};

    if (textFields.length > 0) {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.ai_content_generator_generate)
        .post({
          fields: textFields,
          prompt: prompt,
        });

      const data = await response.resolve();

      if (data.error) {
        Notification.error('Error', data.error, 8);
        resultsSection.classList.add('is-hidden');
        resultsContainer.innerHTML = '';
        return;
      }
      generated = data.generated || {};
    }

    // Generate images one by one — each is stored in fileadmin/ai_generated/
    // server-side and comes back as { fileUid, publicUrl, fileName }. A failing
    // image must not discard the text results or the remaining images.
    for (const field of imageFields) {
      Notification.info('AI', `Generating image for "${field.label}"...`, 3);
      resultsContainer.innerHTML = renderLoadingState(`Generating image for "${field.label}"... (this may take a while)`);
      try {
        const imageResponse = await new AjaxRequest(TYPO3.settings.ajaxUrls.ai_content_generator_generate_image)
          .post({
            prompt: prompt,
            fieldLabel: field.label,
          });

        const imageData = await imageResponse.resolve();

        if (imageData.error) {
          Notification.error('Error', `Failed to generate image for "${field.label}": ${imageData.error}`, 8);
          continue;
        }
        generated[field.name] = imageData;
      } catch (error) {
        Notification.error('Error', `Failed to generate image for "${field.label}": ` + (await extractErrorMessage(error)), 8);
      }
    }

    // Show results
    displayResults(resultsContainer, generated, selectedFields);
    resultsSection.classList.remove('is-hidden');

    // Enable "Fill" button
    const fillBtn = modal.querySelector('[name="fill"]');
    if (fillBtn) {
      fillBtn.removeAttribute('disabled');
      fillBtn.classList.remove('disabled');
    }

    Notification.success('Success', 'Content generated! You can edit it and press "Fill".', 4);
  } catch (error) {
    Notification.error('Error', 'An error occurred while generating content: ' + (await extractErrorMessage(error)), 8);
    resultsSection.classList.add('is-hidden');
    resultsContainer.innerHTML = '';
  } finally {
    // Restore generate button
    if (generateBtn) {
      generateBtn.removeAttribute('disabled');
      Icons.getIcon(BUTTON_ICON, Icons.sizes.small).then((icon) => {
        generateBtn.innerHTML = icon + ' Generate Content';
      });
    }
  }
}

/**
 * Display generated content in editable fields.
 */
function displayResults(container, generated, fields) {
  container.innerHTML = '';

  fields.forEach((field) => {
    const resultItem = document.createElement('div');
    resultItem.className = 'ai-result-item';

    const label = document.createElement('label');
    label.className = 'ai-result-label';
    label.textContent = field.label;
    label.htmlFor = `ai-result-${field.name}`;
    resultItem.appendChild(label);

    if (field.kind === 'image') {
      const imageData = generated[field.name] || {};
      const preview = document.createElement('div');
      preview.className = 'ai-image-result';
      preview.setAttribute('data-field-name', field.name);
      preview.setAttribute('data-file-uid', String(imageData.fileUid || ''));

      if (imageData.publicUrl) {
        const img = document.createElement('img');
        img.className = 'ai-image-preview';
        img.src = imageData.publicUrl;
        img.alt = field.label;
        preview.appendChild(img);

        const caption = document.createElement('span');
        caption.className = 'ai-image-caption';
        caption.textContent = imageData.fileName || '';
        preview.appendChild(caption);
      } else {
        const failed = document.createElement('span');
        failed.className = 'ai-image-caption';
        failed.textContent = 'Image generation failed.';
        preview.appendChild(failed);
      }

      resultItem.appendChild(preview);
    } else if (field.kind === 'select') {
      const select = document.createElement('select');
      select.className = 'ai-result-input';
      select.id = `ai-result-${field.name}`;
      select.setAttribute('data-field-name', field.name);
      const generatedValue = generated[field.name] ?? '';
      (field.options || []).forEach((optionValue) => {
        const option = document.createElement('option');
        option.value = optionValue;
        option.textContent = optionValue;
        if (optionValue === generatedValue) {
          option.selected = true;
        }
        select.appendChild(option);
      });
      resultItem.appendChild(select);
    } else if (field.kind === 'collection') {
      const collectionContainer = document.createElement('div');
      collectionContainer.className = 'ai-collection-container';
      collectionContainer.setAttribute('data-field-name', field.name);

      const items = Array.isArray(generated[field.name]) ? generated[field.name] : [];
      items.forEach((item, index) => {
        const itemCard = document.createElement('div');
        itemCard.className = 'ai-collection-item';

        const itemTitle = document.createElement('div');
        itemTitle.className = 'ai-collection-item-title';
        itemTitle.textContent = `Record ${index + 1}`;
        itemCard.appendChild(itemTitle);

        (field.itemFields || []).forEach((subField) => {
          const subLabel = document.createElement('label');
          subLabel.className = 'ai-result-label';
          subLabel.textContent = subField.label;
          itemCard.appendChild(subLabel);

          let subInput;
          if (subField.type === 'text' || subField.type === 'textarea' || subField.type === 'richtext') {
            subInput = document.createElement('textarea');
            subInput.rows = 3;
          } else {
            subInput = document.createElement('input');
            subInput.type = 'text';
          }
          subInput.className = 'ai-result-input ai-collection-subfield';
          subInput.setAttribute('data-subfield-name', subField.name);
          subInput.value = item[subField.name] ?? '';
          itemCard.appendChild(subInput);
        });

        collectionContainer.appendChild(itemCard);
      });

      resultItem.appendChild(collectionContainer);
    } else {
      const generatedValue = generated[field.name] ?? '';
      const isMultiline = field.type === 'text' || field.type === 'textarea' || field.type === 'richtext';
      let input;
      if (isMultiline) {
        input = document.createElement('textarea');
        input.rows = 6;
      } else {
        input = document.createElement('input');
        input.type = 'text';
      }
      input.className = isMultiline ? 'ai-result-input is-multiline' : 'ai-result-input';
      input.id = `ai-result-${field.name}`;
      input.setAttribute('data-field-name', field.name);
      input.value = generatedValue;
      resultItem.appendChild(input);
    }

    container.appendChild(resultItem);
  });
}

/**
 * Handle "Fill" button click — save the generated content to the record.
 */
async function onFillClick(modal, uid, resultsContainer) {
  // Collect flat text/select inputs
  const data = {};
  const inputs = resultsContainer.querySelectorAll('.ai-result-input[data-field-name]');

  inputs.forEach((input) => {
    const fieldName = input.getAttribute('data-field-name');
    if (fieldName) {
      data[fieldName] = input.value;
    }
  });

  // Collect collection (repeatable child record) fields
  const collections = {};
  const collectionContainers = resultsContainer.querySelectorAll('.ai-collection-container[data-field-name]');

  collectionContainers.forEach((container) => {
    const fieldName = container.getAttribute('data-field-name');
    const items = [];
    container.querySelectorAll('.ai-collection-item').forEach((itemCard) => {
      const item = {};
      itemCard.querySelectorAll('.ai-collection-subfield[data-subfield-name]').forEach((subInput) => {
        item[subInput.getAttribute('data-subfield-name')] = subInput.value;
      });
      items.push(item);
    });
    if (fieldName && items.length > 0) {
      collections[fieldName] = items;
    }
  });

  // Collect generated images (already stored in fileadmin; save attaches references)
  const images = {};
  resultsContainer.querySelectorAll('.ai-image-result[data-field-name]').forEach((preview) => {
    const fieldName = preview.getAttribute('data-field-name');
    const fileUid = parseInt(preview.getAttribute('data-file-uid') || '0', 10);
    if (fieldName && fileUid > 0) {
      images[fieldName] = fileUid;
    }
  });

  if (Object.keys(data).length === 0 && Object.keys(collections).length === 0 && Object.keys(images).length === 0) {
    Notification.warning('Warning', 'No data found to save.', 3);
    return;
  }

  const fillBtn = modal.querySelector('[name="fill"]');
  if (fillBtn) {
    fillBtn.setAttribute('disabled', 'disabled');
    fillBtn.innerHTML = '<typo3-backend-spinner size="small"></typo3-backend-spinner> Saving...';
  }

  Notification.info('Saving', 'Saving content...', 3);

  try {
    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.ai_content_generator_save)
      .post({
        uid: uid,
        data: data,
        collections: collections,
        images: images,
      });

    const result = await response.resolve();

    if (result.error) {
      Notification.error('Error', result.error, 8);
      return;
    }

    Notification.success('Success', 'Content saved! Reloading page...', 3);
    Modal.dismiss();

    // Reload the page module to show the updated content
    setTimeout(() => {
      window.location.reload();
    }, 1000);
  } catch (error) {
    Notification.error('Error', 'An error occurred while saving: ' + (await extractErrorMessage(error)), 8);
  } finally {
    if (fillBtn) {
      fillBtn.removeAttribute('disabled');
      Icons.getIcon('actions-save', Icons.sizes.small).then((icon) => {
        fillBtn.innerHTML = icon + ' Fill';
      });
    }
  }
}

// Initialize on DOM ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
