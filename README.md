# AI Content Generator for TYPO3 v14

AI-powered content generator extension for TYPO3 v14. Adds an "AI" button to every content element in the page module. Clicking it opens a dialog listing the element's fields, where you can enter a prompt and generate content (text and images) with AI.

## Features

- **Works with all content elements** — core elements and custom ones (including Content Blocks)
- **Automatic field detection** — lists fields based on the element's CType from TCA (text, select, collection/inline, image/file fields)
- **Text generation** — generates content for text fields via an OpenAI-compatible API (OpenAI, OpenRouter, Ollama, ...)
- **Image generation** — generates images with separate (optionally different provider) credentials and stores them in `fileadmin/ai_generated/`
- **Editable results** — review and edit generated content before saving
- **Safe saving** — writes changes via the TYPO3 DataHandler

## Installation

This package is **not on Packagist**, so Composer needs to be told where to find it first. Add this to your project's root `composer.json` — if you already have a `repositories` key, just add `ai-content-generator` as a new entry inside it:

```json
"repositories": {
  "ai-content-generator": {
    "type": "vcs",
    "url": "https://github.com/musayazlik/ai-content-generator.git"
  }
}
```

Then install:

```bash
composer require musayazlik/ai-content-generator
```

Skipping the `repositories` step causes:
`Could not find a matching version of package musayazlik/ai-content-generator...`

See **[INSTALL.md](INSTALL.md)** for the full guide (including an offline/zip option).

### Configuration

1. In the backend, go to **System > AI Content Generator**
2. **Text Generation** section: API Key, Model, API Endpoint, System Prompt
3. **Image Generation** section (separate, optional): API Key, Model, API Endpoint, Image Size
4. Click **Save Settings**

## Usage

1. Go to the **Web > Page** module
2. You'll see an **AI** button in the header of every content element
3. Click it
4. In the dialog that opens:
   - Select the fields you want filled (text, select, collection, image)
   - Write your content instruction (e.g. "Write a compelling promotional text for our product")
   - Click **Generate Content**
5. AI shows the generated content (and any images) for each field
6. Edit the content if needed (optional)
7. Click **Fill** — the content is saved and the page reloads

## Architecture

```
ai_content_generator/
├── Classes/
│   ├── Backend/Form/Element/
│   │   └── AiContentGeneratorElement.php       # AI tab/button in the record edit form
│   ├── Controller/
│   │   ├── AiContentGeneratorController.php    # AJAX endpoints (getFields, generate, generateImage, save)
│   │   └── SettingsModuleController.php        # Settings module controller
│   ├── Service/
│   │   ├── AiService.php                       # Text + image API integration
│   │   └── SettingsService.php                 # Stores settings in sys_registry
│   └── EventListener/
│       └── PageLayoutAssetsEventListener.php   # Injects JS/CSS into the page module
├── Configuration/
│   ├── Backend/
│   │   ├── AjaxRoutes.php                      # AJAX route definitions
│   │   └── Modules.php                         # Settings module registration
│   ├── TCA/Overrides/tt_content.php
│   ├── Icons.php                               # Module icon (must live at the Configuration root!)
│   ├── JavaScriptModules.php                   # JS importmap (must live at the Configuration root!)
│   └── Services.yaml                           # Dependency injection
├── Resources/
│   ├── Private/
│   │   ├── Language/locallang.xlf, locallang_mod.xlf
│   │   └── Templates/SettingsModule/Index.html
│   └── Public/
│       ├── JavaScript/ai-content-generator.js  # Main JS module (button + modal)
│       ├── Css/ai-content-generator.css        # Styles (follows the backend theme)
│       └── Icons/Extension.svg                 # Extension icon
├── composer.json
├── ext_localconf.php                           # FormEngine node registration
├── ext_emconf.php                              # TER metadata
└── INSTALL.md                                  # Composer-mode installation guide
```

## AJAX Endpoints

| Route | Description |
|-------|-------------|
| `/ai-content-generator/get-fields` | Returns the fields of the content element for a given UID |
| `/ai-content-generator/generate` | Calls the AI API to generate content for text/select/collection fields |
| `/ai-content-generator/generate-image` | Calls the image API to generate an image and stores it in fileadmin |
| `/ai-content-generator/save` | Saves the generated content (and image references) via DataHandler |

## Requirements

- TYPO3 v14.3+
- PHP 8.2+
- An OpenAI-compatible text API (required); a separate image API for image generation (optional)

## AI Provider Examples

**Text generation:**
- **OpenAI**: `https://api.openai.com/v1/chat/completions` + `gpt-4o-mini`
- **OpenRouter**: `https://openrouter.ai/api/v1/chat/completions` + any model of your choice
- **Ollama (local)**: `http://localhost:11434/v1/chat/completions` + `llama3`

**Image generation:**
- **OpenAI**: `https://api.openai.com/v1/images/generations` + `gpt-image-1` / `dall-e-3`
- **OpenRouter**: `https://openrouter.ai/api/v1/images` + `openai/gpt-image-1` / `google/gemini-2.5-flash-image`

## Author

**Musa Yazlık**
- Email: info@musayazlik.com
