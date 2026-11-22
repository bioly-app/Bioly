-- Bioly initial schema (SQLite 3.37+, STRICT tables)

CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    username TEXT NOT NULL UNIQUE,
    email TEXT,
    email_verified_at TEXT,
    password_hash TEXT NOT NULL,
    locale TEXT NOT NULL DEFAULT 'de',
    sessions_valid_after TEXT,
    last_login_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
) STRICT;

CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY,
    ip_hash TEXT NOT NULL,
    username TEXT NOT NULL,
    success INTEGER NOT NULL CHECK (success IN (0, 1)),
    attempted_at TEXT NOT NULL
) STRICT;
CREATE INDEX idx_login_attempts_time ON login_attempts (attempted_at);

CREATE TABLE tokens (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    purpose TEXT NOT NULL CHECK (purpose IN ('email_verify', 'password_mail', 'password_ftp')),
    token_hash TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    expires_at TEXT NOT NULL,
    used_at TEXT,
    created_at TEXT NOT NULL
) STRICT;
CREATE INDEX idx_tokens_purpose ON tokens (purpose, token_hash);

CREATE TABLE settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL CHECK (json_valid(value)),
    updated_at TEXT NOT NULL
) STRICT;

CREATE TABLE blocks (
    id INTEGER PRIMARY KEY,
    parent_id INTEGER REFERENCES blocks (id) ON DELETE CASCADE,
    type TEXT NOT NULL,
    position INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    data TEXT NOT NULL DEFAULT '{}' CHECK (json_valid(data)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
) STRICT;
CREATE INDEX idx_blocks_parent_position ON blocks (parent_id, position);

CREATE TABLE media (
    id INTEGER PRIMARY KEY,
    filename TEXT NOT NULL UNIQUE,
    original_name TEXT NOT NULL,
    mime TEXT NOT NULL CHECK (mime IN ('image/jpeg', 'image/webp', 'image/svg+xml')),
    size INTEGER NOT NULL,
    width INTEGER,
    height INTEGER,
    usage TEXT NOT NULL CHECK (usage IN ('content', 'background', 'icon')),
    alt_text TEXT,
    is_decorative INTEGER NOT NULL DEFAULT 0 CHECK (is_decorative IN (0, 1)),
    color TEXT,
    created_at TEXT NOT NULL
) STRICT;

CREATE TABLE pages (
    id INTEGER PRIMARY KEY,
    type TEXT NOT NULL UNIQUE CHECK (type IN ('imprint', 'privacy')),
    slug TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    content_md TEXT NOT NULL DEFAULT '',
    content_html TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL
) STRICT;
