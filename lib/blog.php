<?php
declare(strict_types=1);

const BLOG_ROOT = __DIR__ . '/..';
const POSTS_FILE = BLOG_ROOT . '/posts.json';
const UPLOAD_DIR = BLOG_ROOT . '/uploads';
const ARCHIVE_DIR = BLOG_ROOT . '/archive';
const CONFIG_FILE = BLOG_ROOT . '/.switter-config.php';
const CONFIG_PREFIX = "<?php http_response_code(404); exit; __halt_compiler();\n";
const MAX_IMAGE_SIZE = 5 * 1024 * 1024;
const MAX_VIDEO_SIZE = 25 * 1024 * 1024;
const MAX_VIDEO_SECONDS = 60;
const MEDIA_ACCEPT = 'image/jpeg,image/png,image/gif,image/webp,video/webm,.webm';

require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/presentation.php';

class BlogError extends RuntimeException {}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function input(array $values, string $key, string $default = ''): string
{
    return is_string($values[$key] ?? null) ? $values[$key] : $default;
}

function checkPostSize(): void
{
    $limit = ini_parse_quantity((string)ini_get('post_max_size'));
    if ($limit > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit) {
        http_response_code(413);
        throw new BlogError('upload_limit');
    }
}

function jsonData(mixed $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
        | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

function configDefaults(): array
{
    return [
        'language' => 'ru',
        'title' => 'Switter',
        'description' => '',
        'header_title' => '',
        'header_description' => '',
        'header_media' => null,
        'login' => 'admin',
        'password_hash' => '',
        'api_token_hash' => '',
    ];
}

function loadConfig(): array
{
    if (!is_file(CONFIG_FILE)) {
        return configDefaults();
    }

    $raw = file_get_contents(CONFIG_FILE);
    if ($raw === false || !str_starts_with($raw, CONFIG_PREFIX)) {
        throw new BlogError('storage_error');
    }

    $config = json_decode(substr($raw, strlen(CONFIG_PREFIX)), true, 32, JSON_THROW_ON_ERROR);
    return array_replace(configDefaults(), $config);
}

function publicConfig(array $config): array
{
    return array_intersect_key($config, array_flip([
        'language', 'title', 'description', 'header_title', 'header_description', 'header_media',
    ]));
}

function saveConfig(array $config): void
{
    atomicWrite(CONFIG_FILE, CONFIG_PREFIX . jsonData($config), 0600);
}

function atomicWrite(string $path, string $data, int $mode = 0644): void
{
    $suffix = str_starts_with($data, CONFIG_PREFIX) ? '.php' : '.tmp';
    $temporary = dirname($path) . '/.switter-' . bin2hex(random_bytes(12)) . $suffix;
    $file = @fopen($temporary, 'xb');
    if ($file === false) {
        throw new BlogError('storage_error');
    }
    try {
        if (!chmod($temporary, $mode) || fwrite($file, $data) !== strlen($data) || !fflush($file)) {
            throw new BlogError('storage_error');
        }
        fclose($file);
        if (!@rename($temporary, $path)) {
            throw new BlogError('storage_error');
        }
    } finally {
        if (is_resource($file)) {
            fclose($file);
        }
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

function withBlogLock(callable $work, int $mode = LOCK_EX): mixed
{
    $path = BLOG_ROOT . '/.switter-lock.php';
    // An unconfigured, read-only distribution can still display its initial feed.
    if ($mode === LOCK_SH && !is_file($path) && !is_writable(BLOG_ROOT)) {
        return $work();
    }

    $file = @fopen($path, is_file($path) && $mode === LOCK_SH ? 'r' : 'c');
    if ($file === false) {
        throw new BlogError('storage_error');
    }
    try {
        if (!flock($file, $mode)) {
            throw new BlogError('storage_error');
        }
        return $work();
    } finally {
        fclose($file);
    }
}

function loadPosts(): array
{
    if (!is_file(POSTS_FILE)) {
        return [];
    }
    $raw = file_get_contents(POSTS_FILE);
    if ($raw === false) {
        throw new BlogError('storage_error');
    }
    return decodePosts($raw);
}

function decodePosts(string $raw): array
{
    $posts = trim($raw) === '' ? [] : json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($posts) || !array_is_list($posts)) {
        throw new BlogError('storage_error');
    }
    foreach ($posts as $post) {
        if (!is_array($post)) {
            throw new BlogError('storage_error');
        }
    }
    return $posts;
}

function savePosts(array $posts): void
{
    atomicWrite(POSTS_FILE, jsonData(array_values($posts)));
}

function ensureDirectory(string $path, int $mode = 0755): void
{
    if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) {
        throw new BlogError('storage_error');
    }
}

function isMediaName(mixed $name): bool
{
    return is_string($name) && preg_match('/\A[a-zA-Z0-9_-]+\.(jpe?g|png|gif|webp|webm)\z/i', $name) === 1;
}

function mediaType(string $name): string
{
    return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'webm' => 'video/webm',
        default => 'application/octet-stream',
    };
}

function mediaHtml(string $prefix, mixed $name, string $title): string
{
    if (!isMediaName($name)) {
        return '';
    }
    $src = escape($prefix . rawurlencode($name));
    $label = escape($title);

    if (mediaType($name) === 'video/webm') {
        return '<video class="centered-image media" controls playsinline preload="metadata" aria-label="'
            . $label . '"><source src="' . $src . '" type="video/webm"></video>';
    }
    return '<img class="centered-image media" src="' . $src . '" alt="' . $label . '">';
}

