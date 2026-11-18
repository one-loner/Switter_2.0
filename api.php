<?php
declare(strict_types=1);
require __DIR__ . '/lib/blog.php';

function apiResponse(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo jsonData(['version' => 1] + $body);
    exit;
}

function apiMedia(mixed $image): ?array
{
    return isMediaName($image) ? [
        'url' => 'uploads/' . rawurlencode($image),
        'mime_type' => mediaType($image),
    ] : null;
}

function apiPost(array $post): array
{
    return [
        'id' => $post['id'] ?? '',
        'title' => $post['title'] ?? '',
        'content' => $post['content'] ?? '',
        'created_at' => $post['created_at'] ?? null,
        'media' => apiMedia($post['image'] ?? null),
    ];
}

function apiInput(): array
{
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
    checkPostSize();
    if ($contentType === 'multipart/form-data') {
        if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new BlogError('upload_limit');
        }
        return $_POST;
    }
    if ($contentType !== 'application/json') {
        throw new BlogError('content_type');
    }

    $json = file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024 + 1);
    if ($json === false || strlen($json) > 2 * 1024 * 1024) {
        throw new BlogError('upload_limit');
    }
    try {
        $data = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new BlogError('invalid_request');
    }
    if (!$data instanceof stdClass) {
        throw new BlogError('invalid_request');
    }
    return (array)$data;
}

function authorizedApi(array $config): bool
{
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    return preg_match('/\ABearer ([a-f0-9]{64})\z/i', $authorization, $match) === 1
        && $config['api_token_hash'] !== ''
        && hash_equals($config['api_token_hash'], hash('sha256', $match[1]));
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $resource = input($_GET, 'resource', 'posts');
    [$status, $body] = withBlogLock(static function () use ($method, $resource): array {
        $config = loadConfig();
        locale($config['language']);
        $routes = [
            'blog' => ['GET'],
            'posts' => ['GET', 'POST', 'DELETE'],
            'archives' => ['GET', 'POST'],
        ];
        if (input($_GET, 'version', '1') !== '1' || !isset($routes[$resource])) {
            throw new BlogError('not_found');
        }
        if (!in_array($method, $routes[$resource], true)) {
            header('Allow: ' . implode(', ', $routes[$resource]));
            throw new BlogError('method_not_allowed');
        }
        $id = input($_GET, 'id');
        if ($method !== 'GET' && !authorizedApi($config)) {
            header('WWW-Authenticate: Bearer realm="Switter"');
            throw new BlogError('api_unauthorized');
        }
        if ($resource === 'blog') {
            $blog = publicConfig($config);
            $blog['header_media'] = apiMedia($blog['header_media']);
            $blog['timezone'] = date_default_timezone_get();
            return [200, ['data' => $blog]];
        }
        if ($resource === 'archives') {
            if ($method === 'POST') {
                $archive = archivePosts($config);
                if ($archive === null) {
                    throw new BlogError('no_posts');
                }
                return [201, ['data' => ['name' => $archive, 'url' => 'archive/' . rawurlencode($archive)]]];
            }
            $archives = array_map(static fn(string $name): array =>
                ['name' => $name, 'url' => 'archive/' . rawurlencode($name)], archiveNames());
            return [200, ['data' => $archives]];
        }

        if ($method === 'POST') {
            $data = apiInput();
            $upload = $_FILES['media'] ?? null;
            return [201, ['data' => apiPost(addPost(input($data, 'title'), input($data, 'content'), $upload))]];
        }
        if ($method === 'DELETE') {
            if ($id === '') {
                throw new BlogError('invalid_request');
            }
            if (!deletePost($id, $config)) {
                throw new BlogError('not_found');
            }
            return [200, ['data' => ['id' => $id, 'deleted' => true]]];
        }

        $posts = loadPosts();
        if ($id !== '') {
            foreach ($posts as $post) {
                if (($post['id'] ?? null) === $id) {
                    return [200, ['data' => apiPost($post)]];
                }
            }
            throw new BlogError('not_found');
        }

        $limit = filter_var(input($_GET, 'limit', '20'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $offset = filter_var(input($_GET, 'offset', '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($limit === false || $offset === false) {
            throw new BlogError('invalid_request');
        }
        $posts = findPosts($posts, queryText());
        $page = array_slice($posts, $offset, $limit);
        return [200, [
            'data' => array_map('apiPost', $page),
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'total' => count($posts),
                'next_offset' => $offset + count($page) < count($posts) ? $offset + count($page) : null,
            ],
        ]];
    }, $method === 'GET' ? LOCK_SH : LOCK_EX);
    apiResponse($status, $body);
} catch (Throwable $exception) {
    $error = $exception instanceof BlogError ? $exception->getMessage() : 'storage_error';
    $status = match ($error) {
        'not_found' => 404,
        'method_not_allowed' => 405,
        'api_unauthorized' => 401,
        'invalid_request' => 400,
        'upload_limit' => 413,
        'content_type', 'media_formats' => 415,
        'no_posts' => 409,
        'video_unavailable' => 503,
        'post_required', 'upload_error', 'video_invalid', 'video_length' => 422,
        default => 500,
    };
    if ($status === 500) {
        error_log((string)$exception);
    }
    apiResponse($status, ['error' => ['code' => $error, 'message' => t($error)]]);
}
