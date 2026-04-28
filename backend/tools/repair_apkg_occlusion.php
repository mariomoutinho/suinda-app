<?php

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php repair_apkg_occlusion.php <deck_id> <apkg_tmp_dir>\n");
    exit(1);
}

$deckId = (int) $argv[1];
$tmpDir = rtrim($argv[2], "\\/");
$collection = $tmpDir . DIRECTORY_SEPARATOR . 'collection.import.sqlite';
$mediaFile = is_file($tmpDir . DIRECTORY_SEPARATOR . 'media.json')
    ? $tmpDir . DIRECTORY_SEPARATOR . 'media.json'
    : $tmpDir . DIRECTORY_SEPARATOR . 'media';
$projectRoot = dirname(__DIR__, 2);
$zstd = $projectRoot . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'zstd.exe';

if ($deckId <= 0 || !is_dir($tmpDir) || !is_file($collection) || !is_file($mediaFile)) {
    fwrite(STDERR, "Invalid deck id, temp dir, collection, or media file.\n");
    exit(1);
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

function read_media_contents(string $mediaFile, string $zstd): string
{
    return (string) file_get_contents(decode_media_file($mediaFile, $zstd));
}

function read_media_map(string $contents): array
{
    $decoded = json_decode($contents, true);
    if (is_array($decoded)) {
        $map = [];
        foreach ($decoded as $archive => $fileName) {
            $map[normalize_media_name((string) $fileName)] = (string) $archive;
        }
        return $map;
    }

    preg_match_all('/[A-Za-z0-9][A-Za-z0-9 _.,()#@+\-=]*\.(?:mp3|m4a|aac|ogg|oga|wav|webm|png|jpe?g|gif|webp|svg)/i', $contents, $matches);
    $map = [];
    foreach (array_values(array_unique($matches[0])) as $index => $fileName) {
        $map[normalize_media_name($fileName)] = (string) $index;
    }
    return $map;
}

function image_sources(string $html): array
{
    if (!preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
        return [];
    }

    return array_map(
        fn (string $source): string => html_entity_decode($source, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        $matches[1]
    );
}

function media_data(string $fileName, array $mediaMap, string $tmpDir, string $zstd, string $fallbackMime): ?string
{
    $archive = $mediaMap[normalize_media_name($fileName)] ?? null;
    if ($archive === null) {
        return null;
    }

    $path = $tmpDir . DIRECTORY_SEPARATOR . $archive;
    if (!is_file($path)) {
        return null;
    }

    $decodedPath = decode_media_file($path, $zstd);
    $mime = mime_content_type($decodedPath) ?: $fallbackMime;
    return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($decodedPath));
}

function media_text(string $fileName, array $mediaMap, string $tmpDir, string $zstd): ?string
{
    $archive = $mediaMap[normalize_media_name($fileName)] ?? null;
    if ($archive === null) {
        return null;
    }

    $path = $tmpDir . DIRECTORY_SEPARATOR . $archive;
    if (!is_file($path)) {
        return null;
    }

    return (string) file_get_contents(decode_media_file($path, $zstd));
}

function svg_number(string $source, string $attribute): ?float
{
    if (!preg_match('/\b' . preg_quote($attribute, '/') . '=["\']\s*(-?\d+(?:\.\d+)?)/i', $source, $match)) {
        return null;
    }

    return (float) $match[1];
}

function parse_masks(string $svg): array
{
    $width = svg_number($svg, 'width');
    $height = svg_number($svg, 'height');

    if ((!$width || !$height) && preg_match('/\bviewBox=["\']\s*[-.\d]+\s+[-.\d]+\s+([-\.\d]+)\s+([-\.\d]+)/i', $svg, $viewBox)) {
        $width = $width ?: (float) $viewBox[1];
        $height = $height ?: (float) $viewBox[2];
    }

    if (!$width || !$height || !preg_match_all('/<rect\b[^>]*>/i', $svg, $matches)) {
        return [];
    }

    $masks = [];
    foreach ($matches[0] as $rect) {
        $rectWidth = svg_number($rect, 'width');
        $rectHeight = svg_number($rect, 'height');
        if ($rectWidth === null || $rectHeight === null || $rectWidth <= 0 || $rectHeight <= 0) {
            continue;
        }

        $masks[] = [
            'x' => max(0, min(100, ((svg_number($rect, 'x') ?? 0) / $width) * 100)),
            'y' => max(0, min(100, ((svg_number($rect, 'y') ?? 0) / $height) * 100)),
            'width' => max(0, min(100, ($rectWidth / $width) * 100)),
            'height' => max(0, min(100, ($rectHeight / $height) * 100)),
            'isTarget' => (bool) preg_match('/\bclass=["\'][^"\']*\bqshape\b/i', $rect)
                || (bool) preg_match('/\bfill=["\']#?ff7e7e["\']/i', $rect),
        ];
    }

    return $masks;
}

$mediaMap = read_media_map(read_media_contents($mediaFile, $zstd));

$source = new PDO('sqlite:' . $collection);
$source->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$notes = $source->query('SELECT flds FROM notes ORDER BY id')->fetchAll();

$mysql = new PDO('mysql:host=127.0.0.1;port=3306;dbname=suinda_app;charset=utf8mb4', 'root', '');
$mysql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mysql->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$cardsStmt = $mysql->prepare('SELECT id, question FROM cards WHERE deck_id = ? AND active = 1 ORDER BY id');
$cardsStmt->execute([$deckId]);
$cards = $cardsStmt->fetchAll();
$cardLookupStmt = $mysql->prepare('SELECT id, question FROM cards WHERE deck_id = ? AND active = 1 ORDER BY id');
$cardLookupStmt->execute([$deckId]);
$cardsByQuestion = [];
foreach ($cardLookupStmt->fetchAll() as $cardRow) {
    $cardsByQuestion[(string) $cardRow['question']] = $cardRow;
}
$update = $mysql->prepare('UPDATE cards SET question = ?, answer = ?, question_html = ?, answer_html = ?, card_type = ?, image_data = ?, occlusion_masks = ? WHERE id = ?');

$cardIndex = 0;
$updated = 0;
$skipped = 0;

$mysql->beginTransaction();
try {
    foreach ($notes as $note) {
        $fields = explode("\x1f", (string) $note['flds']);
        $baseImage = image_sources((string) ($fields[2] ?? ''))[0] ?? null;
        $questionSvg = null;

        foreach ($fields as $field) {
            foreach (image_sources((string) $field) as $sourceName) {
                if (preg_match('/-Q\.svg$/i', normalize_media_name($sourceName))) {
                    $questionSvg = $sourceName;
                    break 2;
                }
            }
        }

        $sourceQuestion = trim(strip_tags(html_entity_decode((string) ($fields[0] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $card = $cardsByQuestion[$sourceQuestion] ?? ($cards[$cardIndex] ?? null);
        $cardIndex++;

        if (!$card || !$baseImage || !$questionSvg) {
            $skipped++;
            continue;
        }

        $svg = media_text($questionSvg, $mediaMap, $tmpDir, $zstd);
        $imageData = media_data($baseImage, $mediaMap, $tmpDir, $zstd, 'image/png');
        $masks = $svg ? parse_masks($svg) : [];
        $label = trim(strip_tags(html_entity_decode((string) ($fields[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        if (!$imageData || !$masks || $label === '') {
            $skipped++;
            continue;
        }

        $suffix = preg_match('/-ao-(\d+)$/i', $sourceQuestion, $match) ? ' #' . $match[1] : '';
        $question = trim($label . $suffix);
        $html = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $update->execute([$question, $label, $html, $html, 'image_occlusion', $imageData, json_encode($masks), (int) $card['id']]);
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
    'updated' => $updated,
    'skipped' => $skipped,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
