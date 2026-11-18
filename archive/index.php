<?php
declare(strict_types=1);
require __DIR__ . '/../lib/blog.php';

$names = withBlogLock(static function (): array {
    locale(loadConfig()['language']);
    return archiveNames();
}, LOCK_SH);
?>
<!doctype html>
<html <?= languageAttributes() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e('archive') ?></title>
    <link rel="stylesheet" href="../styles.css">
</head>
<body>
<main class="page">
    <h1><?= e('archive') ?></h1>
    <p><a href="../index.php"><?= e('blog') ?></a></p>
    <?php if (!$names): ?>
        <p><?= e('no_archives') ?></p>
    <?php else: ?>
        <ul>
            <?php foreach ($names as $name): ?>
                <li><a href="<?= rawurlencode($name) ?>"><?= escape(pathinfo($name, PATHINFO_FILENAME)) ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>
</body>
</html>
