<?php
declare(strict_types=1);

function formatContent(string $content): string
{
    $escaped = escape($content);

    $formatted = preg_replace_callback(
        '/\*\*(.+?)\*\*|~~(.+?)~~|\*(.+?)\*|__(.+?)__/us',
        static function (array $matches): string {
            if (($matches[1] ?? '') !== '') {
                return '<strong>' . $matches[1] . '</strong>';
            }

            if (($matches[2] ?? '') !== '') {
                return '<s>' . $matches[2] . '</s>';
            }

            if (($matches[3] ?? '') !== '') {
                return '<em>' . $matches[3] . '</em>';
            }

            return '<u>' . $matches[4] . '</u>';
        },
        $escaped
    ) ?? $escaped;

    // В Markdown-ссылках разрешены только схемы http и https.
    $formatted = preg_replace_callback(
        '~\[([^\]\r\n]+)\]\((https?://[^\s)]+)\)~iu',
        static function (array $matches): string {
            return '<a href="' . $matches[2]
                . '" target="_blank" rel="noopener noreferrer">'
                . $matches[1]
                . '</a>';
        },
        $formatted
    ) ?? $formatted;

    // Не превращаем URL внутри уже созданных ссылок в ещё одну ссылку.
    $parts = preg_split(
        '/(<\/?(?:strong|em|u|s)>|<a\b[^>]*>|<\/a>)/i',
        $formatted,
        -1,
        PREG_SPLIT_DELIM_CAPTURE
    );

    if ($parts === false) {
        return nl2br($escaped, false);
    }

    $insideLink = false;

    foreach ($parts as &$part) {
        if (preg_match('/^<a\b/i', $part)) {
            $insideLink = true;
            continue;
        }

        if (strcasecmp($part, '</a>') === 0) {
            $insideLink = false;
            continue;
        }

        if (preg_match('/^<\/?(?:strong|em|u|s)>$/i', $part)) {
            continue;
        }

        if ($insideLink) {
            continue;
        }

        $part = preg_replace_callback(
            '~https?://[^\s<]+~u',
            static function (array $matches): string {
                $url = $matches[0];
                $trailing = '';

                while ($url !== '' && preg_match('/[.,!?;:]$/u', $url)) {
                    $trailing = substr($url, -1) . $trailing;
                    $url = substr($url, 0, -1);
                }

                if ($url === '') {
                    return $matches[0];
                }

                return '<a href="' . $url
                    . '" target="_blank" rel="noopener noreferrer">'
                    . $url
                    . '</a>'
                    . $trailing;
            },
            $part
        ) ?? $part;
    }

    unset($part);

    return nl2br(implode('', $parts), false);
}

function containsSearchTerm(string $text, string $query): bool
{
    if (function_exists('mb_stripos')) {
        return mb_stripos($text, $query, 0, 'UTF-8') !== false;
    }

    return stripos($text, $query) !== false;
}

function makeExcerpt(string $text, string $query, int $length = 240): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

    if (function_exists('mb_stripos') && function_exists('mb_substr')) {
        $position = mb_stripos($text, $query, 0, 'UTF-8');

        if ($position !== false) {
            $start = max(0, $position - 80);
            $excerpt = mb_substr($text, $start, $length, 'UTF-8');

            return ($start > 0 ? '…' : '')
                . $excerpt
                . (mb_strlen($text, 'UTF-8') > $start + $length ? '…' : '');
        }

        return mb_substr($text, 0, $length, 'UTF-8')
            . (mb_strlen($text, 'UTF-8') > $length ? '…' : '');
    }

    $position = stripos($text, $query);

    if ($position !== false) {
        $start = max(0, $position - 80);
        $excerpt = substr($text, $start, $length);

        return ($start > 0 ? '…' : '')
            . $excerpt
            . (strlen($text) > $start + $length ? '…' : '');
    }

    return substr($text, 0, $length)
        . (strlen($text) > $length ? '…' : '');
}

function searchArchives(string $query): array
{
    $results = [];
    $archiveFiles = glob(ARCHIVE_DIR . '/*.html') ?: [];

    foreach ($archiveFiles as $archiveFile) {
        if (!is_file($archiveFile)) {
            continue;
        }

        $html = file_get_contents($archiveFile);

        if ($html === false) {
            continue;
        }

        preg_match_all(
            '~<article\b[^>]*>(.*?)</article>~si',
            $html,
            $articles
        );

        foreach ($articles[1] ?? [] as $articleHtml) {
            $title = '';

            if (preg_match('~<h2\b[^>]*>(.*?)</h2>~si', $articleHtml, $titleMatch)) {
                $title = html_entity_decode(
                    strip_tags($titleMatch[1]),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );
            }

            $content = html_entity_decode(
                strip_tags(preg_replace('~</(?:h2|p)>|<br\s*/?>~i', ' ', $articleHtml) ?? $articleHtml),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
            $content = trim(preg_replace('/\s+/u', ' ', $content) ?? $content);

            if (
                containsSearchTerm($title, $query) ||
                containsSearchTerm($content, $query)
            ) {
                $results[] = [
                    'title' => $title !== '' ? $title : t('untitled'),
                    'content' => $content,
                    'archive' => basename($archiveFile),
                ];
            }
        }
    }

    return $results;
}

