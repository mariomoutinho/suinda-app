<?php

declare(strict_types=1);

final class App
{
    private PDO $db;

    /** Usuario autenticado da requisicao atual (preenchido apos requireUser). */
    private ?array $currentUser = null;

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
        $path = $this->stripBasePath($path);
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
            $this->currentUser = $user;

            if ($method === 'GET' && $path === '/me') {
                $this->json(['user' => $user]);
                return;
            }

            if ($method === 'GET' && $path === '/me/dashboard') {
                $this->dashboard();
                return;
            }

            if ($method === 'GET' && $path === '/me/courses') {
                $this->json(['courses' => $this->enrolledCourses()]);
                return;
            }

            if ($method === 'GET' && $path === '/me/paths') {
                $this->json(['paths' => $this->releasedPaths()]);
                return;
            }

            // ---- Área administrativa (mini-CMS) — somente admin ----
            if (str_starts_with($path, '/admin/')) {
                $this->requireAdmin((string) $user['role']);

                if ($method === 'GET' && $path === '/admin/overview') { $this->adminOverview(); return; }
                if ($method === 'POST' && $path === '/admin/users') { $this->adminCreateUser(); return; }
                if ($method === 'POST' && $path === '/admin/areas') { $this->adminCreateArea(); return; }
                if ($method === 'POST' && $path === '/admin/courses') { $this->adminCreateCourse(); return; }
                if ($method === 'POST' && $path === '/admin/paths') { $this->adminCreatePath(); return; }
                if ($method === 'POST' && $path === '/admin/modules') { $this->adminCreateModule(); return; }
                if ($method === 'POST' && $path === '/admin/path-courses') { $this->adminLinkPathCourse(); return; }
                if ($method === 'POST' && $path === '/admin/course-decks') { $this->adminLinkCourseDeck(); return; }
                if ($method === 'PUT' && preg_match('#^/admin/courses/(\d+)$#', $path, $m)) { $this->adminUpdateCourse((int) $m[1]); return; }
                if ($method === 'POST' && $path === '/admin/enrollments/bulk') { $this->adminBulkEnroll(); return; }
                if ($method === 'POST' && $path === '/admin/enrollments') { $this->adminEnroll(); return; }
                if ($method === 'DELETE' && preg_match('#^/admin/enrollments/(\d+)$#', $path, $m)) { $this->adminDelete('enrollments', (int) $m[1]); return; }
                if ($method === 'DELETE' && preg_match('#^/admin/course-decks/(\d+)$#', $path, $m)) { $this->adminDelete('course_decks', (int) $m[1]); return; }

                $this->json(['error' => 'Rota administrativa nao encontrada.'], 404);
                return;
            }

