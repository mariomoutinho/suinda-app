CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(40) NOT NULL DEFAULT 'student',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS decks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    category VARCHAR(100) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    deck_id INT NOT NULL,
    question TEXT NOT NULL,
    answer TEXT NOT NULL,
    question_html LONGTEXT,
    answer_html LONGTEXT,
    card_type VARCHAR(40) NOT NULL DEFAULT 'basic',
    image_data LONGTEXT,
    audio_data LONGTEXT,
    occlusion_masks LONGTEXT,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (deck_id) REFERENCES decks(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS auth_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(128) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS card_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    card_id INT NOT NULL,
    state VARCHAR(30) NOT NULL,
    due_at VARCHAR(40),
    ease_factor DECIMAL(6, 2),
    interval_days INT,
    repetitions INT,
    lapses INT,
    introduced_at VARCHAR(40),
    last_rating VARCHAR(30),
    updated_at VARCHAR(40) NOT NULL,
    UNIQUE KEY unique_user_card (user_id, card_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS study_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    deck_id INT NOT NULL,
    deck_title VARCHAR(160) NOT NULL,
    total INT NOT NULL,
    wrong INT NOT NULL,
    hard INT NOT NULL,
    easy INT NOT NULL,
    very_easy INT NOT NULL,
    started_at VARCHAR(40),
    finished_at VARCHAR(40),
    duration_in_seconds INT NOT NULL,
    average_seconds_per_card INT NOT NULL,
    created_at VARCHAR(40) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (deck_id) REFERENCES decks(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS study_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    card_id INT NOT NULL,
    question TEXT NOT NULL,
    answer_type VARCHAR(30) NOT NULL,
    next_state VARCHAR(30),
    due_at VARCHAR(40),
    FOREIGN KEY (session_id) REFERENCES study_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS study_daily_activity (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    day VARCHAR(10) NOT NULL,
    total_cards INT NOT NULL DEFAULT 0,
    total_seconds INT NOT NULL DEFAULT 0,
    updated_at VARCHAR(40) NOT NULL,
    UNIQUE KEY unique_user_day (user_id, day),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
