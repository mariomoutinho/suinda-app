<?php

declare(strict_types=1);

final class App
{
    private PDO $db;

    public function __construct(private array $config)
    {
        $this->db = $this->connect();
        $this->migrate();

        if (!empty($this->config['seed_on_boot'])) {
            $this->seed();
        }
    }

    public function handle(): void
    {
        $this->cors();

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
        $path = rtrim($path, '/') ?: '/';

        try {
            if ($method === 'GET' && $path === '/') {
                $this->json(['name' => 'Suinda API', 'status' => 'online']);
                return;
            }

            if ($method === 'POST' && $path === '/auth/login') {
                $this->login();
                return;
            }

            $user = $this->requireUser();

            if ($method === 'GET' && $path === '/me') {
                $this->json(['user' => $user]);
                return;
            }

            if ($method === 'GET' && $path === '/decks') {
                $this->listDecks();
                return;
            }

            if ($method === 'POST' && $path === '/decks') {
                $this->createDeck();
                return;
            }

            if ($method === 'GET' && preg_match('#^/decks/(\d+)$#', $path, $match)) {
                $this->showDeck((int) $match[1]);
                return;
            }

            if ($method === 'PUT' && preg_match('#^/decks/(\d+)$#', $path, $match)) {
                $this->updateDeck((int) $match[1]);
                return;
            }

            if ($method === 'DELETE' && preg_match('#^/decks/(\d+)$#', $path, $match)) {
                $this->deleteDeck((string) $user['role'], (int) $match[1]);
                return;
            }

            if ($method === 'GET' && preg_match('#^/decks/(\d+)/cards$#', $path, $match)) {
                $this->listCards((int) $match[1]);
                return;
            }

            if ($method === 'POST' && preg_match('#^/decks/(\d+)/cards$#', $path, $match)) {
                $this->createCard((int) $match[1]);
                return;
            }

            if ($method === 'PUT' && preg_match('#^/cards/(\d+)$#', $path, $match)) {
                $this->updateCard((int) $user['id'], (string) $user['role'], (int) $match[1]);
                return;
            }

            if ($method === 'DELETE' && preg_match('#^/cards/(\d+)$#', $path, $match)) {
                $this->deleteCard((string) $user['role'], (int) $match[1]);
                return;
            }

            if ($method === 'POST' && $path === '/import') {
                $this->importCards();
                return;
            }

            if ($method === 'POST' && $path === '/import/apkg') {
                $this->importApkg();
                return;
            }

            if ($method === 'GET' && $path === '/stats/today') {
                $this->todayStats((int) $user['id']);
                return;
            }

            if ($method === 'GET' && $path === '/stats/daily') {
                $this->dailyStats((int) $user['id']);
                return;
            }

            if ($method === 'POST' && $path === '/sync') {
                $this->sync((int) $user['id']);
                return;
            }

            if ($method === 'GET' && $path === '/study/history') {
                $this->studyHistory((int) $user['id']);
                return;
            }

            if ($method === 'POST' && $path === '/study/sessions') {
                $this->saveStudySession((int) $user['id']);
                return;
            }

            if ($method === 'GET' && $path === '/cards/progress') {
                $this->cardProgress((int) $user['id']);
                return;
            }

            if ($method === 'PUT' && preg_match('#^/cards/(\d+)/progress$#', $path, $match)) {
                $this->saveCardProgress((int) $user['id'], (int) $match[1]);
                return;
            }

            $this->json(['error' => 'Rota nao encontrada.'], 404);
        } catch (Throwable $exception) {
            $this->json(['error' => $exception->getMessage()], 500);
        }
    }

    private function connect(): PDO
    {
        if (($this->config['database_driver'] ?? 'sqlite') === 'mysql') {
            $mysql = $this->config['mysql'];
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $mysql['host'],
                $mysql['port'],
                $mysql['database'],
                $mysql['charset'] ?? 'utf8mb4'
            );

            $db = new PDO($dsn, $mysql['username'], $mysql['password']);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $db;
        }

        $databaseDir = dirname($this->config['database_path']);
        if (!is_dir($databaseDir)) {
            mkdir($databaseDir, 0775, true);
        }

        $db = new PDO('sqlite:' . $this->config['database_path']);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA foreign_keys = ON');

