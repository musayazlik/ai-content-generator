# Kurulum (Composer Mode)

Bu paket, Composer ile yönetilen bir TYPO3 v14 kurulumu için hazırlanmıştır (klasik `typo3conf/ext/` kurulumu için değil).

## 1. Paketi yerleştirin

Zip içeriğini TYPO3 projenizin içinde bir klasöre çıkarın, örneğin:

```
your-typo3-project/
  packages/
    ai_content_generator/   <- zip içeriği buraya
```

## 2. Path repository ekleyin

Projenizin **kök** `composer.json` dosyasına aşağıdaki `repositories` girdisini ekleyin (yoksa oluşturun):

```json
"repositories": [
  {
    "type": "path",
    "url": "packages/ai_content_generator"
  }
]
```

## 3. Bağımlılığı ekleyin

Aynı `composer.json`'ın `require` bölümüne:

```json
"require": {
  "musayazlik/ai-content-generator": "@dev"
}
```

## 4. Kurulumu çalıştırın

```bash
composer update musayazlik/ai-content-generator
```

Bu komut `vendor/musayazlik/ai-content-generator` sembolik bağlantısını oluşturur ve autoload haritasını günceller.

## 5. Extension'ı aktive edin

TYPO3 Backend → **Admin Tools > Extensions** → listede **"AI Content Generator"**'ı bulup **Activate** (etkinleştir) butonuna basın.

## 6. API ayarlarını yapın

Backend sol menüde **System > AI Content Generator** modülüne girip:
- **Text Generation**: API key, model, endpoint (OpenAI/OpenRouter/Ollama uyumlu)
- **Image Generation**: ayrı API key, model, endpoint (görsel üretimi için)

bilgilerini girip **Save Settings**'e basın. Üretilen görseller `fileadmin/ai_generated/` klasörüne kaydedilir.

## Gereksinimler

- TYPO3 14.3 veya üzeri
- PHP 8.2 veya üzeri
- Composer mode kurulum (composer-managed TYPO3)

Ekstra bir üçüncü parti bağımlılık gerekmez — Guzzle dahil tüm ihtiyaçlar TYPO3 core üzerinden gelir.
