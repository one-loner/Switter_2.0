<?php
declare(strict_types=1);

function startBlogSession(): void
{
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_start(['use_strict_mode' => true, 'cookie_httponly' => true,
        'cookie_secure' => $secure, 'cookie_samesite' => 'Lax']);
    header('Cache-Control: no-store');
}

function csrfToken(): string
{
    return $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
}

function csrfField(): void
{
    echo '<input type="hidden" name="csrf_token" value="' . escape(csrfToken()) . '">';
}

function checkCsrf(): void
{
    if (!hash_equals(csrfToken(), input($_POST, 'csrf_token'))) {
        http_response_code(403);
        throw new BlogError('csrf_error');
    }
}

function sessionFingerprint(array $config): string
{
    return hash('sha256', $config['login'] . ':' . $config['password_hash']);
}

function isAuthorized(array $config): bool
{
    return $config['password_hash'] !== ''
        && hash_equals(sessionFingerprint($config), $_SESSION['authorization'] ?? '');
}

function startAuthorization(array $config): void
{
    session_regenerate_id(true);
    $_SESSION['authorization'] = sessionFingerprint($config);
    unset($_SESSION['csrf_token']);
}

function validatePassword(string $password, string $confirmation): void
{
    if (strlen($password) > 72 || str_contains($password, "\0") || mb_strlen($password, 'UTF-8') < 8
        || !mb_check_encoding($password, 'UTF-8')) {
        throw new BlogError('password_length');
    }
    if (!hash_equals($password, $confirmation)) {
        throw new BlogError('password_match');
    }
}

function redirect(string $page): never
{
    header('Location: ' . $page, true, 303);
    exit;
}
