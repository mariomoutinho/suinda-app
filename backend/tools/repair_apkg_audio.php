<?php

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php repair_apkg_audio.php <deck_id> <apkg_tmp_dir>\n");
    exit(1);
}

$deckId = (int) $argv[1];
$tmpDir = rtrim($argv[2], "\\/");
$collection = $tmpDir . DIRECTORY_SEPARATOR . 'collection.import.sqlite';
$mediaFile = is_file($tmpDir . DIRECTORY_SEPARATOR . 'media.json')
    ? $tmpDir . DIRECTORY_SEPARATOR . 'media.json'
    : $tmpDir . DIRECTORY_SEPARATOR . 'media';

if ($deckId <= 0 || !is_dir($tmpDir) || !is_file($collection) || !is_file($mediaFile)) {
    fwrite(STDERR, "Invalid deck id, temp dir, collection, or media file.\n");
    exit(1);
}

$projectRoot = dirname(__DIR__, 2);
$zstd = $projectRoot . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'zstd.exe';

function anki_text(string $html): string
{
    $html = preg_replace('/\[sound:[^\]]+\]/i', '', $html) ?? $html;
    $html = preg_replace('/<\s*br\s*\/?>/i', "\n", $html) ?? $html;
    $html = preg_replace('/<\/\s*(div|p|li|tr|h[1-6])\s*>/i', "\n", $html) ?? $html;
    $html = strip_tags($html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = str_replace("\xc2\xa0", ' ', $html);
    $html = preg_replace("/[ \t]+/", ' ', $html) ?? $html;
    $html = preg_replace("/\n{3,}/", "\n\n", $html) ?? $html;
    return trim($html);
}

function normalize_media_name(string $fileName): string
{
    $fileName = html_entity_decode($fileName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $fileName = rawurldecode($fileName);
    $fileName = preg_replace('/[#?].*$/', '', $fileName) ?? $fileName;
    return basename(str_replace('\\', '/', trim($fileName)));
}

function is_zstd_file(string $path): bool
{
    return file_get_contents($path, false, null, 0, 4) === "\x28\xb5\x2f\xfd";
}

function decode_media_file(string $path, string $zstd): string
{
    if (!is_zstd_file($path)) {
        return $path;
    }

    $target = $path . '.decoded';
    if (is_file($target) && filesize($target) > 0) {
        return $target;
    }

    $command = escapeshellarg($zstd) . ' -d -f -q -o ' . escapeshellarg($target) . ' ' . escapeshellarg($path);
    exec($command, $output, $code);

    if ($code !== 0 || !is_file($target) || filesize($target) === 0) {
        throw new RuntimeException('Could not decompress media file: ' . $path);
    }

    return $target;
}

$contents = (string) file_get_contents($mediaFile);
preg_match_all('/[A-Za-z0-9][A-Za-z0-9 _.,()#@+\-=]*\.(?:mp3|m4a|aac|ogg|oga|wav|webm|png|jpe?g|gif|webp|svg)/i', $contents, $matches);

$mediaMap = [];
foreach (array_values(array_unique($matches[0])) as $index => $fileName) {
    $cleanName = preg_replace('/^\d+(?=[A-Za-z_-])/', '', $fileName) ?? $fileName;
    $mediaMap[$cleanName] = (string) $index;
}

$source = new PDO('sqlite:' . $collection);
$source->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$notes = $source->query('SELECT flds FROM notes ORDER BY id')->fetchAll();

$mysql = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=suinda_app;charset=utf8mb4',
    'root',
    ''
);
$mysql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mysql->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$cards = $mysql->prepare('SELECT id, question, answer FROM cards WHERE deck_id = ? AND active = 1 ORDER BY id');
$cards->execute([$deckId]);
$cards = $cards->fetchAll();

$cardIndex = 0;
$updated = 0;
$skipped = 0;
$update = $mysql->prepare('UPDATE cards SET audio_data = ? WHERE id = ?');

$mysql->beginTransaction();
try {
    foreach ($notes as $note) {
        $fields = explode("\x1f", (string) $note['flds']);
        $question = anki_text((string) ($fields[0] ?? ''));
        $answer = anki_text((string) ($fields[1] ?? ''));

        if ($question === '' || $answer === '') {
            $skipped++;
            continue;
        }

        $card = $cards[$cardIndex] ?? null;
        $cardIndex++;

        if (!$card || !preg_match('/\[sound:([^\]]+)\]/i', (string) $note['flds'], $match)) {
            continue;
        }

        $fileName = normalize_media_name($match[1]);
        $archive = $mediaMap[$fileName] ?? null;
        $path = $archive === null ? null : $tmpDir . DIRECTORY_SEPARATOR . $archive;

        if (!$path || !is_file($path)) {
            continue;
        }

        $decodedPath = decode_media_file($path, $zstd);
        $mime = mime_content_type($decodedPath) ?: 'audio/mpeg';
        $audioData = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($decodedPath));
        $update->execute([$audioData, (int) $card['id']]);
        $updated++;
    }

    $mysql->commit();
} catch (Throwable $error) {
    $mysql->rollBack();
    throw $error;
}

echo json_encode([
    'deckId' => $deckId,
    'notes' => count($notes),
    'cards' => count($cards),
    'skippedNotes' => $skipped,
    'updated' => $updated,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
