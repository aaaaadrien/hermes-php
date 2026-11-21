<?php
/**
 * Database
 * ===============================
 * SQLite (PDO), stored in data/hermes.db, created automatically on first
 * launch:
 *   users, conversations, messages, sessions, amphores_global, amphores_perso.
 */

declare(strict_types=1);

namespace Hermes;

class Database
{
    private static ?\PDO $pdo = null;

    /**
     * Executes an SQL query and returns the result.
     *
     * @param string $sql SQL query with ? placeholders
     * @param array $params Parameters to bind
     * @param string $mode 'fetch' (returns an array) or 'exec' (returns affected rows)
     * @return array|int
     */
    public static function query(string $sql, array $params = [], string $mode = 'fetch'): array|int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        if ($mode === 'exec') {
            return $stmt->rowCount();
        }
        return $stmt->fetch();
    }

    public static function pdo(): \PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        if (!is_dir(HERMES_DATA_DIR)) {
            mkdir(HERMES_DATA_DIR, 0775, true);
        }
        $db = HERMES_DATA_DIR . '/hermes.db';

        $pdo = new \PDO('sqlite:' . $db, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA busy_timeout=10000');

        $pdo->exec(self::SCHEMA);
        self::migrate($pdo);
        self::defaultAdminAccount($pdo);

        self::$pdo = $pdo;
        return $pdo;
    }

    /** Full schema */
    private const SCHEMA = <<<SQL
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    salt          TEXT NOT NULL,
    created_at    INTEGER NOT NULL,
    is_admin      INTEGER NOT NULL DEFAULT 0,
    is_active     INTEGER NOT NULL DEFAULT 1,
    auth_provider TEXT NOT NULL DEFAULT 'local',
    oidc_sub      TEXT,
    theme         TEXT NOT NULL DEFAULT 'light'
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_oidc_sub ON users(oidc_sub) WHERE oidc_sub IS NOT NULL;

CREATE TABLE IF NOT EXISTS conversations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    titre       TEXT NOT NULL,
    amphore_id  TEXT,
    created_at  INTEGER NOT NULL,
    updated_at  INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS messages (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id INTEGER NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    role            TEXT NOT NULL,
    content         TEXT NOT NULL,
    created_at      INTEGER NOT NULL,
    pieces_jointes  TEXT,
    audio_b64       TEXT,
    meta            TEXT
);

CREATE TABLE IF NOT EXISTS sessions (
    token       TEXT PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at  INTEGER NOT NULL,
    expire_at   INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS amphores_global (
    id            TEXT PRIMARY KEY,
    nom           TEXT NOT NULL,
    description   TEXT,
    system_prompt TEXT NOT NULL,
    created_at    INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS amphores_perso (
    id            TEXT PRIMARY KEY,
    user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    nom           TEXT NOT NULL,
    description   TEXT,
    system_prompt TEXT NOT NULL,
    created_at    INTEGER NOT NULL
);
SQL;

    /**
     * Idempotent migrations to
     * support an existing hermes.db file created before certain additions.
     */
    private static function migrate(\PDO $pdo): void
    {
        $migrations = [
            "ALTER TABLE messages ADD COLUMN pieces_jointes TEXT",
            "ALTER TABLE messages ADD COLUMN audio_b64 TEXT",
            "ALTER TABLE messages ADD COLUMN meta TEXT",
            "ALTER TABLE users ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0",
            "ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1",
            "ALTER TABLE users ADD COLUMN auth_provider TEXT NOT NULL DEFAULT 'local'",
            "ALTER TABLE users ADD COLUMN oidc_sub TEXT",
            "ALTER TABLE users ADD COLUMN theme TEXT NOT NULL DEFAULT 'light'",
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_users_oidc_sub ON users(oidc_sub) WHERE oidc_sub IS NOT NULL",
        ];
        foreach ($migrations as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\PDOException) {
                // Column/index already present: ignored (same behavior as the original).
            }
        }
    }

    /**
     * First login: creation of the default admin account (admin/admin),
     * to be changed immediately after logging in.
     */
    private static function defaultAdminAccount(\PDO $pdo): void
    {
        $exists = $pdo->query("SELECT 1 FROM users WHERE id = 1")->fetch();
        if ($exists !== false) {
            return;
        }
        [$hash, $salt] = Auth::hashPassword('admin');
        $stmt = $pdo->prepare(
            "INSERT INTO users (id, username, password_hash, salt, created_at, is_admin)
             VALUES (1, 'admin', ?, ?, ?, 1)"
        );
        $stmt->execute([$hash, $salt, time()]);
    }
}
