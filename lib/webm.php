<?php
declare(strict_types=1);

function checkWebm(string $path): void
{
    if (!function_exists('proc_open')) {
        throw new BlogError('video_unavailable');
    }
    $binary = getenv('SWITTER_FFPROBE') ?: 'ffprobe';
    $command = [
        $binary, '-v', 'error', '-protocol_whitelist', 'file', '-f', 'matroska',
        '-select_streams', 'v:0', '-count_packets',
        '-show_entries', 'format=duration:stream=codec_name,nb_read_packets',
        '-of', 'json', '-i', $path,
    ];
    $process = @proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'a'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new BlogError('video_unavailable');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    $deadline = microtime(true) + 10;
    $output = '';
    $exitCode = -1;
    try {
        do {
            $output .= stream_get_contents($pipes[1], 16384);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                $output .= stream_get_contents($pipes[1], 16384);
                break;
            }
            if (microtime(true) >= $deadline || strlen($output) > 16384) {
                proc_terminate($process, 9);
                throw new BlogError('video_invalid');
            }
            usleep(10000);
        } while (true);
    } finally {
        fclose($pipes[1]);
        proc_close($process);
    }

    if ($exitCode === 127) {
        throw new BlogError('video_unavailable');
    }
    $data = json_decode($output, true);
    $codec = $data['streams'][0]['codec_name'] ?? null;
    $duration = $data['format']['duration'] ?? null;
    $packetCount = (int)($data['streams'][0]['nb_read_packets'] ?? 0);
    if ($exitCode !== 0 || $packetCount < 1 || !in_array($codec, ['vp8', 'vp9', 'av1'], true)
        || !is_numeric($duration) || (float)$duration <= 0) {
        throw new BlogError('video_invalid');
    }
    if ((float)$duration > MAX_VIDEO_SECONDS) {
        throw new BlogError('video_length');
    }
}
