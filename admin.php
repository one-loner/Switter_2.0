<?php
declare(strict_types=1);

require __DIR__ . '/lib/blog.php';
require __DIR__ . '/lib/session.php';
require __DIR__ . '/lib/backup.php';

startBlogSession();
$config = loadConfig();
locale($config['language']);
$error = '';
$self = './' . rawurlencode(basename($_SERVER['SCRIPT_NAME']));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        checkPostSize();
        checkCsrf();
        $action = input($_POST, 'action');
        $backup = withBlogLock(static function () use ($action): ?string {
            $config = loadConfig();
            if ($action === 'login') {
                if (!hash_equals($config['login'], input($_POST, 'login'))
                    || !password_verify(input($_POST, 'password'), $config['password_hash'])) {
                    throw new BlogError('login_error');
                }
                startAuthorization($config);
                return null;
            }
            if (!isAuthorized($config)) {
                http_response_code(403);
                throw new BlogError('login_error');
            }

            switch ($action) {
                case 'logout':
                    $_SESSION = [];
                    session_destroy();
                    break;
                case 'add':
                    addPost(input($_POST, 'title'), input($_POST, 'content'), $_FILES['image'] ?? null);
                    break;
                case 'delete':
                    deletePost(input($_POST, 'id'), $config);
                    break;
                case 'archive':
                    archivePosts($config);
                    break;
                case 'language':
                    $language = input($_POST, 'language');
                    if (!isset(languages()[$language])) {
                        throw new BlogError('language_error');
                    }
                    $config['language'] = $language;
                    saveConfig($config);
                    break;
                case 'password':
                    if (!password_verify(input($_POST, 'current_password'), $config['password_hash'])) {
                        throw new BlogError('current_password_error');
                    }
                    $new = input($_POST, 'password');
                    validatePassword($new, input($_POST, 'password_confirm'));
                    $config['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
                    $config['api_token_hash'] = '';
                    saveConfig($config);
                    startAuthorization($config);
                    $_SESSION['notice'] = 'password_changed';
                    break;
                case 'token_create':
                    $token = bin2hex(random_bytes(32));
                    $config['api_token_hash'] = hash('sha256', $token);
                    saveConfig($config);
                    $_SESSION['new_api_token'] = $token;
                    break;
                case 'token_revoke':
                    $config['api_token_hash'] = '';
                    saveConfig($config);
                    unset($_SESSION['new_api_token']);
                    $_SESSION['notice'] = 'token_revoked';
                    break;
                case 'backup':
                    return createBackup($config);
                case 'restore':
                    if (input($_POST, 'restore_confirm') !== 'yes') {
                        throw new BlogError('restore_confirm');
                    }
                    restoreBackup($_FILES['backup_file'] ?? [], $config);
                    $_SESSION['notice'] = 'restored';
                    break;
                default:
                    http_response_code(400);
                    throw new BlogError('invalid_request');
            }
            return null;
        }, $action === 'backup' ? LOCK_SH : LOCK_EX);

        if ($backup !== null) {
            exportBackup($backup);
        }
        redirect($self);
    } catch (BlogError $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log((string)$exception);
        $error = 'storage_error';
    }
}

