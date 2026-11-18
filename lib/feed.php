<?php if ($query !== ''): ?>
    <p><?= e('post_results') ?> <?= count($posts) ?> · <?= e('archive_results') ?> <?= count($archiveResults) ?></p>
    <?php if (!$posts && !$archiveResults): ?><p><?= e('no_results') ?></p><?php endif; ?>
<?php elseif (!$posts): ?>
    <p><?= e('no_posts') ?></p>
<?php endif; ?>

<?php foreach ($posts as $post): ?>
    <?php $title = (string)($post['title'] ?? t('untitled')); ?>
    <article class="p">
        <h2 class="pt"><?= escape($title) ?></h2>
        <?php if (!empty($post['created_at'])): ?>
            <?php $time = strtotime((string)$post['created_at']); ?>
            <p class="post-date"><?= e('published') ?> <?= $time === false
                ? escape((string)$post['created_at']) : date('H:i d.m.Y', $time) ?></p>
        <?php endif; ?>
        <?php $image = $post['image'] ?? null; ?>
        <?php if (isMediaName($image) && is_file(UPLOAD_DIR . '/' . $image)): ?>
            <?= mediaHtml('uploads/', $image, $title) ?>
        <?php endif; ?>
        <p class="pg"><?= formatContent((string)($post['content'] ?? '')) ?></p>
        <?php if ($authorized): ?>
            <form method="post" data-confirm="<?= e('delete_confirm') ?>">
                <?php csrfField(); ?>
                <input type="hidden" name="id" value="<?= escape((string)($post['id'] ?? '')) ?>">
                <button class="bt" name="action" value="delete" type="submit"><?= e('delete') ?></button>
            </form>
        <?php endif; ?>
    </article>
    <hr>
<?php endforeach; ?>

<?php if ($archiveResults): ?>
    <h2><?= e('archive_results') ?></h2>
    <?php foreach ($archiveResults as $result): ?>
        <article class="p">
            <h3 class="pt"><?= escape($result['title']) ?></h3>
            <p class="pg"><?= escape(makeExcerpt($result['content'], $query)) ?></p>
            <a href="archive/<?= rawurlencode($result['archive']) ?>"><?= escape($result['archive']) ?></a>
        </article>
        <hr>
    <?php endforeach; ?>
<?php endif; ?>
