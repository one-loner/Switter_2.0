<?php
declare(strict_types=1);

const BACKUP_MAX_BYTES = 1024 * 1024 * 1024;
const BACKUP_MAX_ENTRIES = 20000;

function backupEntryAllowed(string $name): bool
{
    return in_array($name, ['manifest.json', 'posts.json', 'archive/uploads/1'], true)
        || preg_match('~\Aarchive/[a-zA-Z0-9_.-]+\.html\z~', $name) === 1
        || (preg_match('~\A(?:archive/)?uploads/([^/]+)\z~', $name, $match) && isMediaName($match[1]));
}

function requireZip(): void
{
    if (!class_exists(ZipArchive::class)) {
        throw new BlogError('zip_unavailable');
    }
}

function createBackup(array $config): string
{
    requireZip();
    $path = tempnam(sys_get_temp_dir(), 'switter-backup-');
    if ($path === false) {
        throw new BlogError('backup_error');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
        @unlink($path);
        throw new BlogError('backup_error');
    }

    try {
        $manifest = jsonData(['format' => 'switter-backup', 'version' => 1, 'blog' => publicConfig($config)]);
        $posts = jsonData(loadPosts());
        $size = strlen($manifest) + strlen($posts);
        $entries = 2;
        if ($size > BACKUP_MAX_BYTES || !$zip->addFromString('manifest.json', $manifest)
            || !$zip->addFromString('posts.json', $posts)) {
            throw new BlogError('backup_error');
        }

        foreach (['uploads', 'archive', 'archive/uploads'] as $dir) {
            if (!is_dir(BLOG_ROOT . '/' . $dir)) {
                continue;
            }
            foreach (new DirectoryIterator(BLOG_ROOT . '/' . $dir) as $file) {
                $name = $dir . '/' . $file->getFilename();
                if (!backupEntryAllowed($name) || $file->isDir()) {
                    continue;
                }
                if ($file->isLink() || !$file->isFile()) {
                    throw new BlogError('backup_error');
                }
                $size += $file->getSize();
                if ($size > BACKUP_MAX_BYTES || ++$entries > BACKUP_MAX_ENTRIES
                    || !$zip->addFile($file->getPathname(), $name)) {
                    throw new BlogError('backup_error');
                }
            }
        }

        if (!$zip->close()) {
            throw new BlogError('backup_error');
        }
        return $path;
    } catch (Throwable $error) {
        @unlink($path);
        throw $error;
    }
}

function exportBackup(string $path): never
{
    header('Content-Type: application/zip');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('Content-Disposition: attachment; filename="switter-' . date('Ymd-His') . '.zip"');
    header('Content-Length: ' . filesize($path));
    try {
        readfile($path);
    } finally {
        @unlink($path);
    }
    exit;
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
    } elseif (is_dir($path)) {
        foreach (new FilesystemIterator($path) as $entry) {
            removeTree($entry->getPathname());
        }
        @rmdir($path);
    }
}

