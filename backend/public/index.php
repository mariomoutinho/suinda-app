<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../src/App.php';

$app = new App(require __DIR__ . '/../config.php');
$app->handle();