$query = queryText();
[$config, $posts, $archiveResults] = withBlogLock(static function () use ($query): array {
    $config = loadConfig();
    locale($config['language']);
    $authorized = isAuthorized($config);
    return [
        $config,
        $authorized ? findPosts(loadPosts(), $query) : [],
        $authorized && $query !== '' ? searchArchives($query) : [],
    ];
}, LOCK_SH);
$authorized = isAuthorized($config);
$notice = $_SESSION['notice'] ?? '';
$apiToken = $_SESSION['new_api_token'] ?? '';
unset($_SESSION['notice'], $_SESSION['new_api_token']);
?>
<!doctype html>
<html <?= languageAttributes() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e('admin') ?></title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<main class="page">
    <h1><?= e('admin') ?></h1>
    <?php if ($error !== ''): ?>
        <p role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <?php if (!$authorized): ?>
        <?php if ($config['password_hash'] === '' && !is_file(__DIR__ . '/.switter-installed')): ?>
            <p><a href="install.php"><?= e('install') ?></a></p>
        <?php endif; ?>
        <form class="settings-form" method="post">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="login">
            <label><?= e('login') ?><input type="text" name="login" autocomplete="username" required></label>
            <label><?= e('password') ?>
                <input type="password" name="password" autocomplete="current-password" required>
            </label>
            <button class="bt" type="submit"><?= e('sign_in') ?></button>
        </form>
    <?php else: ?>
        <nav><a href="index.php"><?= e('blog') ?></a> · <a href="archive/"><?= e('archive') ?></a></nav>
        <form method="post">
            <?php csrfField(); ?>
            <button name="action" value="logout" type="submit"><?= e('sign_out') ?></button>
        </form>

        <?php if ($notice !== ''): ?>
            <p role="status"><?= e($notice) ?></p>
        <?php endif; ?>

        <h2><?= e('new_post') ?></h2>
        <form class="settings-form" method="post" enctype="multipart/form-data">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="add">
            <label><?= e('post_title') ?>
                <input type="text" name="title" value="<?= escape(input($_POST, 'title')) ?>" required>
            </label>
            <div class="format-buttons">
                <button type="button" data-marker="**" aria-label="<?= e('bold') ?>"><strong>B</strong></button>
                <button type="button" data-marker="*" aria-label="<?= e('italic') ?>"><em>I</em></button>
                <button type="button" data-marker="__" aria-label="<?= e('underline') ?>"><u>U</u></button>
                <button type="button" data-marker="~~" aria-label="<?= e('strike') ?>"><s>S</s></button>
                <button type="button" id="insert-link" data-prompt="<?= e('link_prompt') ?>"><?= e('link') ?></button>
            </div>
            <label><?= e('post_content') ?>
                <textarea id="post-content" name="content" required><?= escape(input($_POST, 'content')) ?></textarea>
            </label>
            <label><?= e('media') ?><input type="file" name="image" accept="<?= MEDIA_ACCEPT ?>"></label>
            <p><?= e('upload_limit') ?> <?= e('video_length') ?></p>
            <button class="bt" type="submit"><?= e('publish') ?></button>
        </form>

        <h2><?= e('posts') ?></h2>
        <form method="get">
            <label><?= e('search') ?>
                <input type="search" name="q" maxlength="200" value="<?= escape($query) ?>">
            </label>
            <button type="submit"><?= e('find') ?></button>
            <?php if ($query !== ''): ?>
                <a href="<?= escape($self) ?>"><?= e('reset') ?></a>
            <?php endif; ?>
        </form>
        <?php require __DIR__ . '/lib/feed.php'; ?>
        <?php if ($query === '' && $posts): ?>
            <form method="post" data-confirm="<?= e('archive_confirm') ?>">
                <?php csrfField(); ?>
                <button class="bt" name="action" value="archive" type="submit"><?= e('archive_posts') ?></button>
            </form>
        <?php endif; ?>

        <h2><?= e('settings') ?></h2>
        <form class="settings-form" method="post">
            <?php csrfField(); ?>
            <?php languagePicker(); ?>
            <button class="bt" name="action" value="language" type="submit"><?= e('apply') ?></button>
        </form>

        <details>
            <summary><?= e('change_password') ?></summary>
            <form class="settings-form" method="post">
                <?php csrfField(); ?>
                <label><?= e('current_password') ?>
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </label>
                <label><?= e('new_password') ?>
                    <input type="password" name="password" autocomplete="new-password" minlength="8" required>
                </label>
                <label><?= e('confirm_password') ?>
                    <input type="password" name="password_confirm" autocomplete="new-password" required>
                </label>
                <p><?= e('password_length') ?></p>
                <button class="bt" name="action" value="password" type="submit"><?= e('change_password') ?></button>
            </form>
        </details>

        <details <?= $apiToken !== '' ? 'open' : '' ?>>
            <summary><?= e('api_access') ?></summary>
            <?php if ($apiToken !== ''): ?>
                <p role="status"><?= e('token_copy') ?></p>
                <input type="text" dir="ltr" aria-label="<?= e('api_access') ?>" readonly
                       value="<?= escape($apiToken) ?>" style="width: 100%;">
            <?php endif; ?>
            <form class="settings-form" method="post">
                <?php csrfField(); ?>
                <button class="bt" name="action" value="token_create" type="submit"><?= e('token_create') ?></button>
                <?php if ($config['api_token_hash'] !== ''): ?>
                    <button class="bt" name="action" value="token_revoke" type="submit"><?= e('token_revoke') ?></button>
                <?php endif; ?>
            </form>
        </details>

        <details>
            <summary><?= e('backup_restore') ?></summary>
            <form class="settings-form" method="post">
                <?php csrfField(); ?>
                <button class="bt" name="action" value="backup" type="submit"><?= e('backup') ?></button>
            </form>
            <form class="settings-form" method="post" enctype="multipart/form-data">
                <?php csrfField(); ?>
                <label><?= e('backup_file') ?><input type="file" name="backup_file" accept=".zip,application/zip" required></label>
                <label><input type="checkbox" name="restore_confirm" value="yes" required> <?= e('restore_confirm') ?></label>
                <button class="bt" name="action" value="restore" type="submit"><?= e('restore') ?></button>
            </form>
        </details>

        <script>
        const editor = document.getElementById('post-content');
        for (const button of document.querySelectorAll('[data-marker]')) {
            button.addEventListener('click', () => {
                const marker = button.dataset.marker;
                const selection = editor.value.slice(editor.selectionStart, editor.selectionEnd);
                editor.setRangeText(marker + selection + marker, editor.selectionStart, editor.selectionEnd, 'end');
                if (!selection) {
                    editor.selectionStart = editor.selectionEnd = editor.selectionEnd - marker.length;
                }
                editor.focus();
            });
        }
        document.getElementById('insert-link').addEventListener('click', event => {
            const url = prompt(event.currentTarget.dataset.prompt, 'https://')?.trim();
            if (url && /^https?:\/\/[^\s)]+$/i.test(url)) {
                const label = editor.value.slice(editor.selectionStart, editor.selectionEnd) || url;
                editor.setRangeText('[' + label + '](' + url + ')', editor.selectionStart, editor.selectionEnd, 'end');
                editor.focus();
            }
        });
        for (const form of document.querySelectorAll('[data-confirm]')) {
            form.addEventListener('submit', event => {
                if (!confirm(form.dataset.confirm)) event.preventDefault();
            });
        }
        </script>
    <?php endif; ?>
</main>
</body>
</html>
