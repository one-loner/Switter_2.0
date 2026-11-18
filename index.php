<?php
declare(strict_types=1);
require __DIR__ . '/lib/blog.php';

$query = queryText();
[$config, $posts, $archiveResults] = withBlogLock(static function () use ($query): array {
    $config = loadConfig();
    locale($config['language']);
    return [$config, findPosts(loadPosts(), $query), $query === '' ? [] : searchArchives($query)];
}, LOCK_SH);
$authorized = false;
?>
<!doctype html>
<html <?= languageAttributes() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= escape($config['title']) ?></title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<main class="page">
    <div class="container">
        <article class="at"><h1><?= escape($config['title']) ?></h1></article>
        <?php if ($config['description'] !== ''): ?>
            <article class="pi"><h4><?= escape($config['description']) ?></h4></article>
        <?php endif; ?>
        <?php if ($config['header_title'] !== '' || $config['header_description'] !== '' || $config['header_media'] !== null): ?>
            <article class="p">
                <?php if ($config['header_title'] !== ''): ?>
                    <h2 class="pt"><?= escape($config['header_title']) ?></h2>
                <?php endif; ?>
                <?= mediaHtml('uploads/', $config['header_media'], $config['header_title'] ?: $config['title']) ?>
                <?php if ($config['header_description'] !== ''): ?>
                    <p class="pg"><?= formatContent($config['header_description']) ?></p>
                <?php endif; ?>
            </article>
        <?php endif; ?>
        <nav><a href="archive/"><?= e('archive') ?></a></nav>
        <?php require __DIR__ . '/lib/feed.php'; ?>
        <form method="get">
            <label><?= e('search') ?>
                <input type="search" name="q" maxlength="200" value="<?= escape($query) ?>">
            </label>
            <button type="submit"><?= e('find') ?></button>
            <?php if ($query !== ''): ?><a href="index.php"><?= e('reset') ?></a><?php endif; ?>
        </form>
    </div>
</main>
</body>
</html>
