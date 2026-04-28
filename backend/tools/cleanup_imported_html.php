<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config.php';
$db = new PDO('sqlite:' . $config['database_path']);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$rows = $db->query('SELECT id, question, answer, question_html, answer_html FROM cards')->fetchAll();
$update = $db->prepare(
    'UPDATE cards SET question = ?, answer = ?, question_html = ?, answer_html = ? WHERE id = ?'
);

$changed = 0;

foreach ($rows as $row) {
    $question = cleanText((string) $row['question']);
    $answer = cleanText((string) $row['answer']);
    $questionHtml = cleanHtml($row['question_html'] ?? null);
    $answerHtml = cleanHtml($row['answer_html'] ?? null);

    if (
        $question !== $row['question'] ||
        $answer !== $row['answer'] ||
        $questionHtml !== ($row['question_html'] ?? null) ||
        $answerHtml !== ($row['answer_html'] ?? null)
    ) {
        $update->execute([$question, $answer, $questionHtml, $answerHtml, (int) $row['id']]);
        $changed++;
    }
}

echo "Cartoes corrigidos: {$changed}" . PHP_EOL;

function cleanText(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace("\xc2\xa0", ' ', $value);
    $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;
    return trim($value);
}

function cleanHtml(?string $value): ?string
{
    if ($value === null || $value === '') {
        return $value;
    }

    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace("\xc2\xa0", ' ', $value);
    $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;
    return trim($value);
}