            // ---- Curso ENEM (banco de questões) — exige matrícula no curso ----
            if (str_starts_with($path, '/enem/')) {
                if ($method === 'GET' && $path === '/enem/overview') { $this->enemOverview(); return; }
                if ($method === 'GET' && $path === '/enem/taxonomy') { $this->enemTaxonomy(); return; }
                if ($method === 'GET' && $path === '/enem/questions') { $this->enemQuestions(); return; }
                if ($method === 'GET' && preg_match('#^/enem/questions/(\d+)$#', $path, $m)) { $this->enemShowQuestion((int) $m[1]); return; }
                if ($method === 'POST' && preg_match('#^/enem/questions/(\d+)/answer$#', $path, $m)) { $this->enemAnswer((int) $m[1]); return; }

                $this->json(['error' => 'Rota ENEM nao encontrada.'], 404);
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

            if ($method === 'GET' && preg_match('#^/cards/(\d+)$#', $path, $match)) {
                $this->showCard((int) $match[1]);
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

        // Camada educacional (Suinda): areas, trilhas, cursos, matriculas e
        // vinculo entre cursos e baralhos. Criada de forma aditiva — nao
        // altera as tabelas originais do app de repeticao espacada.
        $this->db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS knowledge_areas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    description TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS learning_paths (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    area_id INTEGER,
    title TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    description TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (area_id) REFERENCES knowledge_areas(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS courses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    area_id INTEGER,
    title TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    description TEXT,
    level TEXT NOT NULL DEFAULT 'introdutorio',
    status TEXT NOT NULL DEFAULT 'available',
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (area_id) REFERENCES knowledge_areas(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS learning_path_courses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    path_id INTEGER NOT NULL,
    course_id INTEGER NOT NULL,
    position INTEGER NOT NULL DEFAULT 0,
    UNIQUE (path_id, course_id),
    FOREIGN KEY (path_id) REFERENCES learning_paths(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS course_modules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    course_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    description TEXT,
    position INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS enrollments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    course_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    enrolled_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, course_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS course_decks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    course_id INTEGER NOT NULL,
    deck_id INTEGER NOT NULL,
    module_id INTEGER,
    position INTEGER NOT NULL DEFAULT 0,
    UNIQUE (course_id, deck_id),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (deck_id) REFERENCES decks(id) ON DELETE CASCADE,
    FOREIGN KEY (module_id) REFERENCES course_modules(id) ON DELETE SET NULL
);
SQL);

        // owner_id permite que cada estudante continue criando baralhos
        // pessoais (que so ele ve), enquanto os baralhos institucionais
        // (owner_id NULL) ficam restritos as matriculas.
        $this->addColumnIfMissing('decks', 'owner_id', 'INTEGER');

        $this->migrateEnem();
    }

    private function migrateMysql(): void
    {
        // O arquivo .sql NAO e publicado no deploy (regra **/*.sql do FTP).
        // Quando presente (dev local) criamos as tabelas-base a partir dele;
        // quando ausente (servidor), assume-se que foram importadas via
        // phpMyAdmin — as tabelas educacionais abaixo sao sempre garantidas.
        $schemaPath = __DIR__ . '/../database/schema.mysql.sql';
        if (is_file($schemaPath)) {
            $schema = (string) file_get_contents($schemaPath);
            foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
                $this->db->exec($statement);
            }
        }

        $this->addColumnIfMissing('cards', 'card_type', "VARCHAR(40) NOT NULL DEFAULT 'basic'");
        $this->addColumnIfMissing('cards', 'question_html', 'LONGTEXT');
        $this->addColumnIfMissing('cards', 'answer_html', 'LONGTEXT');
        $this->addColumnIfMissing('cards', 'image_data', 'LONGTEXT');
        $this->addColumnIfMissing('cards', 'audio_data', 'LONGTEXT');
        $this->addColumnIfMissing('cards', 'occlusion_masks', 'LONGTEXT');
        $this->addColumnIfMissing('card_progress', 'introduced_at', 'VARCHAR(40)');
        $this->addColumnIfMissing('card_progress', 'last_rating', 'VARCHAR(30)');

        // Camada educacional (Suinda) — equivalente MySQL/MariaDB das tabelas
        // criadas no driver SQLite. Aditiva e idempotente.
        foreach ($this->suindaMysqlSchema() as $statement) {
            $this->db->exec($statement);
        }

        $this->addColumnIfMissing('decks', 'owner_id', 'INT NULL');

        $this->migrateEnem();
    }

    /**
     * Camada ENEM (banco de questoes e taxonomia da Matriz de Referencia).
     * Aditiva e idempotente; nao toca nas tabelas existentes.
     */
    private function migrateEnem(): void
    {
        if (($this->config['database_driver'] ?? 'sqlite') === 'mysql') {
            foreach ($this->enemMysqlSchema() as $statement) {
                $this->db->exec($statement);
            }
            return;
        }

        $this->db->exec($this->enemSqliteSchema());
    }

    private function enemSqliteSchema(): string
    {
        return <<<SQL
CREATE TABLE IF NOT EXISTS cognitive_axes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT
);

CREATE TABLE IF NOT EXISTS disciplines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    area_id INTEGER,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (area_id) REFERENCES knowledge_areas(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS contents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    area_id INTEGER,
    discipline_id INTEGER,
    parent_id INTEGER,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (area_id) REFERENCES knowledge_areas(id) ON DELETE SET NULL,
    FOREIGN KEY (discipline_id) REFERENCES disciplines(id) ON DELETE SET NULL,
    FOREIGN KEY (parent_id) REFERENCES contents(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS competencies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    area_id INTEGER NOT NULL,
    code TEXT NOT NULL UNIQUE,
    number INTEGER NOT NULL,
    statement TEXT NOT NULL,
    FOREIGN KEY (area_id) REFERENCES knowledge_areas(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS skills (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    competency_id INTEGER NOT NULL,
    code TEXT NOT NULL UNIQUE,
    number INTEGER NOT NULL,
    statement TEXT NOT NULL,
    FOREIGN KEY (competency_id) REFERENCES competencies(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS exams (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    year INTEGER NOT NULL,
    day INTEGER,
    booklet TEXT,
    color TEXT,
    source_label TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS exam_questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    exam_id INTEGER NOT NULL,
    course_id INTEGER,
    card_id INTEGER,
    area_id INTEGER,
    discipline_id INTEGER,
    content_id INTEGER,
    competency_id INTEGER,
    skill_id INTEGER,
    cognitive_axis_id INTEGER,
    number INTEGER NOT NULL,
    correct_alternative TEXT,
    status TEXT NOT NULL DEFAULT 'pendente_revisao',
    statement_text TEXT,
    explanation TEXT,
    explanation_status TEXT NOT NULL DEFAULT 'pendente',
    confidence TEXT NOT NULL DEFAULT 'baixa',
    review_needed INTEGER NOT NULL DEFAULT 1,
    pdf_page INTEGER,
    imported_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    UNIQUE (exam_id, number),
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
    FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE SET NULL,
    FOREIGN KEY (area_id) REFERENCES knowledge_areas(id) ON DELETE SET NULL,
    FOREIGN KEY (discipline_id) REFERENCES disciplines(id) ON DELETE SET NULL,
    FOREIGN KEY (content_id) REFERENCES contents(id) ON DELETE SET NULL,
    FOREIGN KEY (competency_id) REFERENCES competencies(id) ON DELETE SET NULL,
    FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE SET NULL,
    FOREIGN KEY (cognitive_axis_id) REFERENCES cognitive_axes(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS question_images (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    question_id INTEGER NOT NULL,
    position INTEGER NOT NULL DEFAULT 0,
    path TEXT NOT NULL,
    kind TEXT NOT NULL DEFAULT 'crop',
    pdf_page INTEGER,
    width INTEGER,
    height INTEGER,
    FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS question_alternatives (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    question_id INTEGER NOT NULL,
    letter TEXT NOT NULL,
    body TEXT,
    is_correct INTEGER NOT NULL DEFAULT 0,
    UNIQUE (question_id, letter),
    FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS question_contents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    question_id INTEGER NOT NULL,
    content_id INTEGER NOT NULL,
    role TEXT NOT NULL DEFAULT 'secundario',
    UNIQUE (question_id, content_id),
    FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE,
    FOREIGN KEY (content_id) REFERENCES contents(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS question_competencies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    question_id INTEGER NOT NULL,
    competency_id INTEGER NOT NULL,
    role TEXT NOT NULL DEFAULT 'secundario',
    UNIQUE (question_id, competency_id),
    FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE,
    FOREIGN KEY (competency_id) REFERENCES competencies(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS question_skills (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    question_id INTEGER NOT NULL,
    skill_id INTEGER NOT NULL,
    role TEXT NOT NULL DEFAULT 'secundario',
    UNIQUE (question_id, skill_id),
    FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS question_cognitive_axes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    question_id INTEGER NOT NULL,
    cognitive_axis_id INTEGER NOT NULL,
    UNIQUE (question_id, cognitive_axis_id),
    FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE,
    FOREIGN KEY (cognitive_axis_id) REFERENCES cognitive_axes(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS question_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    question_id INTEGER NOT NULL,
    selected_alternative TEXT,
    is_correct INTEGER,
    answered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    time_spent_seconds INTEGER,
    attempt_number INTEGER NOT NULL DEFAULT 1,
    session_origin TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_eq_exam ON exam_questions(exam_id);
CREATE INDEX IF NOT EXISTS idx_eq_discipline ON exam_questions(discipline_id);
CREATE INDEX IF NOT EXISTS idx_eq_card ON exam_questions(card_id);
CREATE INDEX IF NOT EXISTS idx_qa_user ON question_attempts(user_id);
CREATE INDEX IF NOT EXISTS idx_qa_question ON question_attempts(question_id);
SQL;
    }

    /** @return string[] DDL idempotente das tabelas ENEM para MySQL. */
    private function enemMysqlSchema(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS cognitive_axes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(10) NOT NULL UNIQUE,
                name VARCHAR(120) NOT NULL,
                description TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS disciplines (
                id INT AUTO_INCREMENT PRIMARY KEY,
                area_id INT NULL,
                name VARCHAR(120) NOT NULL,
                slug VARCHAR(140) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_disc_area FOREIGN KEY (area_id) REFERENCES knowledge_areas(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS contents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                area_id INT NULL,
                discipline_id INT NULL,
                parent_id INT NULL,
                name VARCHAR(240) NOT NULL,
                slug VARCHAR(260) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_cont_area FOREIGN KEY (area_id) REFERENCES knowledge_areas(id) ON DELETE SET NULL,
                CONSTRAINT fk_cont_disc FOREIGN KEY (discipline_id) REFERENCES disciplines(id) ON DELETE SET NULL,
                CONSTRAINT fk_cont_parent FOREIGN KEY (parent_id) REFERENCES contents(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS competencies (
                id INT AUTO_INCREMENT PRIMARY KEY,
                area_id INT NOT NULL,
                code VARCHAR(20) NOT NULL UNIQUE,
                number INT NOT NULL,
                statement TEXT NOT NULL,
                CONSTRAINT fk_comp_area FOREIGN KEY (area_id) REFERENCES knowledge_areas(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS skills (
                id INT AUTO_INCREMENT PRIMARY KEY,
                competency_id INT NOT NULL,
                code VARCHAR(20) NOT NULL UNIQUE,
                number INT NOT NULL,
                statement TEXT NOT NULL,
                CONSTRAINT fk_skill_comp FOREIGN KEY (competency_id) REFERENCES competencies(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS exams (
                id INT AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(160) NOT NULL UNIQUE,
                name VARCHAR(200) NOT NULL,
                year INT NOT NULL,
                day INT NULL,
                booklet VARCHAR(20) NULL,
                color VARCHAR(30) NULL,
                source_label VARCHAR(200) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS exam_questions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                exam_id INT NOT NULL,
                course_id INT NULL,
                card_id INT NULL,
                area_id INT NULL,
                discipline_id INT NULL,
                content_id INT NULL,
                competency_id INT NULL,
                skill_id INT NULL,
                cognitive_axis_id INT NULL,
                number INT NOT NULL,
                correct_alternative VARCHAR(2) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pendente_revisao',
                statement_text MEDIUMTEXT,
                explanation MEDIUMTEXT,
                explanation_status VARCHAR(30) NOT NULL DEFAULT 'pendente',
                confidence VARCHAR(10) NOT NULL DEFAULT 'baixa',
                review_needed TINYINT(1) NOT NULL DEFAULT 1,
                pdf_page INT NULL,
                imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                notes TEXT,
                UNIQUE KEY unique_exam_number (exam_id, number),
                KEY idx_eq_discipline (discipline_id),
                KEY idx_eq_card (card_id),
                CONSTRAINT fk_eq_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
                CONSTRAINT fk_eq_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
                CONSTRAINT fk_eq_card FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE SET NULL,
                CONSTRAINT fk_eq_area FOREIGN KEY (area_id) REFERENCES knowledge_areas(id) ON DELETE SET NULL,
                CONSTRAINT fk_eq_disc FOREIGN KEY (discipline_id) REFERENCES disciplines(id) ON DELETE SET NULL,
                CONSTRAINT fk_eq_content FOREIGN KEY (content_id) REFERENCES contents(id) ON DELETE SET NULL,
                CONSTRAINT fk_eq_comp FOREIGN KEY (competency_id) REFERENCES competencies(id) ON DELETE SET NULL,
                CONSTRAINT fk_eq_skill FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE SET NULL,
                CONSTRAINT fk_eq_axis FOREIGN KEY (cognitive_axis_id) REFERENCES cognitive_axes(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS question_images (
                id INT AUTO_INCREMENT PRIMARY KEY,
                question_id INT NOT NULL,
                position INT NOT NULL DEFAULT 0,
                path VARCHAR(400) NOT NULL,
                kind VARCHAR(20) NOT NULL DEFAULT 'crop',
                pdf_page INT NULL,
                width INT NULL,
                height INT NULL,
                CONSTRAINT fk_qi_question FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS question_alternatives (
                id INT AUTO_INCREMENT PRIMARY KEY,
                question_id INT NOT NULL,
                letter VARCHAR(2) NOT NULL,
                body TEXT,
                is_correct TINYINT(1) NOT NULL DEFAULT 0,
                UNIQUE KEY unique_question_letter (question_id, letter),
                CONSTRAINT fk_qalt_question FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS question_contents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                question_id INT NOT NULL,
                content_id INT NOT NULL,
                role VARCHAR(20) NOT NULL DEFAULT 'secundario',
                UNIQUE KEY unique_q_content (question_id, content_id),
                CONSTRAINT fk_qc_question FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE,
                CONSTRAINT fk_qc_content FOREIGN KEY (content_id) REFERENCES contents(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS question_competencies (
                id INT AUTO_INCREMENT PRIMARY KEY,
                question_id INT NOT NULL,
                competency_id INT NOT NULL,
                role VARCHAR(20) NOT NULL DEFAULT 'secundario',
                UNIQUE KEY unique_q_comp (question_id, competency_id),
                CONSTRAINT fk_qcomp_question FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE,
                CONSTRAINT fk_qcomp_comp FOREIGN KEY (competency_id) REFERENCES competencies(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS question_skills (
                id INT AUTO_INCREMENT PRIMARY KEY,
                question_id INT NOT NULL,
                skill_id INT NOT NULL,
                role VARCHAR(20) NOT NULL DEFAULT 'secundario',
                UNIQUE KEY unique_q_skill (question_id, skill_id),
                CONSTRAINT fk_qskill_question FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE,
                CONSTRAINT fk_qskill_skill FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS question_cognitive_axes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                question_id INT NOT NULL,
                cognitive_axis_id INT NOT NULL,
                UNIQUE KEY unique_q_axis (question_id, cognitive_axis_id),
                CONSTRAINT fk_qax_question FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE,
                CONSTRAINT fk_qax_axis FOREIGN KEY (cognitive_axis_id) REFERENCES cognitive_axes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS question_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                question_id INT NOT NULL,
                selected_alternative VARCHAR(2) NULL,
                is_correct TINYINT(1) NULL,
                answered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                time_spent_seconds INT NULL,
                attempt_number INT NOT NULL DEFAULT 1,
                session_origin VARCHAR(40) NULL,
                KEY idx_qa_user (user_id),
                KEY idx_qa_question (question_id),
                CONSTRAINT fk_qatt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_qatt_question FOREIGN KEY (question_id) REFERENCES exam_questions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
    }

    /** @return string[] DDL idempotente das tabelas educacionais para MySQL. */
    private function suindaMysqlSchema(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS knowledge_areas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(160) NOT NULL,
                slug VARCHAR(160) NOT NULL UNIQUE,
                description TEXT,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS learning_paths (
                id INT AUTO_INCREMENT PRIMARY KEY,
                area_id INT NULL,
                title VARCHAR(180) NOT NULL,
                slug VARCHAR(180) NOT NULL UNIQUE,
                description TEXT,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_paths_area FOREIGN KEY (area_id) REFERENCES knowledge_areas(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS courses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                area_id INT NULL,
                title VARCHAR(180) NOT NULL,
                slug VARCHAR(180) NOT NULL UNIQUE,
                description TEXT,
                level VARCHAR(40) NOT NULL DEFAULT 'introdutorio',
                status VARCHAR(30) NOT NULL DEFAULT 'available',
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_courses_area FOREIGN KEY (area_id) REFERENCES knowledge_areas(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS learning_path_courses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                path_id INT NOT NULL,
                course_id INT NOT NULL,
                position INT NOT NULL DEFAULT 0,
                UNIQUE KEY unique_path_course (path_id, course_id),
                CONSTRAINT fk_lpc_path FOREIGN KEY (path_id) REFERENCES learning_paths(id) ON DELETE CASCADE,
                CONSTRAINT fk_lpc_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS course_modules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                course_id INT NOT NULL,
                title VARCHAR(180) NOT NULL,
                description TEXT,
                position INT NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_modules_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS enrollments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                course_id INT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                enrolled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_course (user_id, course_id),
                CONSTRAINT fk_enroll_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_enroll_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS course_decks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                course_id INT NOT NULL,
                deck_id INT NOT NULL,
                module_id INT NULL,
                position INT NOT NULL DEFAULT 0,
                UNIQUE KEY unique_course_deck (course_id, deck_id),
                CONSTRAINT fk_cd_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
                CONSTRAINT fk_cd_deck FOREIGN KEY (deck_id) REFERENCES decks(id) ON DELETE CASCADE,
                CONSTRAINT fk_cd_module FOREIGN KEY (module_id) REFERENCES course_modules(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
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

        $deckCount = (int) $this->db->query('SELECT COUNT(*) FROM decks')->fetchColumn();
        if ($deckCount > 0) {
            $this->seedEducation();
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

        $this->seedEducation();
    }

    /**
     * Semeia a camada educacional de demonstracao (area, trilha, curso, modulo,
     * vinculo curso<->baralho e matricula do aluno de teste). Idempotente:
     * usa slug/colunas unicas, entao pode rodar varias vezes sem duplicar.
     */
    private function seedEducation(): void
    {
        $areaId = $this->ensureBySlug('knowledge_areas', 'fundamentos', function () {
            $stmt = $this->db->prepare('INSERT INTO knowledge_areas (name, slug, description) VALUES (?, ?, ?)');
            $stmt->execute(['Fundamentos', 'fundamentos', 'Conhecimentos de base para comecar a estudar com o Suinda.']);
            return (int) $this->db->lastInsertId();
        });

        $courseId = $this->ensureBySlug('courses', 'biologia-basica', function () use ($areaId) {
            $stmt = $this->db->prepare('INSERT INTO courses (area_id, title, slug, description, level, status) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $areaId,
                'Biologia Basica',
                'biologia-basica',
                'Conceitos introdutorios de biologia para revisar com repeticao espacada.',
                'introdutorio',
                'available',
            ]);
            return (int) $this->db->lastInsertId();
        });

        // Curso "em breve" (sem matricula) apenas para ilustrar a vitrine.
        $this->ensureBySlug('courses', 'historia-do-brasil', function () use ($areaId) {
            $stmt = $this->db->prepare('INSERT INTO courses (area_id, title, slug, description, level, status) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $areaId,
                'Historia do Brasil',
                'historia-do-brasil',
                'Marcos da historia do Brasil. Conteudo em preparacao.',
                'introdutorio',
                'coming_soon',
            ]);
            return (int) $this->db->lastInsertId();
        });

        $pathId = $this->ensureBySlug('learning_paths', 'trilha-de-fundamentos', function () use ($areaId) {
            $stmt = $this->db->prepare('INSERT INTO learning_paths (area_id, title, slug, description) VALUES (?, ?, ?, ?)');
            $stmt->execute([
                $areaId,
                'Trilha de Fundamentos',
                'trilha-de-fundamentos',
                'Um caminho sugerido para quem esta comecando os estudos.',
            ]);
            return (int) $this->db->lastInsertId();
        });

        $this->ensureLink(
            'SELECT id FROM learning_path_courses WHERE path_id = ? AND course_id = ?',
            [$pathId, $courseId],
            'INSERT INTO learning_path_courses (path_id, course_id, position) VALUES (?, ?, ?)',
            [$pathId, $courseId, 1]
        );

        $moduleId = $this->ensureModule($courseId, 'Modulo 1 — Primeiros conceitos');

        // Vincula o curso ao baralho de Biologia ja semeado pelo app.
        $deckId = $this->findDeckIdLike('%Biologia%');
        if ($deckId !== null) {
            $this->ensureLink(
                'SELECT id FROM course_decks WHERE course_id = ? AND deck_id = ?',
                [$courseId, $deckId],
                'INSERT INTO course_decks (course_id, deck_id, module_id, position) VALUES (?, ?, ?, ?)',
                [$courseId, $deckId, $moduleId, 1]
            );
        }

        // Matricula o aluno de demonstracao no curso disponivel.
        $studentId = $this->findUserIdByEmail('aluno@suinda.com');
        if ($studentId !== null) {
            $this->ensureLink(
                'SELECT id FROM enrollments WHERE user_id = ? AND course_id = ?',
                [$studentId, $courseId],
                'INSERT INTO enrollments (user_id, course_id, status) VALUES (?, ?, ?)',
                [$studentId, $courseId, 'active']
            );
        }
    }

    private function ensureBySlug(string $table, string $slug, callable $insert): int
    {
        $stmt = $this->db->prepare("SELECT id FROM {$table} WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : (int) $insert();
    }

    private function ensureModule(int $courseId, string $title): int
    {
        $stmt = $this->db->prepare('SELECT id FROM course_modules WHERE course_id = ? AND title = ? LIMIT 1');
        $stmt->execute([$courseId, $title]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $stmt = $this->db->prepare('INSERT INTO course_modules (course_id, title, description, position) VALUES (?, ?, ?, ?)');
        $stmt->execute([$courseId, $title, 'Conteudos iniciais do curso.', 1]);
        return (int) $this->db->lastInsertId();
    }

    private function ensureLink(string $selectSql, array $selectParams, string $insertSql, array $insertParams): void
    {
        $stmt = $this->db->prepare($selectSql);
        $stmt->execute($selectParams);
        if ($stmt->fetchColumn() !== false) {
            return;
        }

        $this->db->prepare($insertSql)->execute($insertParams);
    }

    private function findDeckIdLike(string $like): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM decks WHERE title LIKE ? AND active = 1 ORDER BY id LIMIT 1');
        $stmt->execute([$like]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function findUserIdByEmail(string $email): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    // ===================== Área administrativa (mini-CMS) =====================

    /** Estado completo para popular a tela de administração. */
    private function adminOverview(): void
    {
        $areas = $this->rowsInt(
            $this->db->query('SELECT id, name, slug, description, active FROM knowledge_areas ORDER BY name')->fetchAll(),
            ['id', 'active']
        );
        $courses = $this->rowsInt(
            $this->db->query('SELECT id, area_id, title, slug, description, level, status, active FROM courses ORDER BY title')->fetchAll(),
            ['id', 'area_id', 'active']
        );
        $paths = $this->rowsInt(
            $this->db->query('SELECT id, area_id, title, slug, description, active FROM learning_paths ORDER BY title')->fetchAll(),
            ['id', 'area_id', 'active']
        );
        $modules = $this->rowsInt(
            $this->db->query('SELECT id, course_id, title, position FROM course_modules ORDER BY course_id, position')->fetchAll(),
            ['id', 'course_id', 'position']
        );
        $decks = $this->rowsInt(
            $this->db->query(
                'SELECT decks.id, decks.title, decks.category, COUNT(cards.id) AS totalCards
                 FROM decks LEFT JOIN cards ON cards.deck_id = decks.id AND cards.active = 1
                 WHERE decks.active = 1 GROUP BY decks.id ORDER BY decks.title'
            )->fetchAll(),
            ['id', 'totalCards']
        );
        $users = $this->rowsInt(
            $this->db->query('SELECT id, name, email, role, active FROM users ORDER BY name')->fetchAll(),
            ['id', 'active']
        );
        $enrollments = $this->rowsInt(
            $this->db->query(
                'SELECT enrollments.id, enrollments.user_id, enrollments.course_id, enrollments.status,
                        users.name AS user_name, users.email, courses.title AS course_title
                 FROM enrollments
                 INNER JOIN users ON users.id = enrollments.user_id
                 INNER JOIN courses ON courses.id = enrollments.course_id
                 ORDER BY enrollments.id DESC'
            )->fetchAll(),
            ['id', 'user_id', 'course_id']
        );
        $courseDecks = $this->rowsInt(
            $this->db->query(
                'SELECT course_decks.id, course_decks.course_id, course_decks.deck_id, course_decks.module_id,
                        courses.title AS course_title, decks.title AS deck_title
                 FROM course_decks
                 INNER JOIN courses ON courses.id = course_decks.course_id
                 INNER JOIN decks ON decks.id = course_decks.deck_id
                 ORDER BY course_decks.course_id'
            )->fetchAll(),
            ['id', 'course_id', 'deck_id', 'module_id']
        );
        $pathCourses = $this->rowsInt(
            $this->db->query(
                'SELECT learning_path_courses.id, learning_path_courses.path_id, learning_path_courses.course_id,
                        learning_path_courses.position, learning_paths.title AS path_title, courses.title AS course_title
                 FROM learning_path_courses
                 INNER JOIN learning_paths ON learning_paths.id = learning_path_courses.path_id
                 INNER JOIN courses ON courses.id = learning_path_courses.course_id
                 ORDER BY learning_path_courses.path_id, learning_path_courses.position'
            )->fetchAll(),
            ['id', 'path_id', 'course_id', 'position']
        );

        $this->json(compact(
            'areas', 'courses', 'paths', 'modules', 'decks', 'users', 'enrollments', 'courseDecks', 'pathCourses'
        ));
    }

    private function adminCreateUser(): void
    {
        $d = $this->input();
        $name = trim((string) ($d['name'] ?? ''));
        $email = strtolower(trim((string) ($d['email'] ?? '')));
        $password = (string) ($d['password'] ?? '');
        $role = ($d['role'] ?? 'student') === 'admin' ? 'admin' : 'student';

        if ($name === '' || $email === '' || $password === '') {
            $this->json(['error' => 'Informe nome, e-mail e senha.'], 422);
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->json(['error' => 'E-mail invalido.'], 422);
            return;
        }
        if (strlen($password) < 6) {
            $this->json(['error' => 'A senha deve ter ao menos 6 caracteres.'], 422);
            return;
        }
        if ($this->findUserIdByEmail($email) !== null) {
            $this->json(['error' => 'Ja existe um usuario com este e-mail.'], 409);
            return;
        }

        $stmt = $this->db->prepare('INSERT INTO users (name, email, password_hash, role, active) VALUES (?, ?, ?, ?, 1)');
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
        $this->json(['id' => (int) $this->db->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => $role], 201);
    }

    private function adminCreateArea(): void
    {
        $d = $this->input();
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            $this->json(['error' => 'Informe o nome da area.'], 422);
            return;
        }
        $slug = $this->uniqueSlug('knowledge_areas', $this->slugify($name));
        $stmt = $this->db->prepare('INSERT INTO knowledge_areas (name, slug, description) VALUES (?, ?, ?)');
        $stmt->execute([$name, $slug, trim((string) ($d['description'] ?? '')) ?: null]);
        $this->json(['id' => (int) $this->db->lastInsertId(), 'slug' => $slug], 201);
    }

    private function adminCreateCourse(): void
    {
        $d = $this->input();
        $title = trim((string) ($d['title'] ?? ''));
        if ($title === '') {
            $this->json(['error' => 'Informe o titulo do curso.'], 422);
            return;
        }
        $areaId = $this->nullableFk('knowledge_areas', $d['areaId'] ?? null);
        $status = in_array(($d['status'] ?? ''), ['available', 'coming_soon'], true) ? $d['status'] : 'available';
        $level = trim((string) ($d['level'] ?? 'introdutorio')) ?: 'introdutorio';
        $slug = $this->uniqueSlug('courses', $this->slugify($title));

        $stmt = $this->db->prepare('INSERT INTO courses (area_id, title, slug, description, level, status) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$areaId, $title, $slug, trim((string) ($d['description'] ?? '')) ?: null, $level, $status]);
        $this->json(['id' => (int) $this->db->lastInsertId(), 'slug' => $slug], 201);
    }

    private function adminCreatePath(): void
    {
        $d = $this->input();
        $title = trim((string) ($d['title'] ?? ''));
        if ($title === '') {
            $this->json(['error' => 'Informe o titulo da trilha.'], 422);
            return;
        }
        $areaId = $this->nullableFk('knowledge_areas', $d['areaId'] ?? null);
        $slug = $this->uniqueSlug('learning_paths', $this->slugify($title));
        $stmt = $this->db->prepare('INSERT INTO learning_paths (area_id, title, slug, description) VALUES (?, ?, ?, ?)');
        $stmt->execute([$areaId, $title, $slug, trim((string) ($d['description'] ?? '')) ?: null]);
        $this->json(['id' => (int) $this->db->lastInsertId(), 'slug' => $slug], 201);
    }

    private function adminCreateModule(): void
    {
        $d = $this->input();
        $courseId = (int) ($d['courseId'] ?? 0);
        $title = trim((string) ($d['title'] ?? ''));
        if ($courseId <= 0 || $title === '') {
            $this->json(['error' => 'Informe o curso e o titulo do modulo.'], 422);
            return;
        }
        if (!$this->rowExists('courses', $courseId)) {
            $this->json(['error' => 'Curso inexistente.'], 422);
            return;
        }
        $position = (int) ($d['position'] ?? 0);
        $stmt = $this->db->prepare('INSERT INTO course_modules (course_id, title, description, position) VALUES (?, ?, ?, ?)');
        $stmt->execute([$courseId, $title, trim((string) ($d['description'] ?? '')) ?: null, $position]);
        $this->json(['id' => (int) $this->db->lastInsertId()], 201);
    }

    private function adminLinkPathCourse(): void
    {
        $d = $this->input();
        $pathId = (int) ($d['pathId'] ?? 0);
        $courseId = (int) ($d['courseId'] ?? 0);
        if (!$this->rowExists('learning_paths', $pathId) || !$this->rowExists('courses', $courseId)) {
            $this->json(['error' => 'Trilha ou curso inexistente.'], 422);
            return;
        }
        $this->ensureLink(
            'SELECT id FROM learning_path_courses WHERE path_id = ? AND course_id = ?',
            [$pathId, $courseId],
            'INSERT INTO learning_path_courses (path_id, course_id, position) VALUES (?, ?, ?)',
            [$pathId, $courseId, (int) ($d['position'] ?? 0)]
        );
        $this->json(['ok' => true], 201);
    }

    private function adminLinkCourseDeck(): void
    {
        $d = $this->input();
        $courseId = (int) ($d['courseId'] ?? 0);
        $deckId = (int) ($d['deckId'] ?? 0);
        if (!$this->rowExists('courses', $courseId) || !$this->rowExists('decks', $deckId)) {
            $this->json(['error' => 'Curso ou baralho inexistente.'], 422);
            return;
        }
        $moduleId = $this->nullableFk('course_modules', $d['moduleId'] ?? null);
        $this->ensureLink(
            'SELECT id FROM course_decks WHERE course_id = ? AND deck_id = ?',
            [$courseId, $deckId],
            'INSERT INTO course_decks (course_id, deck_id, module_id, position) VALUES (?, ?, ?, ?)',
            [$courseId, $deckId, $moduleId, (int) ($d['position'] ?? 0)]
        );
        $this->json(['ok' => true], 201);
    }

    private function adminEnroll(): void
    {
        $d = $this->input();
        $userId = (int) ($d['userId'] ?? 0);
        $courseId = (int) ($d['courseId'] ?? 0);
        if (!$this->rowExists('users', $userId) || !$this->rowExists('courses', $courseId)) {
            $this->json(['error' => 'Estudante ou curso inexistente.'], 422);
            return;
        }
        $this->ensureLink(
            'SELECT id FROM enrollments WHERE user_id = ? AND course_id = ?',
            [$userId, $courseId],
            'INSERT INTO enrollments (user_id, course_id, status) VALUES (?, ?, ?)',
            [$userId, $courseId, 'active']
        );
        $this->json(['ok' => true], 201);
    }

    /** Edita/inativa um curso. Mantém o slug estável para não quebrar referências. */
    private function adminUpdateCourse(int $id): void
    {
        $current = $this->prepared(
            'SELECT title, description, level, status, area_id, active FROM courses WHERE id = ?',
            [$id]
        )->fetch();

        if (!$current) {
            $this->json(['error' => 'Curso inexistente.'], 404);
            return;
        }

        $d = $this->input();
        $title = trim((string) ($d['title'] ?? $current['title']));
        if ($title === '') {
            $this->json(['error' => 'Informe o titulo do curso.'], 422);
            return;
        }

        $description = array_key_exists('description', $d) ? (trim((string) $d['description']) ?: null) : $current['description'];
        $level = trim((string) ($d['level'] ?? $current['level'])) ?: 'introdutorio';
        $status = in_array(($d['status'] ?? ''), ['available', 'coming_soon'], true) ? $d['status'] : $current['status'];
        $active = array_key_exists('active', $d) ? (int) ((bool) $d['active']) : (int) $current['active'];
        $areaId = array_key_exists('areaId', $d)
            ? $this->nullableFk('knowledge_areas', $d['areaId'])
            : ($current['area_id'] !== null ? (int) $current['area_id'] : null);

        $stmt = $this->db->prepare('UPDATE courses SET title = ?, description = ?, level = ?, status = ?, area_id = ?, active = ? WHERE id = ?');
        $stmt->execute([$title, $description, $level, $status, $areaId, $active, $id]);
        $this->json(['ok' => true, 'id' => $id]);
    }

    /** Matrícula em lote (turma): cria os estudantes ausentes e matricula todos. */
    private function adminBulkEnroll(): void
    {
        $d = $this->input();
        $courseId = (int) ($d['courseId'] ?? 0);
        if (!$this->rowExists('courses', $courseId)) {
            $this->json(['error' => 'Curso inexistente.'], 422);
            return;
        }

        $defaultPassword = (string) ($d['defaultPassword'] ?? '');
        $students = is_array($d['students'] ?? null) ? $d['students'] : [];
        if ($students === []) {
            $this->json(['error' => 'Nenhum estudante informado.'], 422);
            return;
        }

        $created = 0;
        $enrolled = 0;
        $already = 0;
        $errors = [];
        $line = 0;

        foreach ($students as $s) {
            $line++;
            $email = strtolower(trim((string) ($s['email'] ?? '')));
            $name = trim((string) ($s['name'] ?? ''));
            if ($name === '' && $email !== '') {
                $name = ucfirst(explode('@', $email)[0]);
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = ['line' => $line, 'email' => $email, 'reason' => 'e-mail invalido'];
                continue;
            }

            $userId = $this->findUserIdByEmail($email);
            if ($userId === null) {
                $password = (string) ($s['password'] ?? '') ?: $defaultPassword;
                if (strlen($password) < 6) {
                    $errors[] = ['line' => $line, 'email' => $email, 'reason' => 'aluno novo sem senha (min. 6)'];
                    continue;
                }
                $ins = $this->db->prepare('INSERT INTO users (name, email, password_hash, role, active) VALUES (?, ?, ?, ?, 1)');
                $ins->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'student']);
                $userId = (int) $this->db->lastInsertId();
                $created++;
            }

            $exists = $this->prepared('SELECT id FROM enrollments WHERE user_id = ? AND course_id = ?', [$userId, $courseId])->fetchColumn();
            if ($exists !== false) {
                $already++;
                continue;
            }

            $this->db->prepare('INSERT INTO enrollments (user_id, course_id, status) VALUES (?, ?, ?)')->execute([$userId, $courseId, 'active']);
            $enrolled++;
        }

        $this->json([
            'ok' => true,
            'total' => $line,
            'created' => $created,
            'enrolled' => $enrolled,
            'alreadyEnrolled' => $already,
            'errors' => $errors,
        ], 201);
    }

    /** DELETE genérico para vínculos administrativos (lista branca de tabelas). */
    private function adminDelete(string $table, int $id): void
    {
        if (!in_array($table, ['enrollments', 'course_decks'], true)) {
            $this->json(['error' => 'Operacao nao permitida.'], 403);
            return;
        }
        $stmt = $this->db->prepare("DELETE FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        $this->json(['ok' => true, 'deleted' => $stmt->rowCount()]);
    }

    private function rowsInt(array $rows, array $intKeys): array
    {
        return array_map(function (array $row) use ($intKeys): array {
            foreach ($intKeys as $key) {
                if (array_key_exists($key, $row) && $row[$key] !== null) {
                    $row[$key] = (int) $row[$key];
                }
            }
            return $row;
        }, $rows);
    }

    private function rowExists(string $table, int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $stmt = $this->db->prepare("SELECT 1 FROM {$table} WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetchColumn() !== false;
    }

    private function nullableFk(string $table, mixed $value): ?int
    {
        $id = (int) ($value ?? 0);
        return ($id > 0 && $this->rowExists($table, $id)) ? $id : null;
    }

    private function slugify(string $text): string
    {
        $text = trim($text);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? $text;
        $text = trim($text, '-');
        return $text !== '' ? $text : 'item';
    }

    private function uniqueSlug(string $table, string $base): string
    {
        $slug = $base;
        $n = 2;
        $stmt = $this->db->prepare("SELECT 1 FROM {$table} WHERE slug = ? LIMIT 1");
        while (true) {
            $stmt->execute([$slug]);
            if ($stmt->fetchColumn() === false) {
                return $slug;
            }
            $slug = $base . '-' . $n;
            $n++;
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
        $allowed = $this->allowedDeckIds();

        // Estudante sem nenhum baralho liberado: lista vazia (sem erro).
        if ($allowed !== null && $allowed === []) {
            $this->json(['decks' => []]);
            return;
        }

        $where = 'decks.active = 1';
        $params = [];
        if ($allowed !== null) {
            $placeholders = implode(',', array_fill(0, count($allowed), '?'));
            $where .= " AND decks.id IN ($placeholders)";
            $params = $allowed;
        }

        $stmt = $this->db->prepare(
            'SELECT decks.id, decks.title, decks.description, decks.category, COUNT(cards.id) AS totalCards
             FROM decks
             LEFT JOIN cards ON cards.deck_id = decks.id AND cards.active = 1
             WHERE ' . $where . '
             GROUP BY decks.id
             ORDER BY decks.id'
        );
        $stmt->execute($params);

        $this->json(['decks' => array_map([$this, 'normalizeDeck'], $stmt->fetchAll())]);
    }

    private function showDeck(int $id): void
    {
        $this->requireDeckAccess($id);

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

        $ownerId = (int) ($this->currentUser['id'] ?? 0) ?: null;
        $stmt = $this->db->prepare('INSERT INTO decks (title, description, category, owner_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([$title, $description, $category, $ownerId]);

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
        $this->requireDeckAccess($deckId);

        $includeMedia = ($_GET['includeMedia'] ?? '1') !== '0';
        // Em modo "lista" (includeMedia=0) descartamos as colunas pesadas que
        // nao sao necessarias para popular a tabela do Card Browser nem para
        // o primeiro render da tela de estudo:
        //  - image_data / audio_data: ja eram excluidas;
        //  - occlusion_masks: JSON potencialmente grande em decks de oclusao;
        // O fetch completo (apiGetCard) continua retornando todas as colunas.
        $columns = $includeMedia
            ? '*'
            : 'id, deck_id, question, answer, question_html, answer_html, card_type';
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

    private function showCard(int $cardId): void
    {
        $card = $this->getCard($cardId);
        if (!$card) {
            $this->json(['error' => 'Cartao nao encontrado.'], 404);
            return;
        }

        $this->requireDeckAccess((int) $card['deckId']);

        $this->json(['card' => $card]);
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
        $stmt = $this->db->prepare('SELECT * FROM cards WHERE id = ? AND active = 1 LIMIT 1');
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
        // Importar pacotes Anki grandes (anatomia com centenas de imagens) leva minutos.
        // O timeout padrao do SAPI server (30s) matava o script no meio da transacao,
        // deixando o deck criado mas sem cards.
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

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
        $ankiCards = $this->readAnkiCards($source);
        $models = $this->readAnkiModels($source);
        $mediaMap = $this->readAnkiMediaMap($tmpDir);
        $mediaIndex = $this->buildAnkiMediaIndex($tmpDir, $mediaMap);
        $created = 0;
        $stmt = $this->db->prepare(
            'INSERT INTO cards (deck_id, question, answer, question_html, answer_html, card_type, image_data, audio_data, occlusion_masks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $updateExisting = $this->db->prepare(
            'UPDATE cards SET question = ?, answer = ?, question_html = ?, answer_html = ?, card_type = ?, image_data = ?, audio_data = ?, occlusion_masks = ? WHERE id = ?'
        );
        $existingCards = $this->db->prepare('SELECT id, question, answer FROM cards WHERE deck_id = ? AND active = 1');
        $existingCards->execute([$deckId]);
        $existingByQuestion = [];

        foreach ($existingCards->fetchAll() as $existingCard) {
            $key = (string) $existingCard['question'] . '|' . substr((string) $existingCard['answer'], 0, 120);
            $existingByQuestion[$key] = (int) $existingCard['id'];
        }

        $this->db->beginTransaction();

        $totalNotes = count($notes);
        $totalCards = count($ankiCards);
        $skipped = 0;
        $mediaInlined = 0;
        $skippedReasons = [];
        $skippedDetails = [];
        $cardRows = $totalCards > 0 ? $ankiCards : array_map(static function (array $note): array {
            return [
                'anki_card_id' => null,
                'ord' => 0,
                'note_id' => $note['id'] ?? null,
                'mid' => $note['mid'] ?? null,
                'flds' => $note['flds'] ?? '',
            ];
        }, $notes);

        try {
            foreach ($cardRows as $cardRow) {
                $fields = explode("\x1f", (string) $cardRow['flds']);
                $rendered = $this->renderAnkiCard($cardRow, $fields, $models);
                $frontHtml = $rendered['front'] ?: (string) ($fields[0] ?? '');
                $backHtml = $rendered['back'] ?: (string) ($fields[1] ?? '');

                $inlinedFront = $this->inlineAnkiMediaInHtml($frontHtml, $mediaIndex);
                $inlinedBack = $this->inlineAnkiMediaInHtml($backHtml, $mediaIndex);
                $frontHtml = $inlinedFront['html'];
                $backHtml = $inlinedBack['html'];
                $mediaInlined += $inlinedFront['inlined'] + $inlinedBack['inlined'];

                $question = $this->ankiHtmlToText($frontHtml);
                $answer = $this->ankiHtmlToText($backHtml);
                $questionHtml = $this->sanitizeAnkiHtml($frontHtml);
                $answerHtml = $this->sanitizeAnkiHtml($backHtml);
                $mediaHtml = $frontHtml . $backHtml . implode('', $fields);
                $imageData = $this->extractAnkiMediaData($mediaHtml, $mediaIndex, 'image');
                $audioData = $this->extractAnkiMediaData($mediaHtml, $mediaIndex, 'audio');
                $cardType = 'basic';
                $occlusionMasks = null;

                $modelName = (string) ($models[(string) ($cardRow['mid'] ?? '')]['name'] ?? '');
                $looksLikeOcclusion = stripos($modelName, 'occlusion') !== false
                    || stripos($modelName, 'oclus') !== false;

                $occlusion = $this->extractAnkiImageOcclusion($fields, $mediaIndex);
                if ($occlusion) {
                    $cardType = 'image_occlusion';
                    $imageData = $occlusion['imageData'] ?? $imageData;
                    $occlusionMasks = $occlusion['masks'];
                    $label = $this->ankiHtmlToText((string) ($fields[1] ?? ''));
                    if ($label !== '') {
                        $sourceId = $this->ankiHtmlToText((string) ($fields[0] ?? ''));
                        $suffix = preg_match('/-ao-(\d+)$/i', $sourceId, $match)
                            ? ' #' . $match[1]
                            : ' #' . (((int) ($cardRow['ord'] ?? 0)) + 1);
                        $question = trim($label . $suffix);
                        $answer = $label;
                        $questionHtml = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $answerHtml = $questionHtml;
                    }
                } elseif ($looksLikeOcclusion) {
                    // Newer Anki built-in image occlusion: model name contains "Occlusion" but the
                    // legacy SVG-based extractor returns null. Import the card preserving raw fields
                    // so it is not silently dropped; rendering of masks comes in a follow-up change.
                    $cardType = 'image_occlusion';
                }

                $hasVisualContent = ($questionHtml !== null) || ($answerHtml !== null)
                    || ($imageData !== null) || ($audioData !== null)
                    || ($occlusionMasks !== null && $occlusionMasks !== []);

                if ($question === '' && $answer === '' && !$hasVisualContent) {
                    $skipped++;
                    $skippedReasons['empty_card'] = ($skippedReasons['empty_card'] ?? 0) + 1;
                    $skippedDetails[] = [
                        'noteId' => $cardRow['note_id'] ?? null,
                        'cardId' => $cardRow['anki_card_id'] ?? null,
                        'ord' => (int) ($cardRow['ord'] ?? 0),
                        'model' => $modelName,
                        'reason' => 'empty_card',
                    ];
                    continue;
                }

                if ($question === '') {
                    $question = $cardType === 'image_occlusion'
                        ? '[Cartao de oclusao de imagem]'
                        : '[Cartao com midia]';
                }
                if ($answer === '') {
                    $answer = $question;
                }

                $dedupKey = $question . '|' . substr($answer, 0, 120);
                $existingId = $existingByQuestion[$dedupKey] ?? null;

                if ($existingId) {
                    $updateExisting->execute([$question, $answer, $questionHtml, $answerHtml, $cardType, $imageData, $audioData, $occlusionMasks ? json_encode($occlusionMasks) : null, $existingId]);
                } else {
                    $stmt->execute([$deckId, $question, $answer, $questionHtml, $answerHtml, $cardType, $imageData, $audioData, $occlusionMasks ? json_encode($occlusionMasks) : null]);
                }

                $created++;
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }

        $summary = [
            'imported' => $created,
            'skipped' => $skipped,
            'totalNotes' => $totalNotes,
            'totalCards' => $totalCards,
            'mediaInlined' => $mediaInlined,
            'mediaFiles' => count($mediaIndex),
            'skippedReasons' => (object) $skippedReasons,
            'skippedDetails' => array_slice($skippedDetails, 0, 20),
            'deck' => $this->findDeckSummary($deckId),
        ];

        error_log('Suinda APKG import: ' . json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->json($summary, 201);
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
        $html = preg_replace('/<\s*(script|style|iframe|object|embed)[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html) ?? $html;
        $html = preg_replace('/\[sound:[^\]]+\]/i', '', $html) ?? $html;
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = str_replace("\xc2\xa0", ' ', $html);

        $allowed = '<b><strong><i><em><u><br><hr><div><p><span><font><ul><ol><li><sup><sub>'
            . '<img><figure><figcaption>'
            . '<audio><video><source>'
            . '<table><thead><tbody><tfoot><tr><td><th>'
            . '<h1><h2><h3><h4><h5><h6>'
            . '<svg><g><rect><circle><ellipse><line><polyline><polygon><path><text><tspan><defs><use><image>';
        $html = strip_tags($html, $allowed);

        // Strip inline event handlers and risky attributes; keep src/alt/width/height/viewBox/xmlns/etc.
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        $html = preg_replace('/\s+(style|class|id|data-[\w-]+)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;

        // Neutralize javascript:/vbscript: in src and href.
        $html = preg_replace('/\s+(src|href|xlink:href)\s*=\s*("|\')(\s*(?:javascript|vbscript|data:text\/html)[^"\']*)\2/i', ' $1=""', $html) ?? $html;

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

    private function readAnkiCards(PDO $source): array
    {
        try {
            return $source->query(
                'SELECT cards.id AS anki_card_id,
                    cards.nid AS note_id,
                    cards.ord AS ord,
                    cards.did AS deck_id,
                    notes.mid AS mid,
                    notes.flds AS flds
                 FROM cards
                 INNER JOIN notes ON notes.id = cards.nid
                 ORDER BY cards.id'
            )->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    private function renderAnkiCard(array $card, array $fields, array $models): array
    {
        $model = $models[(string) ($card['mid'] ?? '')] ?? null;

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

        $ord = max(0, (int) ($card['ord'] ?? 0));
        $template = $model['tmpls'][$ord] ?? ($model['tmpls'][0] ?? null);
        if (!is_array($template)) {
            return [
                'front' => $this->firstNonEmptyAnkiField($fields),
                'back' => $this->secondNonEmptyAnkiField($fields),
            ];
        }

        $front = $this->renderAnkiTemplate((string) ($template['qfmt'] ?? ''), $fieldMap, $ord, 'question');
        $backTemplate = (string) ($template['afmt'] ?? '');
        $back = str_replace('{{FrontSide}}', $front, $backTemplate);
        $back = $this->renderAnkiTemplate($back, $fieldMap, $ord, 'answer');
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

    private function renderAnkiTemplate(string $template, array $fields, int $ord = 0, string $side = 'question'): string
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

        $rendered = preg_replace_callback('/{{([^}]+)}}/', function ($match) use ($fields, $ord, $side) {
            $name = trim((string) $match[1]);
            if (str_starts_with(strtolower($name), 'cloze:')) {
                $fieldName = trim(substr($name, 6));
                return $this->renderAnkiCloze((string) ($fields[$fieldName] ?? ''), $ord + 1, $side);
            }

            if (str_contains($name, ':')) {
                $parts = explode(':', $name);
                $fieldName = trim((string) end($parts));
                return (string) ($fields[$fieldName] ?? '');
            }

            if (in_array($name, ['Tags', 'Type', 'Deck', 'Subdeck', 'Card'], true)) {
                return '';
            }

            return (string) ($fields[$name] ?? '');
        }, $rendered) ?? $rendered;

        $rendered = preg_replace('/<\s*(script|style)[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $rendered) ?? $rendered;

        return trim($rendered);
    }

    private function renderAnkiCloze(string $html, int $clozeNumber, string $side): string
    {
        if ($html === '') {
            return '';
        }

        return preg_replace_callback('/{{c(\d+)::(.*?)(?:::.*?)?}}/s', static function (array $match) use ($clozeNumber, $side): string {
            $number = (int) $match[1];
            $text = (string) $match[2];

            if ($side === 'answer') {
                return $text;
            }

            return $number === $clozeNumber ? '[...]' : $text;
        }, $html) ?? $html;
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

    /**
     * Replace <img src="filename"> references with inline data URLs resolved from the Anki media index.
     * Returns ['html' => string, 'inlined' => int].
     */
    private function inlineAnkiMediaInHtml(string $html, array $mediaIndex): array
    {
        if ($html === '') {
            return ['html' => $html, 'inlined' => 0];
        }

        $inlined = 0;
        $cache = [];

        $resolve = function (string $name) use ($mediaIndex, &$cache): ?string {
            $key = $this->normalizeAnkiMediaName($name);
            if (array_key_exists($key, $cache)) {
                return $cache[$key];
            }
            $path = $this->resolveAnkiMediaPath($name, $mediaIndex);
            if (!$path) {
                return $cache[$key] = null;
            }
            $mime = mime_content_type($path) ?: $this->guessMediaMime($name, 'image');
            return $cache[$key] = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
        };

        $html = preg_replace_callback(
            '/<img\b([^>]*?)\bsrc\s*=\s*(["\'])([^"\']+)\2([^>]*)>/i',
            function (array $match) use ($resolve, &$inlined): string {
                $src = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (preg_match('#^(data|https?):#i', $src)) {
                    return $match[0];
                }
                $dataUrl = $resolve($src);
                if (!$dataUrl) {
                    return $match[0];
                }
                $inlined++;
                return '<img' . $match[1] . 'src="' . $dataUrl . '"' . $match[4] . '>';
            },
            $html
        ) ?? $html;

        return ['html' => $html, 'inlined' => $inlined];
    }

    private function extractAnkiMediaData(string $html, array $mediaIndex, string $type): ?string
    {
        static $dataUrlCache = [];

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
            $cacheKey = $type . ':' . $normalized;
            if (array_key_exists($cacheKey, $dataUrlCache)) {
                if ($dataUrlCache[$cacheKey] !== null) {
                    return $dataUrlCache[$cacheKey];
                }
                continue;
            }

            $path = $mediaIndex[$normalized] ?? $mediaIndex[strtolower($normalized)] ?? null;

            if (!$path || !is_file($path)) {
                $dataUrlCache[$cacheKey] = null;
                continue;
            }

            if ($this->isZstdFile($path)) {
                $target = $path . '.decoded';
                if (!is_file($target) && !$this->decompressZstdFile($path, $target)) {
                    $dataUrlCache[$cacheKey] = null;
                    continue;
                }

                $path = $target;
            }

            $mime = mime_content_type($path) ?: $this->guessMediaMime($fileName, $type);
            $dataUrl = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
            $dataUrlCache[$cacheKey] = $dataUrl;
            return $dataUrl;
        }

        return null;
    }

    private function extractAnkiImageOcclusion(array $fields, array $mediaIndex): ?array
    {
        $baseImage = null;
        $questionSvg = null;

        foreach ($fields as $field) {
            foreach ($this->extractImageSources((string) $field) as $source) {
                $normalized = $this->normalizeAnkiMediaName($source);
                if (preg_match('/-Q\.svg$/i', $normalized)) {
                    $questionSvg = $source;
                    continue;
                }

                if ($baseImage === null && !preg_match('/\.(?:svg)$/i', $normalized)) {
                    $baseImage = $source;
                }
            }
        }

        if (!$baseImage || !$questionSvg) {
            return null;
        }

        $svg = $this->extractAnkiMediaText($questionSvg, $mediaIndex);
        if ($svg === null) {
            return null;
        }

        $masks = $this->parseAnkiOcclusionMasks($svg);
        if (!$masks) {
            return null;
        }

        return [
            'imageData' => $this->getAnkiMediaDataByName($baseImage, $mediaIndex, 'image'),
            'masks' => $masks,
        ];
    }

    private function extractImageSources(string $html): array
    {
        $sources = [];

        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $match) {
                $sources[] = html_entity_decode($match, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        if (preg_match_all('/<(?:image|source)[^>]+(?:href|xlink:href|src)=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $match) {
                $sources[] = html_entity_decode($match, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        if (preg_match_all('/url\((["\']?)([^"\')]+)\1\)/i', $html, $matches)) {
            foreach ($matches[2] as $match) {
                $sources[] = html_entity_decode($match, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        if (!$sources) {
            return [];
        }

        return array_values(array_unique($sources));
    }

    private function extractFirstImageSource(string $html): ?string
    {
        $sources = $this->extractImageSources($html);
        return $sources[0] ?? null;
    }

    private function extractAnkiMediaText(string $fileName, array $mediaIndex): ?string
    {
        $path = $this->resolveAnkiMediaPath($fileName, $mediaIndex);
        if (!$path) {
            return null;
        }

        return (string) file_get_contents($path);
    }

    private function getAnkiMediaDataByName(string $fileName, array $mediaIndex, string $type): ?string
    {
        static $cache = [];
        $key = $type . ':' . $this->normalizeAnkiMediaName($fileName);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $path = $this->resolveAnkiMediaPath($fileName, $mediaIndex);
        if (!$path) {
            return $cache[$key] = null;
        }

        $mime = mime_content_type($path) ?: $this->guessMediaMime($fileName, $type);
        return $cache[$key] = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
    }

    private function resolveAnkiMediaPath(string $fileName, array $mediaIndex): ?string
    {
        static $cache = [];
        $normalized = $this->normalizeAnkiMediaName($fileName);
        if (array_key_exists($normalized, $cache)) {
            return $cache[$normalized];
        }

        $path = $mediaIndex[$normalized] ?? $mediaIndex[strtolower($normalized)] ?? null;

        if (!$path || !is_file($path)) {
            return $cache[$normalized] = null;
        }

        if (!$this->isZstdFile($path)) {
            return $cache[$normalized] = $path;
        }

        $target = $path . '.decoded';
        if (is_file($target)) {
            return $cache[$normalized] = $target;
        }
        if (!$this->decompressZstdFile($path, $target)) {
            return $cache[$normalized] = null;
        }

        return $cache[$normalized] = $target;
    }

    private function parseAnkiOcclusionMasks(string $svg): array
    {
        $width = $this->extractSvgNumber($svg, 'width');
        $height = $this->extractSvgNumber($svg, 'height');

        if ((!$width || !$height) && preg_match('/\bviewBox=["\']\s*[-.\d]+\s+[-.\d]+\s+([-\.\d]+)\s+([-\.\d]+)/i', $svg, $viewBox)) {
            $width = $width ?: (float) $viewBox[1];
            $height = $height ?: (float) $viewBox[2];
        }

        if (!$width || !$height) {
            return [];
        }

        if (!preg_match_all('/<rect\b[^>]*>/i', $svg, $matches)) {
            return [];
        }

        $masks = [];
        foreach ($matches[0] as $rect) {
            $x = $this->extractSvgNumber($rect, 'x');
            $y = $this->extractSvgNumber($rect, 'y');
            $rectWidth = $this->extractSvgNumber($rect, 'width');
            $rectHeight = $this->extractSvgNumber($rect, 'height');

            if ($rectWidth === null || $rectHeight === null || $rectWidth <= 0 || $rectHeight <= 0) {
                continue;
            }

            $masks[] = [
                'x' => max(0, min(100, (($x ?? 0) / $width) * 100)),
                'y' => max(0, min(100, (($y ?? 0) / $height) * 100)),
                'width' => max(0, min(100, ($rectWidth / $width) * 100)),
                'height' => max(0, min(100, ($rectHeight / $height) * 100)),
                'isTarget' => $this->isAnkiTargetOcclusionMask($rect),
            ];
        }

        return $masks;
    }

    private function isAnkiTargetOcclusionMask(string $rect): bool
    {
        return (bool) preg_match('/\bclass=["\'][^"\']*\bqshape\b/i', $rect)
            || (bool) preg_match('/\bfill=["\']#?ff7e7e["\']/i', $rect);
    }

    private function extractSvgNumber(string $source, string $attribute): ?float
    {
        if (!preg_match('/\b' . preg_quote($attribute, '/') . '=["\']\s*(-?\d+(?:\.\d+)?)/i', $source, $match)) {
            return null;
        }

        return (float) $match[1];
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
        $deckId = $this->cardDeckId($cardId);
        if ($deckId === null) {
            $this->json(['error' => 'Cartao nao encontrado.'], 404);
            return;
        }
        $this->requireDeckAccess($deckId);

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

    private function stripBasePath(string $path): string
    {
        $base = (string) ($this->config['base_path'] ?? '');

        if ($base === '') {
            // Auto-detecta o prefixo a partir do diretorio do script de entrada
            // (ex.: /suinda/api quando servido por Apache). Em servidor PHP
            // embutido com router, SCRIPT_NAME costuma ser "/" e nada e removido.
            $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
            $base = rtrim(dirname($script), '/');
        }

        $base = '/' . trim($base, '/');
        if ($base !== '/' && str_starts_with($path, $base)) {
            $stripped = substr($path, strlen($base));
            return $stripped === '' ? '/' : $stripped;
        }

        return $path;
    }

    private function isAdmin(): bool
    {
        return ($this->currentUser['role'] ?? '') === 'admin';
    }

    /**
     * IDs de baralhos que o usuario atual pode ver/estudar.
     * Retorna null quando nao ha restricao (admin enxerga tudo).
     */
    private function allowedDeckIds(): ?array
    {
        if ($this->isAdmin()) {
            return null;
        }

        $userId = (int) ($this->currentUser['id'] ?? 0);

        $stmt = $this->db->prepare(
            'SELECT DISTINCT course_decks.deck_id AS id
             FROM course_decks
             INNER JOIN enrollments ON enrollments.course_id = course_decks.course_id
             INNER JOIN courses ON courses.id = course_decks.course_id
             INNER JOIN decks ON decks.id = course_decks.deck_id
             WHERE enrollments.user_id = ? AND enrollments.status = ? AND decks.active = 1 AND courses.active = 1'
        );
        $stmt->execute([$userId, 'active']);
        $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));

        // Baralhos pessoais do proprio estudante continuam acessiveis.
        $owned = $this->db->prepare('SELECT id FROM decks WHERE owner_id = ? AND active = 1');
        $owned->execute([$userId]);
        $ids = array_merge($ids, array_map('intval', array_column($owned->fetchAll(), 'id')));

        return array_values(array_unique($ids));
    }

    private function deckAllowed(int $deckId): bool
    {
        $allowed = $this->allowedDeckIds();
        return $allowed === null || in_array($deckId, $allowed, true);
    }

    private function requireDeckAccess(int $deckId): void
    {
        if (!$this->deckAllowed($deckId)) {
            $this->json(['error' => 'Este conteudo ainda nao foi liberado para a sua conta.'], 403);
            exit;
        }
    }

    private function cardDeckId(int $cardId): ?int
    {
        $stmt = $this->db->prepare('SELECT deck_id FROM cards WHERE id = ? AND active = 1 LIMIT 1');
        $stmt->execute([$cardId]);
        $deckId = $stmt->fetchColumn();

        return $deckId === false ? null : (int) $deckId;
    }

    private function dashboard(): void
    {
        $courses = $this->enrolledCourses();
        $paths = $this->releasedPaths();

        $totals = ['courses' => count($courses), 'decks' => 0, 'newCards' => 0, 'dueCards' => 0];
        foreach ($courses as $course) {
            $totals['decks'] += count($course['decks']);
            $totals['newCards'] += $course['progress']['newCards'];
            $totals['dueCards'] += $course['progress']['dueCards'];
        }

        $this->json([
            'user' => $this->currentUser,
            'courses' => $courses,
            'paths' => $paths,
            'totals' => $totals,
            'hasContent' => count($courses) > 0,
        ]);
    }

    /** Cursos em que o estudante esta matriculado, com baralhos e progresso. */
    private function enrolledCourses(): array
    {
        $userId = (int) ($this->currentUser['id'] ?? 0);

        if ($this->isAdmin()) {
            $courseRows = $this->db->query(
                'SELECT id, title, slug, description, level, status FROM courses WHERE active = 1 ORDER BY id'
            )->fetchAll();
        } else {
            $courseRows = $this->prepared(
                'SELECT courses.id, courses.title, courses.slug, courses.description, courses.level, courses.status
                 FROM courses
                 INNER JOIN enrollments ON enrollments.course_id = courses.id
                 WHERE enrollments.user_id = ? AND enrollments.status = ? AND courses.active = 1
                 ORDER BY courses.id',
                [$userId, 'active']
            )->fetchAll();
        }

        $deckStmt = $this->db->prepare(
            'SELECT decks.id, decks.title, decks.description, decks.category, course_decks.module_id
             FROM course_decks
             INNER JOIN decks ON decks.id = course_decks.deck_id
             WHERE course_decks.course_id = ? AND decks.active = 1
             ORDER BY course_decks.position, decks.id'
        );
        $moduleCountStmt = $this->db->prepare('SELECT COUNT(*) FROM course_modules WHERE course_id = ? AND active = 1');

        $courses = [];
        foreach ($courseRows as $course) {
            $courseId = (int) $course['id'];
            $deckStmt->execute([$courseId]);

            $decks = [];
            $agg = ['total' => 0, 'studied' => 0, 'newCards' => 0, 'dueCards' => 0];

            foreach ($deckStmt->fetchAll() as $deckRow) {
                $deckId = (int) $deckRow['id'];
                $counts = $this->deckStudyCounts($userId, $deckId);
                $decks[] = [
                    'id' => $deckId,
                    'title' => $deckRow['title'],
                    'description' => $deckRow['description'],
                    'category' => $deckRow['category'],
                    'totalCards' => $counts['total'],
                    'newCards' => $counts['newCards'],
                    'dueCards' => $counts['dueCards'],
                    'studiedCards' => $counts['studied'],
                ];
                $agg['total'] += $counts['total'];
                $agg['studied'] += $counts['studied'];
                $agg['newCards'] += $counts['newCards'];
                $agg['dueCards'] += $counts['dueCards'];
            }

            $moduleCountStmt->execute([$courseId]);
            $percent = $agg['total'] > 0 ? (int) round(($agg['studied'] / $agg['total']) * 100) : 0;

            $courses[] = [
                'id' => $courseId,
                'title' => $course['title'],
                'slug' => $course['slug'],
                'description' => $course['description'],
                'level' => $course['level'],
                'status' => $course['status'],
                'modules' => (int) $moduleCountStmt->fetchColumn(),
                'decks' => $decks,
                'progress' => [
                    'totalCards' => $agg['total'],
                    'studiedCards' => $agg['studied'],
                    'newCards' => $agg['newCards'],
                    'dueCards' => $agg['dueCards'],
                    'percent' => $percent,
                ],
            ];
        }

        return $courses;
    }

    /** Trilhas com ao menos um curso liberado para o estudante (admin ve todas). */
    private function releasedPaths(): array
    {
        $userId = (int) ($this->currentUser['id'] ?? 0);
        $isAdmin = $this->isAdmin();

        $paths = $this->db->query(
            'SELECT id, title, slug, description FROM learning_paths WHERE active = 1 ORDER BY id'
        )->fetchAll();

        $courseStmt = $this->db->prepare(
            'SELECT courses.id, courses.title, courses.slug, courses.status
             FROM learning_path_courses
             INNER JOIN courses ON courses.id = learning_path_courses.course_id
             WHERE learning_path_courses.path_id = ? AND courses.active = 1
             ORDER BY learning_path_courses.position, courses.id'
        );
        $enrolledStmt = $this->db->prepare(
            'SELECT COUNT(*) FROM enrollments WHERE user_id = ? AND course_id = ? AND status = ?'
        );

        $result = [];
        foreach ($paths as $path) {
            $courseStmt->execute([(int) $path['id']]);
            $courses = [];
            $hasEnrollment = false;

            foreach ($courseStmt->fetchAll() as $course) {
                $enrolled = $isAdmin;
                if (!$isAdmin) {
                    $enrolledStmt->execute([$userId, (int) $course['id'], 'active']);
                    $enrolled = ((int) $enrolledStmt->fetchColumn()) > 0;
                }
                $hasEnrollment = $hasEnrollment || $enrolled;
                $courses[] = [
                    'id' => (int) $course['id'],
                    'title' => $course['title'],
                    'slug' => $course['slug'],
                    'status' => $course['status'],
                    'enrolled' => $enrolled,
                ];
            }

            if (!$isAdmin && !$hasEnrollment) {
                continue;
            }

            $result[] = [
                'id' => (int) $path['id'],
                'title' => $path['title'],
                'slug' => $path['slug'],
                'description' => $path['description'],
                'courses' => $courses,
            ];
        }

        return $result;
    }

    /** Contagem de cartoes (total, estudados, novos, a revisar) do usuario em um baralho. */
    private function deckStudyCounts(int $userId, int $deckId): array
    {
        $total = (int) $this->prepared(
            'SELECT COUNT(*) FROM cards WHERE deck_id = ? AND active = 1',
            [$deckId]
        )->fetchColumn();

        $studied = (int) $this->prepared(
            'SELECT COUNT(*) FROM card_progress
             INNER JOIN cards ON cards.id = card_progress.card_id
             WHERE cards.deck_id = ? AND cards.active = 1 AND card_progress.user_id = ? AND card_progress.state <> ?',
            [$deckId, $userId, 'new']
        )->fetchColumn();

        $now = (new DateTimeImmutable('now'))->format('c');
        $due = (int) $this->prepared(
            'SELECT COUNT(*) FROM card_progress
             INNER JOIN cards ON cards.id = card_progress.card_id
             WHERE cards.deck_id = ? AND cards.active = 1 AND card_progress.user_id = ?
               AND card_progress.state <> ? AND card_progress.due_at IS NOT NULL AND card_progress.due_at <= ?',
            [$deckId, $userId, 'new', $now]
        )->fetchColumn();

        return [
            'total' => $total,
            'studied' => $studied,
            'newCards' => max(0, $total - $studied),
            'dueCards' => $due,
        ];
    }

    private function prepared(string $sql, array $params): PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    // ============================ Curso ENEM ============================

    /**
     * Fragmento SQL que restringe a cards ENEM acessíveis ao usuário (matrícula).
     * @return array{0:string,1:array} [sql, params]
     */
    private function enemAccessFilter(string $cardAlias = 'c'): array
    {
        $allowed = $this->allowedDeckIds(); // null = admin (tudo)
        $sql = " AND {$cardAlias}.card_type = 'enem'";
        $params = [];
        if ($allowed !== null) {
            if ($allowed === []) {
                return [' AND 1 = 0', []]; // sem matrícula: nada
            }
            $placeholders = implode(',', array_fill(0, count($allowed), '?'));
            $sql .= " AND {$cardAlias}.deck_id IN ($placeholders)";
            $params = $allowed;
        }
        return [$sql, $params];
    }

    /** Linha da questão + deck do card (para gating); null se inexistente. */
    private function enemQuestionRow(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT eq.*, c.deck_id AS deck_id, c.card_type AS card_type,
                    d.name AS discipline, d.slug AS discipline_slug,
                    a.name AS area, a.slug AS area_slug,
                    ct.name AS content_name,
                    comp.code AS competency_code, comp.statement AS competency_statement,
                    sk.code AS skill_code, sk.statement AS skill_statement,
                    ex.name AS exam_name, ex.slug AS exam_slug
             FROM exam_questions eq
             INNER JOIN cards c ON c.id = eq.card_id
             LEFT JOIN disciplines d ON d.id = eq.discipline_id
             LEFT JOIN knowledge_areas a ON a.id = eq.area_id
             LEFT JOIN contents ct ON ct.id = eq.content_id
             LEFT JOIN competencies comp ON comp.id = eq.competency_id
             LEFT JOIN skills sk ON sk.id = eq.skill_id
             LEFT JOIN exams ex ON ex.id = eq.exam_id
             WHERE eq.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function enemImages(int $questionId): array
    {
        $stmt = $this->db->prepare('SELECT position, path, kind, pdf_page FROM question_images WHERE question_id = ? ORDER BY position');
        $stmt->execute([$questionId]);
        return array_map(fn ($r) => [
            'position' => (int) $r['position'], 'path' => $r['path'], 'kind' => $r['kind'],
        ], $stmt->fetchAll());
    }

    private function enemOverview(): void
    {
        $userId = (int) ($this->currentUser['id'] ?? 0);
        [$acc, $accParams] = $this->enemAccessFilter('c');
        $base = 'FROM exam_questions eq INNER JOIN cards c ON c.id = eq.card_id WHERE 1 = 1' . $acc;

        $totalActive = (int) $this->prepared("SELECT COUNT(*) {$base} AND eq.status = 'ativa'", $accParams)->fetchColumn();
        $totalAll = (int) $this->prepared("SELECT COUNT(*) {$base}", $accParams)->fetchColumn();
        $annulled = (int) $this->prepared("SELECT COUNT(*) {$base} AND eq.status = 'anulada'", $accParams)->fetchColumn();

        $byDisc = $this->prepared(
            "SELECT d.name AS discipline, COUNT(*) AS total
             FROM exam_questions eq INNER JOIN cards c ON c.id = eq.card_id
             LEFT JOIN disciplines d ON d.id = eq.discipline_id
             WHERE eq.status = 'ativa'{$acc} GROUP BY d.name ORDER BY d.name",
            $accParams
        )->fetchAll();

        $attBase = "FROM question_attempts qa INNER JOIN exam_questions eq ON eq.id = qa.question_id INNER JOIN cards c ON c.id = eq.card_id WHERE qa.user_id = ? AND eq.status = 'ativa'{$acc}";
        $aParams = array_merge([$userId], $accParams);
        $attempts = (int) $this->prepared("SELECT COUNT(*) {$attBase}", $aParams)->fetchColumn();
        $correctAttempts = (int) $this->prepared("SELECT COALESCE(SUM(qa.is_correct), 0) {$attBase}", $aParams)->fetchColumn();
        $answered = (int) $this->prepared("SELECT COUNT(DISTINCT qa.question_id) {$attBase}", $aParams)->fetchColumn();

        $now = (new DateTimeImmutable('now'))->format('c');
        $studied = (int) $this->prepared(
            "SELECT COUNT(*) FROM exam_questions eq INNER JOIN cards c ON c.id = eq.card_id
             INNER JOIN card_progress cp ON cp.card_id = c.id AND cp.user_id = ? AND cp.state <> 'new'
             WHERE eq.status = 'ativa'{$acc}",
            array_merge([$userId], $accParams)
        )->fetchColumn();
        $due = (int) $this->prepared(
            "SELECT COUNT(*) FROM exam_questions eq INNER JOIN cards c ON c.id = eq.card_id
             INNER JOIN card_progress cp ON cp.card_id = c.id AND cp.user_id = ?
             WHERE eq.status = 'ativa' AND cp.state <> 'new' AND cp.due_at IS NOT NULL AND cp.due_at <= ?{$acc}",
            array_merge([$userId, $now], $accParams)
        )->fetchColumn();

        $course = $this->prepared("SELECT id, title, slug, description FROM courses WHERE slug = 'preparatorio-enem' LIMIT 1", [])->fetch() ?: null;
        $accuracy = $attempts > 0 ? (int) round(($correctAttempts / $attempts) * 100) : 0;

        $this->json([
            'course' => $course ? $this->rowsInt([$course], ['id'])[0] : null,
            'hasContent' => $totalAll > 0,
            'totals' => [
                'questions' => $totalActive, 'annulled' => $annulled,
                'newCards' => max(0, $totalActive - $studied), 'dueCards' => $due,
                'answered' => $answered, 'attempts' => $attempts,
                'correctAttempts' => $correctAttempts, 'accuracy' => $accuracy,
            ],
            'byDiscipline' => array_map(fn ($r) => ['discipline' => $r['discipline'], 'total' => (int) $r['total']], $byDisc),
        ]);
    }

    private function enemTaxonomy(): void
    {
        $this->json([
            'areas' => $this->rowsInt($this->db->query("SELECT id, name, slug FROM knowledge_areas WHERE slug LIKE 'enem-%' ORDER BY name")->fetchAll(), ['id']),
            'disciplines' => $this->rowsInt($this->db->query('SELECT id, area_id, name, slug FROM disciplines ORDER BY name')->fetchAll(), ['id', 'area_id']),
            'contents' => $this->rowsInt($this->db->query('SELECT id, area_id, discipline_id, name FROM contents ORDER BY name')->fetchAll(), ['id', 'area_id', 'discipline_id']),
            'competencies' => $this->rowsInt($this->db->query('SELECT id, area_id, code, number, statement FROM competencies ORDER BY area_id, number')->fetchAll(), ['id', 'area_id', 'number']),
            'skills' => $this->rowsInt($this->db->query('SELECT id, competency_id, code, number, statement FROM skills ORDER BY competency_id, number')->fetchAll(), ['id', 'competency_id', 'number']),
        ]);
    }

    private function enemQuestions(): void
    {
        $userId = (int) ($this->currentUser['id'] ?? 0);
        [$acc, $accParams] = $this->enemAccessFilter('c');
        $g = $_GET;

        $where = '';
        $wp = [];
        $status = (string) ($g['status'] ?? 'ativa');
        if ($status === 'ativa') { $where .= " AND eq.status = 'ativa'"; }
        elseif ($status === 'anulada') { $where .= " AND eq.status = 'anulada'"; }
        // 'todas' => sem filtro de status

        if (!empty($g['discipline'])) { $where .= ' AND d.slug = ?'; $wp[] = $g['discipline']; }
        if (!empty($g['content'])) { $where .= ' AND eq.content_id = ?'; $wp[] = (int) $g['content']; }
        if (!empty($g['competency'])) { $where .= ' AND comp.code = ?'; $wp[] = $g['competency']; }
        if (!empty($g['skill'])) { $where .= ' AND sk.code = ?'; $wp[] = $g['skill']; }
        if (!empty($g['exam'])) { $where .= ' AND ex.slug = ?'; $wp[] = $g['exam']; }

        $now = (new DateTimeImmutable('now'))->format('c');
        switch ((string) ($g['filter'] ?? '')) {
            case 'novas':
                $where .= " AND NOT EXISTS (SELECT 1 FROM card_progress cp WHERE cp.card_id = c.id AND cp.user_id = ? AND cp.state <> 'new')";
                $wp[] = $userId; break;
            case 'nao_estudadas':
                $where .= ' AND NOT EXISTS (SELECT 1 FROM question_attempts qa WHERE qa.question_id = eq.id AND qa.user_id = ?)';
                $wp[] = $userId; break;
            case 'vencidas':
            case 'pendentes':
                $where .= " AND EXISTS (SELECT 1 FROM card_progress cp WHERE cp.card_id = c.id AND cp.user_id = ? AND cp.state <> 'new' AND cp.due_at IS NOT NULL AND cp.due_at <= ?)";
                $wp[] = $userId; $wp[] = $now; break;
            case 'erradas':
                $where .= ' AND EXISTS (SELECT 1 FROM question_attempts qa WHERE qa.question_id = eq.id AND qa.user_id = ? AND qa.is_correct = 0)';
                $wp[] = $userId; break;
        }

        $limit = max(1, min(200, (int) ($g['limit'] ?? 60)));
        $randomFn = (($this->config['database_driver'] ?? 'sqlite') === 'mysql') ? 'RAND()' : 'RANDOM()';
        $order = !empty($g['random']) ? $randomFn : 'eq.number';

        $sql = "SELECT eq.id, eq.number, eq.status, eq.content_id, eq.card_id, eq.confidence, eq.review_needed,
                    d.name AS discipline, d.slug AS discipline_slug, ct.name AS content,
                    comp.code AS competency, sk.code AS skill, ex.slug AS exam,
                    (SELECT COUNT(*) FROM question_images qi WHERE qi.question_id = eq.id) AS images,
                    (SELECT COUNT(*) FROM question_attempts qa WHERE qa.question_id = eq.id AND qa.user_id = ?) AS attempts
                FROM exam_questions eq
                INNER JOIN cards c ON c.id = eq.card_id
                LEFT JOIN disciplines d ON d.id = eq.discipline_id
                LEFT JOIN contents ct ON ct.id = eq.content_id
                LEFT JOIN competencies comp ON comp.id = eq.competency_id
                LEFT JOIN skills sk ON sk.id = eq.skill_id
                LEFT JOIN exams ex ON ex.id = eq.exam_id
                WHERE 1 = 1{$acc}{$where}
                ORDER BY {$order} LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$userId], $accParams, $wp));

        $questions = array_map(fn ($r) => [
            'id' => (int) $r['id'], 'number' => (int) $r['number'], 'status' => $r['status'],
            'cardId' => (int) $r['card_id'], 'discipline' => $r['discipline'], 'disciplineSlug' => $r['discipline_slug'],
            'content' => $r['content'], 'competency' => $r['competency'], 'skill' => $r['skill'], 'exam' => $r['exam'],
            'hasImage' => ((int) $r['images']) > 0, 'attempts' => (int) $r['attempts'],
            'reviewNeeded' => ((int) $r['review_needed']) === 1,
        ], $stmt->fetchAll());

        $this->json(['questions' => $questions, 'count' => count($questions)]);
    }

    private function enemShowQuestion(int $id): void
    {
        $row = $this->enemQuestionRow($id);
        if (!$row || ($row['card_type'] ?? '') !== 'enem') {
            $this->json(['error' => 'Questão não encontrada.'], 404);
            return;
        }
        $this->requireDeckAccess((int) $row['deck_id']);

        // Frente: NÃO revela a alternativa correta.
        $alts = $this->db->prepare('SELECT letter, body FROM question_alternatives WHERE question_id = ? ORDER BY letter');
        $alts->execute([$id]);

        $this->json(['question' => [
            'id' => (int) $row['id'], 'number' => (int) $row['number'], 'status' => $row['status'],
            'discipline' => $row['discipline'], 'area' => $row['area'], 'content' => $row['content_name'],
            'competency' => $row['competency_code'], 'competencyStatement' => $row['competency_statement'],
            'skill' => $row['skill_code'], 'skillStatement' => $row['skill_statement'],
            'exam' => $row['exam_name'], 'pdfPage' => $row['pdf_page'] !== null ? (int) $row['pdf_page'] : null,
            'statement' => $row['statement_text'],
            'images' => $this->enemImages($id),
            'imagePending' => count($this->enemImages($id)) === 0,
            'alternatives' => array_map(fn ($a) => ['letter' => $a['letter'], 'body' => $a['body']], $alts->fetchAll()),
            'cardId' => (int) $row['card_id'],
            'annulled' => $row['status'] === 'anulada',
        ]]);
    }

    private function enemAnswer(int $id): void
    {
        $row = $this->enemQuestionRow($id);
        if (!$row || ($row['card_type'] ?? '') !== 'enem') {
            $this->json(['error' => 'Questão não encontrada.'], 404);
            return;
        }
        $this->requireDeckAccess((int) $row['deck_id']);

        $data = $this->input();
        $selected = strtoupper(trim((string) ($data['selected'] ?? '')));
        $timeSpent = (int) ($data['timeSpent'] ?? 0);
        $origin = substr((string) ($data['origin'] ?? 'enem'), 0, 40);

        $altStmt = $this->db->prepare('SELECT letter, body, is_correct FROM question_alternatives WHERE question_id = ? ORDER BY letter');
        $altStmt->execute([$id]);
        $alternatives = array_map(fn ($a) => [
            'letter' => $a['letter'], 'body' => $a['body'], 'isCorrect' => ((int) $a['is_correct']) === 1,
        ], $altStmt->fetchAll());

        $back = [
            'status' => $row['status'],
            'correct' => $row['correct_alternative'],
            'selected' => $selected ?: null,
            'explanation' => $row['explanation'],
            'explanationStatus' => $row['explanation_status'],
            'alternatives' => $alternatives,
            'discipline' => $row['discipline'], 'content' => $row['content_name'],
            'competency' => $row['competency_code'], 'competencyStatement' => $row['competency_statement'],
            'skill' => $row['skill_code'], 'skillStatement' => $row['skill_statement'],
            'cardId' => (int) $row['card_id'],
        ];

        // Anulada: não registra tentativa, não conta acerto/erro.
        if ($row['status'] === 'anulada') {
            $back['isCorrect'] = null;
            $back['annulled'] = true;
            $back['message'] = 'Questão anulada no gabarito oficial. Não conta acerto nem erro.';
            $this->json($back);
            return;
        }

        $isCorrect = ($selected !== '' && $selected === $row['correct_alternative']) ? 1 : 0;
        $attemptNumber = 1 + (int) $this->prepared(
            'SELECT COUNT(*) FROM question_attempts WHERE user_id = ? AND question_id = ?',
            [(int) $this->currentUser['id'], $id]
        )->fetchColumn();

        $this->db->prepare(
            'INSERT INTO question_attempts (user_id, question_id, selected_alternative, is_correct, time_spent_seconds, attempt_number, session_origin)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([(int) $this->currentUser['id'], $id, $selected ?: null, $isCorrect, $timeSpent ?: null, $attemptNumber, $origin]);

        $back['isCorrect'] = (bool) $isCorrect;
        $back['annulled'] = false;
        $back['attemptNumber'] = $attemptNumber;
        $this->json($back);
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