function uploadMedia(?array $file): ?string
{
    if ($file === null || ($file['error'] ?? null) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $path = input($file, 'tmp_name');
    if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_uploaded_file($path)) {
        throw new BlogError('upload_error');
    }
    $size = filesize($path);
    if ($size === false || $size === 0 || $size > MAX_VIDEO_SIZE) {
        throw new BlogError('upload_limit');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $extension = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'video/webm' => 'webm',
        default => throw new BlogError('media_formats'),
    };

    if ($extension === 'webm') {
        require_once __DIR__ . '/webm.php';
        checkWebm($path);
    } elseif ($size > MAX_IMAGE_SIZE) {
        throw new BlogError('upload_limit');
    } elseif ((@getimagesize($path)['mime'] ?? null) !== $mime) {
        throw new BlogError('media_formats');
    }

    ensureDirectory(UPLOAD_DIR);
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($path, UPLOAD_DIR . '/' . $filename)) {
        throw new BlogError('storage_error');
    }
    return $filename;
}

function addPost(string $title, string $content, ?array $file): array
{
    $title = trim($title);
    $content = trim($content);
    if ($title === '' || $content === '' || preg_match('//u', $title . $content) !== 1) {
        throw new BlogError('post_required');
    }
    $posts = loadPosts();
    $post = [
        'id' => bin2hex(random_bytes(16)),
        'title' => $title,
        'content' => $content,
        'image' => uploadMedia($file),
        'created_at' => date('Y-m-d H:i:s'),
    ];
    array_unshift($posts, $post);
    try {
        savePosts($posts);
    } catch (Throwable $error) {
        removeUnusedMedia($post['image'], [], null);
        throw $error;
    }
    return $post;
}

function removeUnusedMedia(mixed $name, array $remaining, mixed $header): void
{
    if (!isMediaName($name) || $name === $header || in_array($name, array_column($remaining, 'image'), true)) {
        return;
    }
    $path = UPLOAD_DIR . '/' . $name;
    if (is_file($path) && !@unlink($path)) {
        error_log('Switter could not remove unused media.');
    }
}

function deletePost(string $id, array $config): bool
{
    $posts = loadPosts();
    foreach ($posts as $key => $post) {
        if (($post['id'] ?? null) !== $id) {
            continue;
        }
        unset($posts[$key]);
        savePosts($posts);
        removeUnusedMedia($post['image'] ?? null, $posts, $config['header_media']);
        return true;
    }
    return false;
}

function archiveNames(): array
{
    $names = array_map('basename', glob(ARCHIVE_DIR . '/*.html') ?: []);
    rsort($names, SORT_STRING);
    return $names;
}

function archivePosts(array $config): ?string
{
    $posts = loadPosts();
    if (!$posts) {
        return null;
    }
    $archiveUploads = ARCHIVE_DIR . '/uploads';
    ensureDirectory($archiveUploads);
    $name = date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(3)) . '.html';
    $path = ARCHIVE_DIR . '/' . $name;
    $copied = [];
    $articles = '';

    try {
        foreach ($posts as $post) {
            $title = (string)($post['title'] ?? '');
            $content = formatContent((string)($post['content'] ?? ''));
            $media = '';
            $image = $post['image'] ?? null;
            if (isMediaName($image) && is_file(UPLOAD_DIR . '/' . $image)) {
                $destination = $archiveUploads . '/' . $image;
                if (!is_file($destination)) {
                    $copied[] = $destination;
                    if (!@copy(UPLOAD_DIR . '/' . $image, $destination)) {
                        throw new BlogError('storage_error');
                    }
                } elseif (hash_file('sha256', $destination) !== hash_file('sha256', UPLOAD_DIR . '/' . $image)) {
                    throw new BlogError('storage_error');
                }
                $media = mediaHtml('uploads/', $image, $title);
            }
            $articles .= '<article class="p"><h2 class="pt">' . escape($title) . '</h2>'
                . $media . '<p class="pg">' . $content . "</p></article>\n";
        }

        $archiveTitle = e('post_archive');
        $html = '<!doctype html><html ' . languageAttributes() . '><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $archiveTitle . '</title><link rel="stylesheet" href="../styles.css"></head>'
            . '<body><main class="page"><h1>' . $archiveTitle . '</h1><p>'
            . escape(date('Y-m-d H:i:s')) . '</p>' . $articles . '</main></body></html>';
        atomicWrite($path, $html);
        savePosts([]);
    } catch (Throwable $error) {
        foreach ($copied as $file) {
            @unlink($file);
        }
        if (is_file($path)) {
            @unlink($path);
        }
        throw $error;
    }

    foreach ($posts as $post) {
        removeUnusedMedia($post['image'] ?? null, [], $config['header_media']);
    }
    return $name;
}

function findPosts(array $posts, string $query): array
{
    if ($query === '') {
        return $posts;
    }
    return array_values(array_filter($posts, static fn(array $post): bool =>
        containsSearchTerm((string)($post['title'] ?? ''), $query)
        || containsSearchTerm((string)($post['content'] ?? ''), $query)));
}

function queryText(): string
{
    $query = trim(input($_GET, 'q'));
    return mb_substr($query, 0, 200, 'UTF-8');
}
