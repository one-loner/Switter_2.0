<?php
declare(strict_types=1);

function languages(): array
{
    return [
        'en' => 'English', 'zh-Hans' => '简体中文', 'hi' => 'हिन्दी', 'es' => 'Español',
        'ar' => 'العربية', 'fr' => 'Français', 'bn' => 'বাংলা', 'pt' => 'Português',
        'ru' => 'Русский', 'id' => 'Bahasa Indonesia', 'ur' => 'اردو', 'de' => 'Deutsch',
        'ja' => '日本語', 'pcm' => 'Naijá', 'arz' => 'العربية المصرية', 'mr' => 'मराठी',
        'vi' => 'Tiếng Việt', 'te' => 'తెలుగు', 'tr' => 'Türkçe', 'ha' => 'Hausa',
        'pnb-Arab' => 'پنجابی', 'sw' => 'Kiswahili', 'tl' => 'Tagalog', 'ta' => 'தமிழ்',
        'yue' => '粵語',
    ];
}

function locale(?string $language = null): string
{
    static $selected = 'ru';
    if ($language !== null && isset(languages()[$language])) {
        $selected = $language;
    }
    return $selected;
}

function t(string $key): string
{
    static $messages = [];
    $lang = locale();
    if (!isset($messages[$lang])) {
        $messages[$lang] = require BLOG_ROOT . '/locales/' . $lang . '.php';
    }
    return $messages[$lang][$key] ?? throw new LogicException('Unknown translation key: ' . $key);
}

function e(string $key): string
{
    return escape(t($key));
}

function languageAttributes(): string
{
    $lang = locale();
    $dir = in_array($lang, ['ar', 'arz', 'ur', 'pnb-Arab'], true) ? 'rtl' : 'ltr';
    return 'lang="' . $lang . '" dir="' . $dir . '"';
}

function languagePicker(): void
{
    ?>
    <label><?= e('language') ?>
        <select name="language">
            <?php foreach (languages() as $code => $label): ?>
                <option value="<?= $code ?>" lang="<?= $code ?>" <?= $code === locale() ? 'selected' : '' ?>>
                    <?= escape($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php
}
