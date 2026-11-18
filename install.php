<?php
declare(strict_types=1);

require __DIR__ . '/lib/blog.php';
require __DIR__ . '/lib/session.php';

startBlogSession();
$config = loadConfig();
$selection = input($_GET, 'language', input($_POST, 'language', $config['language']));
locale(isset(languages()[$selection]) ? $selection : $config['language']);
$configured = is_file(CONFIG_FILE) || is_file(__DIR__ . '/.switter-installed');
$minimumPatch = ['8.2' => 80235, '8.3' => 80336, '8.4' => 80427, '8.5' => 80512];
$branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
$requirements = PHP_VERSION_ID >= ($minimumPatch[$branch] ?? 80235)
    && !preg_match('/(?:dev|alpha|beta|rc)/i', PHP_VERSION)
    && extension_loaded('mbstring') && extension_loaded('fileinfo') && extension_loaded('zip');
$error = '';
$installed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$configured && $requirements) {
    try {
        checkPostSize();
        checkCsrf();
        $login = trim(input($_POST, 'login'));
        if (!preg_match('/\A[a-zA-Z0-9_.-]{3,32}\z/', $login)) {
            throw new BlogError('login_invalid');
        }
        $password = input($_POST, 'password');
        validatePassword($password, input($_POST, 'password_confirm'));
        $blog = [
            'title' => trim(input($_POST, 'title')),
            'description' => trim(input($_POST, 'description')),
            'header_title' => trim(input($_POST, 'header_title')),
            'header_description' => trim(input($_POST, 'header_description')),
        ];
        foreach (['title' => 200, 'description' => 1000, 'header_title' => 200, 'header_description' => 5000] as $key => $max) {
            if (!mb_check_encoding($blog[$key], 'UTF-8') || mb_strlen($blog[$key], 'UTF-8') > $max
                || ($key === 'title' && $blog[$key] === '')) {
                throw new BlogError('blog_invalid');
            }
        }
        $newConfig = array_replace($config, $blog, [
            'language' => locale(),
            'login' => $login,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        withBlogLock(static function () use ($newConfig): void {
            if (is_file(CONFIG_FILE) || is_file(BLOG_ROOT . '/.switter-installed')) {
                throw new BlogError('already_installed');
            }
            $newConfig['header_media'] = uploadMedia($_FILES['header_media'] ?? null);
            try {
                saveConfig($newConfig);
            } catch (Throwable $exception) {
                removeUnusedMedia($newConfig['header_media'], [], null);
                throw $exception;
            }
        });
        $installed = true;
    } catch (BlogError $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log((string)$exception);
        $error = 'storage_error';
    }
}
?>
<!doctype html>
<html <?= languageAttributes() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e('install') ?></title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<main class="page">
    <article class="at"><h1><?= e('install') ?></h1></article>

    <?php if ($installed || $configured): ?>
        <p><?= e($installed ? 'installed' : 'already_installed') ?></p>
        <p><a href="admin.php"><?= e('admin') ?></a> · <a href="index.php"><?= e('blog') ?></a></p>
    <?php elseif (!$requirements): ?>
        <p role="alert"><?= e('requirements') ?></p>
    <?php else: ?>
        <form method="get">
            <?php languagePicker(); ?>
            <button type="submit"><?= e('apply') ?></button>
        </form>
        <?php if ($error !== ''): ?>
            <p role="alert"><?= e($error) ?></p>
        <?php endif; ?>
        <form class="settings-form" method="post" action="install.php?language=<?= locale() ?>" enctype="multipart/form-data">
            <?php csrfField(); ?>
            <input type="hidden" name="language" value="<?= locale() ?>">
            <fieldset>
                <legend><?= e('admin') ?></legend>
                <label><?= e('login') ?>
                    <input type="text" name="login" value="<?= escape(input($_POST, 'login', 'admin')) ?>"
                           autocomplete="username" minlength="3" maxlength="32" required>
                </label>
                <label><?= e('password') ?>
                    <input type="password" name="password" autocomplete="new-password" minlength="8" required>
                </label>
                <label><?= e('confirm_password') ?>
                    <input type="password" name="password_confirm" autocomplete="new-password" required>
                </label>
                <p><?= e('password_length') ?></p>
            </fieldset>
            <fieldset>
                <legend><?= e('blog') ?></legend>
                <label><?= e('blog_title') ?>
                    <input type="text" name="title" maxlength="200" value="<?= escape(input($_POST, 'title', 'Switter')) ?>" required>
                </label>
                <label><?= e('description') ?>
                    <textarea name="description" maxlength="1000"><?= escape(input($_POST, 'description')) ?></textarea>
                </label>
                <label><?= e('header_title') ?>
                    <input type="text" name="header_title" maxlength="200" value="<?= escape(input($_POST, 'header_title')) ?>">
                </label>
                <label><?= e('header_description') ?>
                    <textarea name="header_description" maxlength="5000"><?= escape(input($_POST, 'header_description')) ?></textarea>
                </label>
                <label><?= e('header_media') ?>
                    <input type="file" name="header_media" accept="<?= MEDIA_ACCEPT ?>">
                </label>
                <p><?= e('upload_limit') ?> <?= e('video_length') ?></p>
            </fieldset>
            <button class="bt" type="submit"><?= e('install') ?></button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