        return $db;
    }

    private function migrate(): void
    {
        if (($this->config['database_driver'] ?? 'sqlite') === 'mysql') {
            $this->migrateMysql();
            return;
        }

        $this->db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'student',
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS auth_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS decks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    description TEXT NOT NULL,
    category TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    deck_id INTEGER NOT NULL,
    question TEXT NOT NULL,
    answer TEXT NOT NULL,
    question_html TEXT,
    answer_html TEXT,
    card_type TEXT NOT NULL DEFAULT 'basic',
    image_data TEXT,
    audio_data TEXT,
    occlusion_masks TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (deck_id) REFERENCES decks(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS card_progress (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    card_id INTEGER NOT NULL,
    state TEXT NOT NULL,
    due_at TEXT,
    ease_factor REAL,
    interval_days INTEGER,
    repetitions INTEGER,
    lapses INTEGER,
    introduced_at TEXT,
    last_rating TEXT,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, card_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS study_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    deck_id INTEGER NOT NULL,
    deck_title TEXT NOT NULL,
    total INTEGER NOT NULL,
    wrong INTEGER NOT NULL,
    hard INTEGER NOT NULL,
    easy INTEGER NOT NULL,
    very_easy INTEGER NOT NULL,
    started_at TEXT,
    finished_at TEXT,
    duration_in_seconds INTEGER NOT NULL,
    average_seconds_per_card INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (deck_id) REFERENCES decks(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS study_answers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL,
    card_id INTEGER NOT NULL,
    question TEXT NOT NULL,
    answer_type TEXT NOT NULL,
    next_state TEXT,
    due_at TEXT,
    FOREIGN KEY (session_id) REFERENCES study_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS study_daily_activity (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    day TEXT NOT NULL,
    total_cards INTEGER NOT NULL DEFAULT 0,
    total_seconds INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, day),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
SQL);
        $this->addColumnIfMissing('cards', 'card_type', "TEXT NOT NULL DEFAULT 'basic'");
        $this->addColumnIfMissing('cards', 'question_html', 'TEXT');
        $this->addColumnIfMissing('cards', 'answer_html', 'TEXT');
        $this->addColumnIfMissing('cards', 'image_data', 'TEXT');
        $this->addColumnIfMissing('cards', 'audio_data', 'TEXT');
        $this->addColumnIfMissing('cards', 'occlusion_masks', 'TEXT');
        $this->addColumnIfMissing('card_progress', 'introduced_at', 'TEXT');
        $this->addColumnIfMissing('card_progress', 'last_rating', 'TEXT');
    }

    private function migrateMysql(): void
    {
        $schema = file_get_contents(__DIR__ . '/../database/schema.mysql.sql');
        if ($schema === false) {
            throw new RuntimeException('Schema MySQL nao encontrado.');
        }

        foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
            $this->db->exec($statement);
        }

        $this->addColumnIfMissing('cards', 'card_type', "VARCHAR(40) NOT NULL DEFAULT 'basic'");
        $this->addColumnIfMissing('cards', 'question_html', 'LONGTEXT');
        $this->addColumnIfMissing('cards', 'answer_html', 'LONGTEXT');
        $this->addColumnIfMissing('cards', 'image_data', 'LONGTEXT');
        $this->addColumnIfMissing('cards', 'audio_data', 'LONGTEXT');
        $this->addColumnIfMissing('cards', 'occlusion_masks', 'LONGTEXT');
        $this->addColumnIfMissing('card_progress', 'introduced_at', 'VARCHAR(40)');
        $this->addColumnIfMissing('card_progress', 'last_rating', 'VARCHAR(30)');
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if (($this->config['database_driver'] ?? 'sqlite') === 'mysql') {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([$table, $column]);
            if ((int) $stmt->fetchColumn() > 0) {
                return;
            }

            $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            return;
        }

        $columns = $this->db->query("PRAGMA table_info($table)")->fetchAll();
        foreach ($columns as $existing) {
            if ($existing['name'] === $column) {
                return;
            }
        }

        $this->db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }

    private function seed(): void
    {
        $count = (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count === 0) {
            $stmt = $this->db->prepare('INSERT INTO users (name, email, password_hash, role, active) VALUES (?, ?, ?, ?, 1)');
            $stmt->execute(['Aluno Teste', 'aluno@suinda.com', password_hash('123456', PASSWORD_DEFAULT), 'student']);
        }

        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute(['admin@suinda.com']);
        $admin = $stmt->fetch();
        if (!$admin) {
            $stmt = $this->db->prepare('INSERT INTO users (name, email, password_hash, role, active) VALUES (?, ?, ?, ?, 1)');
            $stmt->execute(['Administrador', 'admin@suinda.com', password_hash('admin123', PASSWORD_DEFAULT), 'admin']);
        } elseif (
            $admin['name'] !== 'Administrador' ||
            $admin['role'] !== 'admin' ||
            (int) $admin['active'] !== 1 ||
            !password_verify('admin123', $admin['password_hash'])
        ) {
            $stmt = $this->db->prepare('UPDATE users SET name = ?, password_hash = ?, role = ?, active = 1 WHERE email = ?');
            $stmt->execute(['Administrador', password_hash('admin123', PASSWORD_DEFAULT), 'admin', 'admin@suinda.com']);
        }

        $count = (int) $this->db->query('SELECT COUNT(*) FROM decks')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $decks = [
            [' Biologia Basica', 'Introducao aos conceitos fundamentais de biologia.', 'Biologia'],
            [' Historia do Brasil', 'Principais marcos historicos do Brasil.', 'Historia'],
            [' Ingles Essencial', 'Vocabulario e expressoes basicas do ingles.', 'Idiomas'],
        ];

        $deckStmt = $this->db->prepare('INSERT INTO decks (title, description, category) VALUES (?, ?, ?)');
        foreach ($decks as $deck) {
            $deckStmt->execute($deck);
        }

        $cards = [
            [1, 'O que e celula?', 'E a unidade estrutural e funcional dos seres vivos.'],
            [1, 'O que e fotossintese?', 'E o processo pelo qual plantas produzem alimento usando luz, agua e gas carbonico.'],
            [1, 'O que sao seres autotrofos?', 'Sao seres capazes de produzir seu proprio alimento.'],
            [2, 'Em que ano ocorreu a Independencia do Brasil?', 'Em 1822.'],
            [2, 'Quem foi Dom Pedro I?', 'Foi o primeiro imperador do Brasil.'],
            [2, 'O que foi a Proclamacao da Republica?', 'Foi o evento que encerrou o Imperio e instaurou a Republica no Brasil, em 1889.'],
            [3, "Como dizer 'ola' em ingles?", 'Hello.'],
            [3, "Como dizer 'obrigado' em ingles?", 'Thank you.'],
            [3, "Como dizer 'livro' em ingles?", 'Book.'],
        ];

        $cardStmt = $this->db->prepare('INSERT INTO cards (deck_id, question, answer) VALUES (?, ?, ?)');
        foreach ($cards as $card) {
            $cardStmt->execute($card);
        }
    }

    private function login(): void
    {
        $data = $this->input();
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ? AND active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->json(['error' => 'E-mail ou senha invalidos.'], 401);
            return;
        }

        $token = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare('INSERT INTO auth_tokens (user_id, token) VALUES (?, ?)');
        $stmt->execute([(int) $user['id'], $token]);

        $this->json([
            'token' => $token,
            'user' => $this->publicUser($user),
        ]);
    }

    private function requireUser(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/Bearer\s+(.+)/', $header, $match)) {
            $this->json(['error' => 'Token nao informado.'], 401);
            exit;
        }

        $stmt = $this->db->prepare(
            'SELECT users.* FROM users INNER JOIN auth_tokens ON auth_tokens.user_id = users.id WHERE auth_tokens.token = ? AND users.active = 1 LIMIT 1'
        );
        $stmt->execute([$match[1]]);
        $user = $stmt->fetch();

        if (!$user) {
            $this->json(['error' => 'Token invalido.'], 401);
            exit;
        }

        return $this->publicUser($user);
    }

    private function listDecks(): void
    {
        $rows = $this->db->query(
            'SELECT decks.id, decks.title, decks.description, decks.category, COUNT(cards.id) AS totalCards
             FROM decks
             LEFT JOIN cards ON cards.deck_id = decks.id AND cards.active = 1
             WHERE decks.active = 1
             GROUP BY decks.id
             ORDER BY decks.id'
        )->fetchAll();

        $this->json(['decks' => array_map([$this, 'normalizeDeck'], $rows)]);
    }

    private function showDeck(int $id): void
    {
        $stmt = $this->db->prepare(
            'SELECT decks.id, decks.title, decks.description, decks.category, COUNT(cards.id) AS totalCards
             FROM decks
             LEFT JOIN cards ON cards.deck_id = decks.id AND cards.active = 1
             WHERE decks.id = ? AND decks.active = 1
             GROUP BY decks.id'
        );
        $stmt->execute([$id]);
        $deck = $stmt->fetch();

        if (!$deck) {
            $this->json(['error' => 'Baralho nao encontrado.'], 404);
            return;
        }

        $this->json(['deck' => $this->normalizeDeck($deck)]);
    }

    private function createDeck(): void
    {
        $data = $this->input();
        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $category = trim((string) ($data['category'] ?? 'Geral'));

        if ($title === '') {
            $this->json(['error' => 'Informe o nome do baralho.'], 422);
            return;
        }

        if ($description === '') {
            $description = 'Baralho criado pelo usuario.';
        }

        $stmt = $this->db->prepare('INSERT INTO decks (title, description, category) VALUES (?, ?, ?)');
        $stmt->execute([$title, $description, $category]);

        $this->showDeck((int) $this->db->lastInsertId());
    }

    private function updateDeck(int $id): void
    {
        $current = $this->findDeckSummary($id);
        if (!$current) {
            $this->json(['error' => 'Baralho nao encontrado.'], 404);
            return;
        }

        $data = $this->input();
        $title = trim((string) ($data['title'] ?? $current['title']));
        $description = trim((string) ($data['description'] ?? $current['description']));
        $category = trim((string) ($data['category'] ?? $current['category']));

        if ($title === '') {
            $this->json(['error' => 'Informe o nome do baralho.'], 422);
            return;
        }

        if ($description === '') {
            $description = 'Baralho criado pelo usuario.';
        }

        if ($category === '') {
            $category = 'Geral';
        }

        $stmt = $this->db->prepare(
            'UPDATE decks SET title = ?, description = ?, category = ? WHERE id = ? AND active = 1'
        );
        $stmt->execute([$title, $description, $category, $id]);

        $this->showDeck($id);
    }

    private function deleteDeck(string $role, int $id): void
    {
        $this->requireAdmin($role);

        $current = $this->findDeckSummary($id);
        if (!$current) {
            $this->json(['error' => 'Baralho nao encontrado.'], 404);
            return;
        }

        $titlePrefix = $current['title'] . '::%';

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'DELETE FROM decks WHERE id = ? OR title LIKE ?'
            );
            $stmt->execute([$id, $titlePrefix]);

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        $this->json(['ok' => true, 'deleted' => 'permanent']);
    }

    private function listCards(int $deckId): void
    {
        $includeMedia = ($_GET['includeMedia'] ?? '1') !== '0';
        $columns = $includeMedia
            ? '*'
            : 'id, deck_id, question, answer, question_html, answer_html, card_type, occlusion_masks';
        $stmt = $this->db->prepare('SELECT ' . $columns . ' FROM cards WHERE deck_id = ? AND active = 1 ORDER BY id');
        $stmt->execute([$deckId]);

        $cards = array_map(fn ($card) => $this->normalizeCard($card, $includeMedia), $stmt->fetchAll());
        $this->json(['cards' => $cards]);
    }

    private function createCard(int $deckId): void
    {
        $data = $this->input();
        $question = trim((string) ($data['question'] ?? ''));
        $answer = trim((string) ($data['answer'] ?? ''));
        $cardType = (string) ($data['cardType'] ?? 'basic');

        if ($question === '' || $answer === '') {
            $this->json(['error' => 'Informe frente e verso do cartao.'], 422);
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO cards (deck_id, question, answer, question_html, answer_html, card_type, image_data, audio_data, occlusion_masks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $deckId,
            $question,
            $answer,
            $data['questionHtml'] ?? null,
            $data['answerHtml'] ?? null,
            $cardType,
            $data['imageData'] ?? null,
            $data['audioData'] ?? null,
            isset($data['occlusionMasks']) ? json_encode($data['occlusionMasks']) : null,
        ]);

        $this->json(['card' => $this->getCard((int) $this->db->lastInsertId())], 201);
    }

    private function updateCard(int $userId, string $role, int $cardId): void
    {
        $this->requireAdmin($role);
        $data = $this->input();
        $current = $this->getCard($cardId);

        if (!$current) {
            $this->json(['error' => 'Cartao nao encontrado.'], 404);
            return;
        }

        $deckId = (int) ($data['deckId'] ?? $current['deckId']);

        if ($deckId <= 0) {
            $this->json(['error' => 'Informe o baralho do cartao.'], 422);
            return;
        }

        $stmt = $this->db->prepare(
            'UPDATE cards
             SET deck_id = ?, question = ?, answer = ?, question_html = ?, answer_html = ?, card_type = ?, image_data = ?, audio_data = ?, occlusion_masks = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $deckId,
            trim((string) ($data['question'] ?? '')),
            trim((string) ($data['answer'] ?? '')),
            $data['questionHtml'] ?? null,
            $data['answerHtml'] ?? null,
            (string) ($data['cardType'] ?? 'basic'),
            array_key_exists('imageData', $data) ? $data['imageData'] : ($current['imageData'] ?? null),
            array_key_exists('audioData', $data) ? $data['audioData'] : ($current['audioData'] ?? null),
            isset($data['occlusionMasks']) ? json_encode($data['occlusionMasks']) : null,
            $cardId,
        ]);

        $this->json(['card' => $this->getCard($cardId), 'userId' => $userId]);
    }

    private function deleteCard(string $role, int $cardId): void
    {
        $this->requireAdmin($role);

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare('DELETE FROM card_progress WHERE card_id = ?');
            $stmt->execute([$cardId]);

            $stmt = $this->db->prepare('DELETE FROM study_answers WHERE card_id = ?');
            $stmt->execute([$cardId]);

            $stmt = $this->db->prepare('DELETE FROM cards WHERE id = ?');
            $stmt->execute([$cardId]);

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        $this->json(['ok' => true]);
    }

    private function requireAdmin(string $role): void
    {
        if ($role !== 'admin') {
            $this->json(['error' => 'Apenas administradores podem alterar cards.'], 403);
            exit;
        }
    }

    private function getCard(int $cardId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM cards WHERE id = ? LIMIT 1');
        $stmt->execute([$cardId]);
        $card = $stmt->fetch();

        if (!$card) {
            return [];
        }

        return $this->normalizeCard($card);
    }

    private function importCards(): void
    {
        $data = $this->input();
        $deckId = (int) ($data['deckId'] ?? 0);
        $content = (string) ($data['content'] ?? '');
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $created = 0;

        $stmt = $this->db->prepare('INSERT INTO cards (deck_id, question, answer) VALUES (?, ?, ?)');

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\t|;/', $line, 2);
            if (!$parts || count($parts) < 2) {
                continue;
            }

            $question = trim($parts[0]);
            $answer = trim($parts[1]);

            if ($question === '' || $answer === '') {
                continue;
            }

            $stmt->execute([$deckId, $this->ankiHtmlToText($question), $this->ankiHtmlToText($answer)]);
            $created++;
        }

        $this->json(['imported' => $created], 201);
    }

    private function importApkg(): void
    {
        $deckId = (int) ($_POST['deckId'] ?? 0);
        $autoCreateDeck = (string) ($_POST['autoCreateDeck'] ?? '1') === '1';
        $deckTitle = trim((string) ($_POST['deckTitle'] ?? ''));

        if (empty($_FILES['file']['tmp_name'])) {
            $this->json(['error' => 'Envie um arquivo .apkg.'], 422);
            return;
        }

        if (!$autoCreateDeck && $deckId <= 0) {
            $this->json(['error' => 'Escolha um baralho ou marque a opcao de criar baralho automaticamente.'], 422);
            return;
        }

        if (!class_exists('ZipArchive')) {
            $this->json(['error' => 'A extensao ZipArchive do PHP e necessaria para importar .apkg.'], 500);
            return;
        }

        $tmpDir = sys_get_temp_dir() . '/suinda_apkg_' . bin2hex(random_bytes(5));
        mkdir($tmpDir, 0775, true);

        $zip = new ZipArchive();
        if ($zip->open($_FILES['file']['tmp_name']) !== true) {
            $this->json(['error' => 'Nao foi possivel abrir o pacote Anki.'], 422);
            return;
        }

        $zip->extractTo($tmpDir);
        $zip->close();

        $collection = $this->resolveAnkiCollectionPath($tmpDir);

        if (!$collection) {
            $this->json([
                'error' => 'Pacote Anki em formato novo. Instale o utilitario zstd no computador para importar arquivos .apkg recentes.',
            ], 422);
            return;
        }

        if ($autoCreateDeck) {
            if ($deckTitle === '') {
                $deckTitle = (string) ($_FILES['file']['name'] ?? 'Baralho importado');
            }

            $deckId = $this->createImportedDeck($deckTitle);
        }

        $source = new PDO('sqlite:' . $collection);
        $source->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $notes = $source->query('SELECT id, mid, flds FROM notes ORDER BY id')->fetchAll();
        $models = $this->readAnkiModels($source);
        $mediaMap = $this->readAnkiMediaMap($tmpDir);
        $mediaIndex = $this->buildAnkiMediaIndex($tmpDir, $mediaMap);
        $created = 0;
        $stmt = $this->db->prepare(
            'INSERT INTO cards (deck_id, question, answer, question_html, answer_html, card_type, image_data, audio_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $updateExisting = $this->db->prepare(
            'UPDATE cards SET question = ?, answer = ?, question_html = ?, answer_html = ?, card_type = ?, image_data = ?, audio_data = ? WHERE id = ?'
        );
        $existingCards = $this->db->prepare('SELECT id, question FROM cards WHERE deck_id = ? AND active = 1');
        $existingCards->execute([$deckId]);
        $existingByQuestion = [];

        foreach ($existingCards->fetchAll() as $existingCard) {
            $existingByQuestion[(string) $existingCard['question']] = (int) $existingCard['id'];
        }

        $this->db->beginTransaction();

        try {
            foreach ($notes as $note) {
                $fields = explode("\x1f", (string) $note['flds']);
                $rendered = $this->renderAnkiNote($note, $fields, $models);
                $frontHtml = $rendered['front'] ?: (string) ($fields[0] ?? '');
                $backHtml = $rendered['back'] ?: (string) ($fields[1] ?? '');
                $question = $this->ankiHtmlToText($frontHtml);
                $answer = $this->ankiHtmlToText($backHtml);
                $questionHtml = $this->sanitizeAnkiHtml($frontHtml);
                $answerHtml = $this->sanitizeAnkiHtml($backHtml);
                $imageData = $this->extractAnkiMediaData($frontHtml . $backHtml, $mediaIndex, 'image');
                $audioData = $this->extractAnkiMediaData($frontHtml . $backHtml, $mediaIndex, 'audio');

                if ($question === '' || $answer === '') {
                    continue;
                }

                $existingId = $existingByQuestion[$question] ?? null;

                if ($existingId) {
                    $updateExisting->execute([$question, $answer, $questionHtml, $answerHtml, 'basic', $imageData, $audioData, $existingId]);
                } else {
                    $stmt->execute([$deckId, $question, $answer, $questionHtml, $answerHtml, 'basic', $imageData, $audioData]);
                    $existingByQuestion[$question] = (int) $this->db->lastInsertId();
                }

                $created++;
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        $this->json(['imported' => $created, 'deck' => $this->findDeckSummary($deckId)], 201);
    }

    private function createImportedDeck(string $rawTitle): int
    {
        $title = preg_replace('/\.apkg$/i', '', $rawTitle) ?? $rawTitle;
        $title = preg_replace('/^#+/', '', $title) ?? $title;
        $title = str_replace('__', ' - ', $title);
        $title = str_replace('_', ' ', $title);
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;
        $title = trim($title);

        if ($title === '') {
            $title = 'Baralho importado';
        }

        $title = substr($title, 0, 150);
        $baseTitle = $title;
        $suffix = 2;

        while ($this->deckTitleExists($title)) {
            $suffixText = ' (' . $suffix . ')';
            $title = substr($baseTitle, 0, 150 - strlen($suffixText)) . $suffixText;
            $suffix++;
        }

        $stmt = $this->db->prepare('INSERT INTO decks (title, description, category) VALUES (?, ?, ?)');
        $stmt->execute([$title, 'Baralho importado do Anki.', 'Importado']);

        return (int) $this->db->lastInsertId();
    }

    private function deckTitleExists(string $title): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM decks WHERE title = ? AND active = 1 LIMIT 1');
        $stmt->execute([$title]);

        return (bool) $stmt->fetchColumn();
    }

    private function findDeckSummary(int $deckId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT decks.id, decks.title, decks.description, decks.category, COUNT(cards.id) AS totalCards
             FROM decks
             LEFT JOIN cards ON cards.deck_id = decks.id AND cards.active = 1
             WHERE decks.id = ? AND decks.active = 1
             GROUP BY decks.id'
        );
        $stmt->execute([$deckId]);
        $deck = $stmt->fetch();

        return $deck ? $this->normalizeDeck($deck) : null;
    }

    private function ankiHtmlToText(string $html): string
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

    private function sanitizeAnkiHtml(string $html): ?string
    {
        $html = preg_replace('/<\s*(script|style)[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html) ?? $html;
        $html = preg_replace('/\[sound:[^\]]+\]/i', '', $html) ?? $html;
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = str_replace("\xc2\xa0", ' ', $html);
        $html = strip_tags($html, '<b><strong><i><em><u><br><div><p><span><ul><ol><li><sup><sub>');
        $html = preg_replace('/\s+(style|class|id|onclick|onerror|onload|href|src)=("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = trim($html);

        return $html === '' ? null : $html;
    }

    private function extractAnkiSoundMarkers(string $html): array
    {
        if (!preg_match_all('/\[sound:[^\]]+\]/i', $html, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[0]));
    }

    private function readAnkiModels(PDO $source): array
    {
        try {
            $modelsJson = (string) $source->query('SELECT models FROM col LIMIT 1')->fetchColumn();
            $models = json_decode($modelsJson, true);
            return is_array($models) ? $models : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function renderAnkiNote(array $note, array $fields, array $models): array
    {
        $model = $models[(string) ($note['mid'] ?? '')] ?? null;

        if (!is_array($model)) {
            return [
                'front' => (string) ($fields[0] ?? ''),
                'back' => (string) ($fields[1] ?? ''),
            ];
        }

        $fieldMap = [];
        foreach (($model['flds'] ?? []) as $index => $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name !== '') {
                $fieldMap[$name] = (string) ($fields[$index] ?? '');
            }
        }

        $template = $model['tmpls'][0] ?? null;
        if (!is_array($template)) {
            return [
                'front' => $this->firstNonEmptyAnkiField($fields),
                'back' => $this->secondNonEmptyAnkiField($fields),
            ];
        }

        $front = $this->renderAnkiTemplate((string) ($template['qfmt'] ?? ''), $fieldMap);
        $backTemplate = (string) ($template['afmt'] ?? '');
        $back = str_replace('{{FrontSide}}', $front, $backTemplate);
        $back = $this->renderAnkiTemplate($back, $fieldMap);
        if ($front !== '') {
            $back = preg_replace('/' . preg_quote($front, '/') . '/u', '', $back, 1) ?? $back;
        }

        if ($this->ankiHtmlToText($front) === '') {
            $front = $this->firstNonEmptyAnkiField($fields);
        }

        if ($this->ankiHtmlToText($back) === '') {
            $back = $this->secondNonEmptyAnkiField($fields);
        }

        return [
            'front' => $front,
            'back' => $back,
        ];
    }

    private function renderAnkiTemplate(string $template, array $fields): string
    {
        $rendered = $template;

        $rendered = preg_replace_callback('/{{#([^}]+)}}(.*?){{\/\1}}/s', function ($match) use ($fields) {
            $name = trim((string) $match[1]);
            return trim((string) ($fields[$name] ?? '')) !== '' ? (string) $match[2] : '';
        }, $rendered) ?? $rendered;

        $rendered = preg_replace_callback('/{{\^([^}]+)}}(.*?){{\/\1}}/s', function ($match) use ($fields) {
            $name = trim((string) $match[1]);
            return trim((string) ($fields[$name] ?? '')) === '' ? (string) $match[2] : '';
        }, $rendered) ?? $rendered;

        $rendered = preg_replace_callback('/{{type:([^}]+)}}/i', function ($match) use ($fields) {
            return (string) ($fields[trim((string) $match[1])] ?? '');
        }, $rendered) ?? $rendered;

        $rendered = preg_replace_callback('/{{([^}]+)}}/', function ($match) use ($fields) {
            $name = trim((string) $match[1]);
            return (string) ($fields[$name] ?? '');
        }, $rendered) ?? $rendered;

        $rendered = preg_replace('/<\s*(script|style)[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $rendered) ?? $rendered;

        return trim($rendered);
    }

    private function firstNonEmptyAnkiField(array $fields): string
    {
        foreach ($fields as $field) {
            if ($this->ankiHtmlToText((string) $field) !== '') {
                return (string) $field;
            }
        }

        return '';
    }

    private function secondNonEmptyAnkiField(array $fields): string
    {
        $foundFirst = false;

        foreach ($fields as $field) {
            if ($this->ankiHtmlToText((string) $field) === '') {
                continue;
            }

            if ($foundFirst) {
                return (string) $field;
            }

            $foundFirst = true;
        }

        return '';
    }

    private function resolveAnkiCollectionPath(string $tmpDir): ?string
    {
        $candidates = [
            $tmpDir . '/collection.anki21b',
            $tmpDir . '/collection.anki21',
            $tmpDir . '/collection.anki2',
        ];

        foreach ($candidates as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            $path = $candidate;
            if ($this->isZstdFile($candidate)) {
                $path = $tmpDir . '/collection.import.sqlite';
                if (!$this->decompressZstdFile($candidate, $path)) {
                    continue;
                }
            }

            if (!$this->isUsableAnkiCollection($path)) {
                continue;
            }

            return $path;
        }

        return null;
    }

    private function isZstdFile(string $path): bool
    {
        $bytes = file_get_contents($path, false, null, 0, 4);
        return $bytes === "\x28\xb5\x2f\xfd";
    }

    private function decompressZstdFile(string $source, string $target): bool
    {
        if (function_exists('zstd_uncompress')) {
            $data = zstd_uncompress((string) file_get_contents($source));
            if ($data !== false) {
                file_put_contents($target, $data);
                return true;
            }
        }

        $zstd = $this->findZstdExecutable();
        if (!$zstd) {
            return false;
        }

        $command = escapeshellarg($zstd) . ' -d -f -q -o ' . escapeshellarg($target) . ' ' . escapeshellarg($source);
        exec($command, $output, $code);

        return $code === 0 && is_file($target) && filesize($target) > 0;
    }

    private function findZstdExecutable(): ?string
    {
        $paths = [
            dirname(__DIR__, 2) . '/tools/zstd.exe',
            'zstd',
        ];
        $localAppData = getenv('LOCALAPPDATA');

        if ($localAppData) {
            $localAppData = str_replace('\\', '/', $localAppData);
            $paths[] = $localAppData . '/Microsoft/WinGet/Links/zstd.exe';
            $packageRoot = $localAppData . '/Microsoft/WinGet/Packages/Meta.Zstandard_Microsoft.Winget.Source_8wekyb3d8bbwe';
            foreach (glob($packageRoot . '/zstd-*/zstd.exe') ?: [] as $candidate) {
                $paths[] = $candidate;
            }
        }

        foreach ($paths as $path) {
            if ($path === 'zstd') {
                exec('zstd --version', $output, $code);
                if ($code === 0) {
                    return 'zstd';
                }
                continue;
            }

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function isUsableAnkiCollection(string $path): bool
    {
        try {
            $db = new PDO('sqlite:' . $path);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $count = (int) $db->query('SELECT COUNT(*) FROM notes')->fetchColumn();
            if ($count === 0) {
                return false;
            }

            if ($count === 1) {
                $fields = (string) $db->query('SELECT flds FROM notes LIMIT 1')->fetchColumn();
                if (str_contains($fields, 'Atualize para a versão mais recente do Anki')) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function readAnkiMediaMap(string $tmpDir): array
    {
        $candidates = [
            $tmpDir . '/media',
            $tmpDir . '/collection.media',
        ];

        $mediaPath = null;

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $mediaPath = $candidate;
                break;
            }
        }

        if (!$mediaPath) return [];

        $path = $mediaPath;
        if ($this->isZstdFile($mediaPath)) {
            $path = $tmpDir . '/media.json';
            if (!$this->decompressZstdFile($mediaPath, $path)) {
                return [];
            }
        }

        $contents = (string) file_get_contents($path);
        $decoded = json_decode($contents, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        return $this->readAnkiBinaryMediaMap($contents);
    }

    private function readAnkiBinaryMediaMap(string $contents): array
    {
        $extensions = 'mp3|m4a|aac|ogg|oga|wav|webm|png|jpe?g|gif|webp|svg';

        if (!preg_match_all('/[A-Za-z0-9][A-Za-z0-9 _.,()#@+\-=]*\.(?:' . $extensions . ')/i', $contents, $matches)) {
            return [];
        }

        $map = [];
        foreach (array_values(array_unique($matches[0])) as $index => $fileName) {
            $map[(string) $index] = $fileName;
        }

        return $map;
    }

    private function buildAnkiMediaIndex(string $tmpDir, array $mediaMap): array
    {
        $index = [];

        foreach ($mediaMap as $archive => $mappedName) {
            $path = $tmpDir . '/' . $archive;
            if (!is_file($path)) {
                continue;
            }

            $normalized = $this->normalizeAnkiMediaName((string) $mappedName);
            if ($normalized !== '') {
                $index[$normalized] = $path;
                $index[strtolower($normalized)] = $path;
            }
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, FilesystemIterator::SKIP_DOTS)) as $candidate) {
            if (!$candidate->isFile()) {
                continue;
            }

            $normalized = $this->normalizeAnkiMediaName($candidate->getBasename());
            if ($normalized !== '') {
                $index[$normalized] ??= $candidate->getPathname();
                $index[strtolower($normalized)] ??= $candidate->getPathname();
            }
        }

        return $index;
    }

    private function extractAnkiMediaData(string $html, array $mediaIndex, string $type): ?string
    {
        $fileNames = [];

        if ($type === 'image' && preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $match) {
                $fileNames[] = html_entity_decode($match, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        if ($type === 'audio' && preg_match_all('/\[sound:([^\]]+)\]/i', $html, $matches)) {
            foreach ($matches[1] as $match) {
                $fileNames[] = html_entity_decode($match, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        if ($type === 'audio' && preg_match_all('/<(?:audio|source)[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $match) {
                $fileNames[] = html_entity_decode($match, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        foreach ($fileNames as $fileName) {
            $normalized = $this->normalizeAnkiMediaName($fileName);
            $path = $mediaIndex[$normalized] ?? $mediaIndex[strtolower($normalized)] ?? null;

            if (!$path || !is_file($path)) {
                continue;
            }

            $mime = mime_content_type($path) ?: $this->guessMediaMime($fileName, $type);
            return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
        }

        return null;
    }

    private function normalizeAnkiMediaName(string $fileName): string
    {
        $fileName = html_entity_decode($fileName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $fileName = rawurldecode($fileName);
        $fileName = preg_replace('/[#?].*$/', '', $fileName) ?? $fileName;
        return basename(str_replace('\\', '/', trim($fileName)));
    }

    private function guessMediaMime(string $fileName, string $type): string
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $mimes = [
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'ogg' => 'audio/ogg',
            'oga' => 'audio/ogg',
            'wav' => 'audio/wav',
            'webm' => 'audio/webm',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
        ];

        return $mimes[$extension] ?? ($type === 'audio' ? 'audio/mpeg' : 'image/png');
    }

    private function todayStats(int $userId): void
    {
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $stmt = $this->db->prepare(
            'SELECT
                COUNT(*) AS total_sessions,
                COALESCE(SUM(total), 0) AS total_cards,
                COALESCE(SUM(duration_in_seconds), 0) AS total_seconds
             FROM study_sessions
             WHERE user_id = ? AND substr(created_at, 1, 10) = ?'
        );
        $stmt->execute([$userId, $today]);
        $row = $stmt->fetch();
        $totalCards = (int) ($row['total_cards'] ?? 0);
        $totalSeconds = (int) ($row['total_seconds'] ?? 0);
        $activityStmt = $this->db->prepare(
            'SELECT total_cards, total_seconds FROM study_daily_activity WHERE user_id = ? AND day = ?'
        );
        $activityStmt->execute([$userId, $today]);
        $activity = $activityStmt->fetch() ?: [];
        $totalCards = max($totalCards, (int) ($activity['total_cards'] ?? 0));
        $totalSeconds = max($totalSeconds, (int) ($activity['total_seconds'] ?? 0));

        $this->json([
            'stats' => [
                'totalSessions' => (int) ($row['total_sessions'] ?? 0),
                'totalCards' => $totalCards,
                'totalSeconds' => $totalSeconds,
                'totalMinutes' => round($totalSeconds / 60, 2),
                'secondsPerCard' => $totalCards > 0 ? round($totalSeconds / $totalCards, 2) : 0,
            ],
        ]);
    }

    private function dailyStats(int $userId): void
    {
        $stmt = $this->db->prepare(
            'SELECT substr(created_at, 1, 10) AS day,
                COALESCE(SUM(total), 0) AS total_cards,
                COALESCE(SUM(duration_in_seconds), 0) AS total_seconds
             FROM study_sessions
             WHERE user_id = ?
             GROUP BY substr(created_at, 1, 10)
             ORDER BY day DESC
             LIMIT 30'
        );
        $stmt->execute([$userId]);
        $days = [];

        foreach ($stmt->fetchAll() as $row) {
            $days[$row['day']] = [
                'day' => $row['day'],
                'totalCards' => (int) $row['total_cards'],
                'totalSeconds' => (int) $row['total_seconds'],
            ];
        }

        $activityStmt = $this->db->prepare(
            'SELECT day, total_cards, total_seconds
             FROM study_daily_activity
             WHERE user_id = ?
             ORDER BY day DESC
             LIMIT 30'
        );
        $activityStmt->execute([$userId]);

        foreach ($activityStmt->fetchAll() as $row) {
            $day = $row['day'];
            $existing = $days[$day] ?? ['day' => $day, 'totalCards' => 0, 'totalSeconds' => 0];
            $days[$day] = [
                'day' => $day,
                'totalCards' => max($existing['totalCards'], (int) $row['total_cards']),
                'totalSeconds' => max($existing['totalSeconds'], (int) $row['total_seconds']),
            ];
        }

        usort($days, fn ($a, $b) => strcmp($a['day'], $b['day']));
        $this->json(['days' => array_values($days)]);
    }

    private function sync(int $userId): void
    {
        $data = $this->input();
        $synced = [
            'sessions' => 0,
            'progress' => 0,
            'activity' => 0,
        ];

        $this->db->beginTransaction();
        try {
            foreach (($data['cardProgress'] ?? []) as $progress) {
                $cardId = (int) ($progress['cardId'] ?? 0);
                if ($cardId <= 0) {
                    continue;
                }

                $this->upsertCardProgress($userId, $cardId, $progress);
                $synced['progress']++;
            }

            foreach (($data['studyHistory'] ?? []) as $session) {
                if ($this->sessionAlreadySynced($userId, $session)) {
                    continue;
                }

                $this->insertStudySession($userId, $session);
                $synced['sessions']++;
            }

            if (!empty($data['todayActivity']) && is_array($data['todayActivity'])) {
                $this->upsertDailyActivity($userId, $data['todayActivity']);
                $synced['activity'] = 1;
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        $this->json([
            'ok' => true,
            'userId' => $userId,
            'syncedAt' => date('c'),
            'synced' => $synced,
            'message' => 'Dados sincronizados com o backend local.',
        ]);
    }

    private function studyHistory(int $userId): void
    {
        $stmt = $this->db->prepare('SELECT * FROM study_sessions WHERE user_id = ? ORDER BY id');
        $stmt->execute([$userId]);

        $this->json(['history' => array_map([$this, 'normalizeSession'], $stmt->fetchAll())]);
    }

    private function saveStudySession(int $userId): void
    {
        $data = $this->input();
        $sessionId = $this->insertStudySession($userId, $data);

        $this->json(['id' => $sessionId], 201);
    }

    private function insertStudySession(int $userId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO study_sessions
             (user_id, deck_id, deck_title, total, wrong, hard, easy, very_easy, started_at, finished_at, duration_in_seconds, average_seconds_per_card, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            (int) ($data['deckId'] ?? 0),
            (string) ($data['deckTitle'] ?? ''),
            (int) ($data['total'] ?? 0),
            (int) ($data['wrong'] ?? 0),
            (int) ($data['hard'] ?? 0),
            (int) ($data['easy'] ?? 0),
            (int) ($data['veryEasy'] ?? 0),
            $data['startedAt'] ?? null,
            $data['finishedAt'] ?? null,
            (int) ($data['durationInSeconds'] ?? 0),
            (int) ($data['averageSecondsPerCard'] ?? 0),
            (string) ($data['createdAt'] ?? date('c')),
        ]);

        $sessionId = (int) $this->db->lastInsertId();
        $answerStmt = $this->db->prepare(
            'INSERT INTO study_answers (session_id, card_id, question, answer_type, next_state, due_at) VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach (($data['answers'] ?? []) as $answer) {
            $answerStmt->execute([
                $sessionId,
                (int) ($answer['cardId'] ?? 0),
                (string) ($answer['question'] ?? ''),
                (string) ($answer['answerType'] ?? ''),
                $answer['nextState'] ?? null,
                $answer['dueAt'] ?? null,
            ]);
        }

        return $sessionId;
    }

    private function sessionAlreadySynced(int $userId, array $session): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM study_sessions
             WHERE user_id = ? AND deck_id = ? AND created_at = ? AND total = ? AND duration_in_seconds = ?'
        );
        $stmt->execute([
            $userId,
            (int) ($session['deckId'] ?? 0),
            (string) ($session['createdAt'] ?? ''),
            (int) ($session['total'] ?? 0),
            (int) ($session['durationInSeconds'] ?? 0),
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function upsertDailyActivity(int $userId, array $activity): void
    {
        $day = (string) ($activity['day'] ?? (new DateTimeImmutable('today'))->format('Y-m-d'));

        if (($this->config['database_driver'] ?? 'sqlite') === 'mysql') {
            $sql = 'INSERT INTO study_daily_activity (user_id, day, total_cards, total_seconds, updated_at)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        total_cards = GREATEST(total_cards, VALUES(total_cards)),
                        total_seconds = GREATEST(total_seconds, VALUES(total_seconds)),
                        updated_at = VALUES(updated_at)';
        } else {
            $sql = 'INSERT INTO study_daily_activity (user_id, day, total_cards, total_seconds, updated_at)
                    VALUES (?, ?, ?, ?, ?)
                    ON CONFLICT(user_id, day) DO UPDATE SET
                        total_cards = MAX(total_cards, excluded.total_cards),
                        total_seconds = MAX(total_seconds, excluded.total_seconds),
                        updated_at = excluded.updated_at';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $userId,
            substr($day, 0, 10),
            (int) ($activity['totalCards'] ?? 0),
            (int) ($activity['totalStudyTimeInSeconds'] ?? 0),
            date('c'),
        ]);
    }

    private function cardProgress(int $userId): void
    {
        $stmt = $this->db->prepare('SELECT * FROM card_progress WHERE user_id = ? ORDER BY card_id');
        $stmt->execute([$userId]);

        $progress = array_map([$this, 'normalizeCardProgress'], $stmt->fetchAll());
        $this->json(['progress' => $progress]);
    }

    private function saveCardProgress(int $userId, int $cardId): void
    {
        $data = $this->input();

        $this->upsertCardProgress($userId, $cardId, $data);
        $this->json(['ok' => true]);
    }

    private function upsertCardProgress(int $userId, int $cardId, array $data): void
    {
        if (($this->config['database_driver'] ?? 'sqlite') === 'mysql') {
            $sql = 'INSERT INTO card_progress (user_id, card_id, state, due_at, ease_factor, interval_days, repetitions, lapses, introduced_at, last_rating, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        state = VALUES(state),
                        due_at = VALUES(due_at),
                        ease_factor = VALUES(ease_factor),
                        interval_days = VALUES(interval_days),
                        repetitions = VALUES(repetitions),
                        lapses = VALUES(lapses),
                        introduced_at = VALUES(introduced_at),
                        last_rating = VALUES(last_rating),
                        updated_at = VALUES(updated_at)';
        } else {
            $sql = 'INSERT INTO card_progress (user_id, card_id, state, due_at, ease_factor, interval_days, repetitions, lapses, introduced_at, last_rating, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON CONFLICT(user_id, card_id) DO UPDATE SET
                        state = excluded.state,
                        due_at = excluded.due_at,
                        ease_factor = excluded.ease_factor,
                        interval_days = excluded.interval_days,
                        repetitions = excluded.repetitions,
                        lapses = excluded.lapses,
                        introduced_at = excluded.introduced_at,
                        last_rating = excluded.last_rating,
                        updated_at = excluded.updated_at';
        }

        $stmt = $this->db->prepare($sql);

        $stmt->execute([
            $userId,
            $cardId,
            (string) ($data['state'] ?? 'new'),
            $data['dueAt'] ?? null,
            $data['easeFactor'] ?? null,
            $data['intervalDays'] ?? null,
            $data['repetitions'] ?? null,
            $data['lapses'] ?? null,
            $data['introducedAt'] ?? null,
            $data['lastRating'] ?? null,
            date('c'),
        ]);
    }

    private function input(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
    }

    private function normalizeDeck(array $deck): array
    {
        return [
            'id' => (int) $deck['id'],
            'title' => $deck['title'],
            'description' => $deck['description'],
            'category' => $deck['category'],
            'totalCards' => (int) $deck['totalCards'],
        ];
    }

    private function normalizeCard(array $card, bool $includeMedia = true): array
    {
        $normalized = [
            'id' => (int) $card['id'],
            'deckId' => (int) $card['deck_id'],
            'question' => $card['question'],
            'answer' => $card['answer'],
            'questionHtml' => $card['question_html'] ?? null,
            'answerHtml' => $card['answer_html'] ?? null,
            'cardType' => $card['card_type'] ?? 'basic',
            'occlusionMasks' => ($card['occlusion_masks'] ?? null) ? json_decode($card['occlusion_masks'], true) : [],
        ];

        if ($includeMedia) {
            $normalized['imageData'] = $card['image_data'] ?? null;
            $normalized['audioData'] = $card['audio_data'] ?? null;
        }

        return $normalized;
    }

    private function normalizeSession(array $session): array
    {
        return [
            'id' => (int) $session['id'],
            'deckId' => (int) $session['deck_id'],
            'deckTitle' => $session['deck_title'],
            'total' => (int) $session['total'],
            'wrong' => (int) $session['wrong'],
            'hard' => (int) $session['hard'],
            'easy' => (int) $session['easy'],
            'veryEasy' => (int) $session['very_easy'],
            'startedAt' => $session['started_at'],
            'finishedAt' => $session['finished_at'],
            'durationInSeconds' => (int) $session['duration_in_seconds'],
            'averageSecondsPerCard' => (int) $session['average_seconds_per_card'],
            'createdAt' => $session['created_at'],
        ];
    }

    private function normalizeCardProgress(array $progress): array
    {
        return [
            'userId' => (int) $progress['user_id'],
            'cardId' => (int) $progress['card_id'],
            'state' => $progress['state'],
            'dueAt' => $progress['due_at'],
            'easeFactor' => $progress['ease_factor'] === null ? null : (float) $progress['ease_factor'],
            'intervalDays' => $progress['interval_days'] === null ? null : (int) $progress['interval_days'],
            'repetitions' => $progress['repetitions'] === null ? null : (int) $progress['repetitions'],
            'lapses' => $progress['lapses'] === null ? null : (int) $progress['lapses'],
            'introducedAt' => $progress['introduced_at'] ?? null,
            'lastRating' => $progress['last_rating'] ?? null,
        ];
    }

    private function cors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    }

    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}
