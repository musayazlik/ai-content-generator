# AI Content Generator for TYPO3 v14

AI destekli içerik oluşturucu extension. TYPO3 v14 page module'deki her içerik elementine bir "AI" butonu ekler. Butona tıklandığında, elementin alanlarını listeleyen bir dialog açılır ve kullanıcı metin girerek AI ile içerik (metin ve görsel) oluşturabilir.

## Özellikler

- **Tüm içerik elementlerinde çalışır** — Core ve custom (Content Blocks dahil) elementler
- **Otomatik alan tespiti** — TCA'den elementin CType'ına göre alanları otomatik listeler (text, select, collection/inline, image/file alanları)
- **Metin üretimi** — OpenAI (veya uyumlu: OpenRouter, Ollama) API ile metin alanları için içerik oluşturur
- **Görsel üretimi** — Ayrı API bilgileriyle (farklı sağlayıcı da olabilir) görsel oluşturur ve `fileadmin/ai_generated/` klasörüne kaydeder
- **Düzenlenebilir sonuçlar** — Oluşturulan içeriği kaydetmeden önce düzenleyebilirsiniz
- **DataHandler ile kayıt** — TYPO3 DataHandler kullanarak güvenli kayıt yapar

## Kurulum

Composer-mode kurulum için detaylı adımlar **[INSTALL.md](INSTALL.md)** dosyasında. Özetle:

```bash
composer require musayazlik/ai-content-generator
```

(Private/path repository üzerinden dağıtılıyorsa `INSTALL.md`'deki path repository adımlarını izleyin.)

### Konfigürasyon

1. Backend sol menüde **System > AI Content Generator** modülüne gidin
2. **Text Generation** bölümünde: API Key, Model, API Endpoint, System Prompt
3. **Image Generation** bölümünde (ayrı, isteğe bağlı): API Key, Model, API Endpoint, Image Size
4. **Save Settings** ile kaydedin

## Kullanım

1. **Web > Page** modülüne gidin
2. Herhangi bir içerik elementinin header'ında **AI** butonunu göreceksiniz
3. Butona tıklayın
4. Açılan dialog'da:
   - Doldurmak istediğiniz alanları seçin (metin, seçim listesi, koleksiyon, görsel)
   - İçerik talimatınızı yazın (örn: "Ürünümüz hakkında ikna edici bir tanıtım metni yaz")
   - **"İçeriği Oluştur"** butonuna tıklayın
5. AI oluşturulan içeriği (ve varsa görselleri) her alan için gösterecek
6. İçeriği düzenleyin (opsiyonel)
7. **"Doldur"** butonuna tıklayın — içerik kaydedilir ve sayfa yenilenir

## Mimari

```
ai_content_generator/
├── Classes/
│   ├── Backend/Form/Element/
│   │   └── AiContentGeneratorElement.php       # Edit form'daki AI sekmesi/butonu
│   ├── Controller/
│   │   ├── AiContentGeneratorController.php    # AJAX endpoint'ler (getFields, generate, generateImage, save)
│   │   └── SettingsModuleController.php        # Ayarlar modülü controller'ı
│   ├── Service/
│   │   ├── AiService.php                       # Metin + görsel API entegrasyonu
│   │   └── SettingsService.php                 # Ayarları sys_registry'de saklar
│   └── EventListener/
│       └── PageLayoutAssetsEventListener.php   # Page module'e JS/CSS enjekte eder
├── Configuration/
│   ├── Backend/
│   │   ├── AjaxRoutes.php                      # AJAX route tanımları
│   │   └── Modules.php                         # Ayarlar modülü kaydı
│   ├── TCA/Overrides/tt_content.php
│   ├── Icons.php                               # Modül ikonu (Configuration kökünde olmalı!)
│   ├── JavaScriptModules.php                   # JS importmap (Configuration kökünde olmalı!)
│   └── Services.yaml                           # Dependency injection
├── Resources/
│   ├── Private/
│   │   ├── Language/locallang.xlf, locallang_mod.xlf
│   │   └── Templates/SettingsModule/Index.html
│   └── Public/
│       ├── JavaScript/ai-content-generator.js  # Ana JS modülü (buton + modal)
│       ├── Css/ai-content-generator.css        # Stiller (backend temasına uyumlu)
│       └── Icons/Extension.svg                 # Extension ikonu
├── composer.json
├── ext_localconf.php                           # FormEngine node kaydı
├── ext_emconf.php                              # TER metadata
└── INSTALL.md                                  # Composer-mode kurulum rehberi
```

## AJAX Endpoint'leri

| Route | Açıklama |
|-------|----------|
| `/ai-content-generator/get-fields` | Verilen UID için içerik elementinin alanlarını döndürür |
| `/ai-content-generator/generate` | AI API'sini çağırarak metin/seçim/koleksiyon alanları için içerik oluşturur |
| `/ai-content-generator/generate-image` | Görsel API'sini çağırarak görsel üretir, fileadmin'e kaydeder |
| `/ai-content-generator/save` | Oluşturulan içeriği (ve görsel referanslarını) DataHandler ile kaydeder |

## Gereksinimler

- TYPO3 v14.3+
- PHP 8.2+
- OpenAI uyumlu bir metin API'si (zorunlu), görsel üretimi için ayrı bir görsel API'si (opsiyonel)

## AI Sağlayıcı Örnekleri

**Metin üretimi:**
- **OpenAI**: `https://api.openai.com/v1/chat/completions` + `gpt-4o-mini`
- **OpenRouter**: `https://openrouter.ai/api/v1/chat/completions` + istediğiniz model
- **Ollama (local)**: `http://localhost:11434/v1/chat/completions` + `llama3`

**Görsel üretimi:**
- **OpenAI**: `https://api.openai.com/v1/images/generations` + `gpt-image-1` / `dall-e-3`
- **OpenRouter**: `https://openrouter.ai/api/v1/images` + `openai/gpt-image-1` / `google/gemini-2.5-flash-image`

## Geliştirici

**Musa Yazlık**
- E-posta: info@musayazlik.com