function stageBackup(ZipArchive $zip, string $stage, array $config): void
{
    if ($zip->numFiles > BACKUP_MAX_ENTRIES) {
        throw new BlogError('backup_invalid');
    }
    $seen = [];
    $size = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->statIndex($i);
        $name = $entry['name'] ?? '';
        $canonical = strtolower($name);
        if (!backupEntryAllowed($name) || isset($seen[$canonical])) {
            throw new BlogError('backup_invalid');
        }
        $seen[$canonical] = true;
        $size += $entry['size'];
        if ($size > BACKUP_MAX_BYTES || ($name === 'manifest.json' && $entry['size'] > 65536)) {
            throw new BlogError('backup_invalid');
        }

        $path = $stage . '/' . $name;
        $source = $zip->getStream($name);
        $destination = @fopen($path, 'xb');
        if ($source === false || $destination === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($destination)) {
                fclose($destination);
            }
            throw new BlogError('backup_invalid');
        }
        try {
            $copied = stream_copy_to_stream($source, $destination, $entry['size'] + 1);
        } finally {
            fclose($source);
            fclose($destination);
        }
        if ($copied !== $entry['size'] || hash_file('crc32b', $path) !== sprintf('%08x', $entry['crc'])) {
            throw new BlogError('backup_invalid');
        }
        if (isMediaName(basename($name))) {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
            if ($mime !== mediaType($name)) {
                throw new BlogError('backup_invalid');
            }
        }
    }

    if (!is_file($stage . '/manifest.json') || !is_file($stage . '/posts.json')) {
        throw new BlogError('backup_invalid');
    }
    try {
        $manifest = json_decode(file_get_contents($stage . '/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        $posts = decodePosts(file_get_contents($stage . '/posts.json'));
    } catch (JsonException | BlogError) {
        throw new BlogError('backup_invalid');
    }
    if (($manifest['format'] ?? null) !== 'switter-backup' || ($manifest['version'] ?? null) !== 1
        || !is_array($manifest['blog'] ?? null)) {
        throw new BlogError('backup_invalid');
    }

    $blog = publicConfig($manifest['blog']);
    foreach (['language', 'title', 'description', 'header_title', 'header_description'] as $key) {
        if (!is_string($blog[$key] ?? null)) {
            throw new BlogError('backup_invalid');
        }
    }
    $header = $blog['header_media'] ?? null;
    if (!isset(languages()[$blog['language']]) || ($header !== null && !isMediaName($header))) {
        throw new BlogError('backup_invalid');
    }
    foreach ($posts as $post) {
        foreach (['id', 'title', 'content', 'created_at'] as $key) {
            if (isset($post[$key]) && !is_string($post[$key])) {
                throw new BlogError('backup_invalid');
            }
        }
    }
    foreach (['index.php', 'styles.css'] as $file) {
        if (!copy(ARCHIVE_DIR . '/' . $file, $stage . '/archive/' . $file)) {
            throw new BlogError('backup_error');
        }
    }
    atomicWrite($stage . '/.switter-config.php', CONFIG_PREFIX . jsonData(array_replace($config, $blog, [
        'header_media' => $header,
    ])), 0600);
}

function restoreBackup(array $file, array $config): void
{
    requireZip();
    $source = input($file, 'tmp_name');
    if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_uploaded_file($source)
        || ($file['size'] ?? 0) > BACKUP_MAX_BYTES) {
        throw new BlogError('backup_upload_error');
    }

    $zip = new ZipArchive();
    if ($zip->open($source, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
        throw new BlogError('backup_invalid');
    }

    $stage = BLOG_ROOT . '/.switter-restore-' . bin2hex(random_bytes(16));
    $installed = [];
    $old = [];
    $rollbackOk = true;
    try {
        ensureDirectory($stage, 0700);
        ensureDirectory($stage . '/uploads');
        ensureDirectory($stage . '/archive/uploads');
        ensureDirectory($stage . '/previous', 0700);
        stageBackup($zip, $stage, $config);

        foreach (['uploads', 'archive', 'posts.json', '.switter-config.php'] as $name) {
            $target = BLOG_ROOT . '/' . $name;
            if (file_exists($target)) {
                if (!@rename($target, $stage . '/previous/' . $name)) {
                    throw new BlogError('backup_error');
                }
                $old[] = $name;
            }
            if (!@rename($stage . '/' . $name, $target)) {
                throw new BlogError('backup_error');
            }
            $installed[] = $name;
        }
    } catch (Throwable $error) {
        foreach (array_reverse($installed) as $name) {
            $rollbackOk = @rename(BLOG_ROOT . '/' . $name, $stage . '/' . $name) && $rollbackOk;
        }
        foreach (array_reverse($old) as $name) {
            $rollbackOk = @rename($stage . '/previous/' . $name, BLOG_ROOT . '/' . $name) && $rollbackOk;
        }
        if (!$rollbackOk) {
            error_log('Switter restore rollback needs manual recovery in ' . $stage);
        }
        throw $error;
    } finally {
        $zip->close();
        if ($rollbackOk) {
            removeTree($stage);
        }
    }
}
