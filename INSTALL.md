# Installation (Composer Mode)

This package is intended for a Composer-managed TYPO3 v14 installation (not the classic `typo3conf/ext/` install).

## Option A — Via Git (recommended, one command after initial setup)

The package is published at [github.com/musayazlik/ai-content-generator](https://github.com/musayazlik/ai-content-generator) (public repository).

Add this to your project's **root** `composer.json`:

```json
"repositories": [
  {
    "type": "vcs",
    "url": "https://github.com/musayazlik/ai-content-generator.git"
  }
]
```

Then install with a single command:

```bash
composer require musayazlik/ai-content-generator
```

Since the repository is public, no authentication token is required. To update to a newer release later:

```bash
composer update musayazlik/ai-content-generator
```

## Option B — From a local zip (offline / path repository)

## 1. Place the package

Extract the zip contents into a folder inside your TYPO3 project, e.g.:

```
your-typo3-project/
  packages/
    ai_content_generator/   <- zip contents go here
```

## 2. Add a path repository

Add the following `repositories` entry to your project's **root** `composer.json` (create the key if it doesn't exist yet):

```json
"repositories": [
  {
    "type": "path",
    "url": "packages/ai_content_generator"
  }
]
```

## 3. Add the dependency

In the same `composer.json`, under `require`:

```json
"require": {
  "musayazlik/ai-content-generator": "@dev"
}
```

## 4. Run the install

```bash
composer update musayazlik/ai-content-generator
```

This creates the `vendor/musayazlik/ai-content-generator` symlink and updates the autoload map.

## Activate the extension

TYPO3 Backend → **Admin Tools > Extensions** → find **"AI Content Generator"** in the list → click **Activate**.

## Configure API settings

In the backend, go to **System > AI Content Generator** and fill in:
- **Text Generation**: API key, model, endpoint (OpenAI/OpenRouter/Ollama compatible)
- **Image Generation**: separate API key, model, endpoint (for image generation)

Then click **Save Settings**. Generated images are stored in the `fileadmin/ai_generated/` folder.

## Requirements

- TYPO3 14.3 or higher
- PHP 8.2 or higher
- A Composer-managed TYPO3 installation

No additional third-party dependencies are required — everything (including Guzzle) comes from TYPO3 core.
