<?php

declare(strict_types=1);

$db = new PDO('mysql:host=127.0.0.1;port=3306;dbname=suinda_app;charset=utf8mb4', 'root', '');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("DELETE FROM decks WHERE title = 'Teste MySQL API'");

echo "Dados de teste removidos." . PHP_EOL;
