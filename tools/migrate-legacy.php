<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../lib/blog.php';

// The old installer generated PHP string literals with var_export().
function legacyLiteral(string $literal): string
{
    return preg_replace_callback("/\\\\([\\\\'])/", static fn(array $match): string => $match[1],
        substr($literal, 1, -1));
}

function importLegacy(string $directory): array
{
    $admin = file_get_contents($directory . '/admin.php');
    $index = file_get_contents($directory . '/index.php');
    $literal = "'(?:[^'\\\\]|\\\\.)*'";
    if (!is_string($admin) || !is_string($index)
        || !preg_match("/^const ADMIN_LOGIN = ($literal);$/m", $admin, $login)
        || !preg_match("/^const ADMIN_PASSWORD_HASH = ($literal);$/m", $admin, $hash)) {
        throw new RuntimeException('Cannot read the legacy admin settings.');
    }
    $hash = legacyLiteral($hash[1]);
    if (password_get_info($hash)['algo'] === null) {
        throw new RuntimeException('The legacy installation has no valid password hash.');
    }
    $config = array_replace(configDefaults(), [
        'login' => legacyLiteral($login[1]),
        'password_hash' => $hash,
    ]);
    foreach (['title' => 'h1', 'description' => 'h4'] as $key => $tag) {
        if (preg_match("~<$tag>([^<]*)</$tag>~u", $index, $match)) {
            $config[$key] = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    foreach (['header_title' => 'escapeHtml', 'header_description' => 'formatPostContent'] as $key => $function) {
        $element = $key === 'header_title' ? '<h2 class="pt">' : '<p class="pg">';
        if (preg_match('~' . preg_quote($element, '~') . '<\?= ' . $function . "\\(($literal)\\) \\?>~s", $index, $match)) {
            $config[$key] = legacyLiteral($match[1]);
        }
    }
    if (preg_match("~<img src=\"<\\?= 'uploads/' \\. escapeHtml\\(($literal)\\) \\?>\"~s", $index, $match)) {
        $name = legacyLiteral($match[1]);
        if (isMediaName($name)) {
            $config['header_media'] = $name;
        }
    }
    return $config;
}

try {
    if ($argc !== 2 || !is_dir($argv[1])) {
        throw new RuntimeException('Usage: php tools/migrate-legacy.php /path/to/old-switter');
    }
    $config = importLegacy(rtrim($argv[1], '/'));
    withBlogLock(static function () use ($config): void {
        if (is_file(CONFIG_FILE)) {
            throw new RuntimeException('This installation is already configured.');
        }
        saveConfig($config);
    });
    echo "Legacy settings imported.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
