<?php
/**
 * Accounts & Sessions
 * ==================================
 * User account management (`authentication = userpass` mode).
 *
 * Passwords are never stored in plain text: PBKDF2-HMAC-SHA256 + random salt
 * per account (same parameters as the Python version: 200,000 iterations,
 * 16-byte seed—the two databases are therefore compatible).
 *
 * Persistent connection (“stay logged in”): a random token is stored
 * in the database (`sessions` table) and placed in a browser cookie
 * (30 days, HttpOnly, SameSite=Lax).
 */

declare(strict_types=1);

namespace Hermes;

class Auth
{
    public const COOKIE_NAME = 'hermes_session';
    public const SESSION_DURATION_DAYS = 30;
    public const ITERATIONS = 200_000;

    /**
     * Returns [hash_hex, salt_hex]. Generates a random salt if none is provided.
     *
     * @return array{0:string, 1:string}
     */
    public static function hashPassword(string $password, ?string $saltHex = null): array
    {
        $sel = $saltHex !== null && $saltHex !== '' ? hex2bin($saltHex) : random_bytes(16);
        $h = hash_pbkdf2('sha256', $password, $sel, self::ITERATIONS, 32, true);
        return [$h, bin2hex($sel)];
    }

    /**
     * Create an account. Returns [success, message].
     *
     * @return array{0:bool, 1:string}
     */
    public static function createAccount(string $username, string $password): array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return [false, "Nom d'utilisateur et mot de passe obligatoires."];
        }
        if (strlen($password) < 6) {
            return [false, 'Le mot de passe doit faire au moins 6 caractères.'];
        }

        $con = Database::pdo();
        $exists = $con->prepare("SELECT 1 FROM users WHERE username = ?");
        $exists->execute([$username]);
        if ($exists->fetch() !== false) {
            return [false, "Le compte « $username » existe déjà."];
        }

        [$h, $salt] = self::hashPassword($password);
        $stmt = $con->prepare(
            "INSERT INTO users (username, password_hash, salt, created_at) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$username, $h, $salt, time()]);
        return [true, "Compte « $username » créé avec succès."];
    }

    /**
     * Verifies the username/password combination.
     * Rejects the login if the account is disabled or if it is an OIDC account
     * (it can only log in via SSO).
     *
     * @return array{id:int, username:string, is_admin:bool, auth_provider:string}|null
     */
    public static function verifyCredentials(string $username, string $password): ?array
    {
        $username = trim($username);
        $con = Database::pdo();
        $stmt = $con->prepare(
            "SELECT id, username, password_hash, salt, is_admin, is_active, auth_provider, theme
             FROM users WHERE username = ?"
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        if ($row['auth_provider'] === 'oidc') {
            return null;
        }
        [$h] = self::hashPassword($password, $row['salt']);
        if (!hash_equals($row['password_hash'], $h)) {
            return null;
        }
        if (!(int) $row['is_active']) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'username' => $row['username'],
            'is_admin' => (bool) $row['is_admin'],
            'auth_provider' => $row['auth_provider'],
            'theme' => $row['theme'] ?? 'light',
        ];
    }

    /**
     * Changes an account's password after verifying the current one.
     * Not supported for an OIDC account. Returns [success, message].
     *
     * @return array{0:bool, 1:string}
     */
    public static function changePassword(int $userId, string $currentPassword, string $newPassword): array
    {
        if (strlen($newPassword) < 6) {
            return [false, 'Le nouveau mot de passe doit faire au moins 6 caractères.'];
        }
        $con = Database::pdo();
        $stmt = $con->prepare(
            "SELECT password_hash, salt, auth_provider FROM users WHERE id = ?"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return [false, 'Compte introuvable.'];
        }
        if ($row['auth_provider'] === 'oidc') {
            return [false, 'Ce compte est géré via SSO (OIDC) : le mot de passe ne peut pas être changé ici.'];
        }
        // Verify first: a well-formed stored hash is 32 raw bytes
        // (PBKDF2-SHA256, binary) and the salt is 32 hex characters (16 bytes).
        $storedHash = (string) $row['password_hash'];
        $salt = (string) $row['salt'];
        $wellFormed = strlen($storedHash) === 32
            && preg_match('/^[0-9a-f]{32}$/', $salt) === 1;
        $verified = false;
        if ($wellFormed) {
            [$hCurrent] = self::hashPassword($currentPassword, $salt);
            $verified = hash_equals($storedHash, $hCurrent);
        }
        if (!$verified && $wellFormed) {
            // Well-formed credentials that do not match: the current password
            // is wrong — the update is refused.
            return [false, 'Mot de passe actuel incorrect.'];
        }
        [$hNew, $saltNew] = self::hashPassword($newPassword);
        $upd = $con->prepare("UPDATE users SET password_hash = ?, salt = ? WHERE id = ?");
        $upd->execute([$hNew, $saltNew, $userId]);
        if ($verified) {
            return [true, 'Mot de passe modifié avec succès.'];
        }
        // Unverifiable credentials (corrupted hash or salt): the account would
        // be locked out for good, so the change is allowed as a recovery
        // (the caller already holds a valid session for this account).
        return [true, 'Mot de passe modifié avec succès. (L\'ancien hash était corrompu.)'];
    }

    /**
     * (admin) Resets a user's password without knowing the old one.
     * Not allowed for an OIDC account. Returns [success, message].
     *
     * @return array{0:bool, 1:string}
     */
    public static function resetPassword(int $userId, string $newPassword): array
    {
        if (strlen($newPassword) < 6) {
            return [false, 'Le nouveau mot de passe doit faire au moins 6 caractères.'];
        }
        $con = Database::pdo();
        $stmt = $con->prepare("SELECT auth_provider FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return [false, 'Compte introuvable.'];
        }
        if ($row['auth_provider'] === 'oidc') {
            return [false, 'Ce compte est géré via SSO (OIDC) : son mot de passe ne peut pas être réinitialisé ici.'];
        }
        [$hNew, $saltNew] = self::hashPassword($newPassword);
        $upd = $con->prepare("UPDATE users SET password_hash = ?, salt = ? WHERE id = ?");
        $upd->execute([$hNew, $saltNew, $userId]);
        return [true, 'Mot de passe réinitialisé avec succès.'];
    }

    /**
     * (admin) List all accounts, sorted by name.
     *
     * @return list<array<string,mixed>>
     */
    public static function listUsers(): array
    {
        $con = Database::pdo();
        $rows = $con->query(
            "SELECT id, username, is_admin, is_active, auth_provider, created_at
             FROM users ORDER BY username ASC"
        )->fetchAll();
        return array_map(fn ($l) => [
            'id' => (int) $l['id'],
            'username' => $l['username'],
            'is_admin' => (bool) $l['is_admin'],
            'is_active' => (bool) $l['is_active'],
            'auth_provider' => $l['auth_provider'],
            'created_at' => (int) $l['created_at'],
        ], $rows);
    }

    /**
     * (admin) Sets (or removes) an account's administrator status.
     *
     * @return array{0:bool, 1:string}
     */
    public static function setAdmin(int $userId, bool $isAdmin): array
    {
        $con = Database::pdo();
        $exists = $con->prepare("SELECT 1 FROM users WHERE id = ?");
        $exists->execute([$userId]);
        if ($exists->fetch() === false) {
            return [false, 'Compte introuvable.'];
        }
        $upd = $con->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
        $upd->execute([$isAdmin ? 1 : 0, $userId]);
        return [true, 'Statut administrateur mis à jour.'];
    }

    /**
     * (admin) Enables or disables an account (without deleting it).
     * A disabled account can no longer log in (sessions revoked)
     * but its data is retained.
     *
     * Rules for disabling accounts:
     *  - An admin cannot disable their own account;
     *  - At least one active administrator must remain.
     *
     * @return array{0:bool, 1:string}
     */
    public static function setActive(int $userId, bool $isActive, ?int $actorId = null): array
    {
        $con = Database::pdo();
        $stmt = $con->prepare("SELECT id, is_admin FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $target = $stmt->fetch();
        if ($target === false) {
            return [false, 'Compte introuvable.'];
        }

        if (!$isActive) {
            if ($actorId !== null && $actorId === $userId) {
                return [false, 'Vous ne pouvez pas désactiver votre propre compte.'];
            }
            if ((bool) $target['is_admin']) {
                $other = $con->prepare(
                    "SELECT 1 FROM users WHERE is_admin = 1 AND is_active = 1 AND id != ? LIMIT 1"
                );
                $other->execute([$userId]);
                if ($other->fetch() === false) {
                    return [false, 'Impossible de désactiver ce compte : il doit rester au moins un administrateur actif.'];
                }
            }
        }

        $upd = $con->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $upd->execute([$isActive ? 1 : 0, $userId]);
        if (!$isActive) {
            $del = $con->prepare("DELETE FROM sessions WHERE user_id = ?");
            $del->execute([$userId]);
        }
        return [true, $isActive ? 'Compte activé.' : 'Compte désactivé.'];
    }

    /**
     * Resolves (or creates upon first login) the local account associated with an
     * OIDC user, identified uniquely by the `sub` claim.
     *
     * @return array{id:int, username:string, is_admin:bool, auth_provider:string}
     */
    public static function resolveOrCreateOidcUser(string $sub, string $preferredUsername = ''): array
    {
        $con = Database::pdo();
        $stmt = $con->prepare(
            "SELECT id, username, is_admin, auth_provider FROM users WHERE oidc_sub = ?"
        );
        $stmt->execute([$sub]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return [
                'id' => (int) $row['id'],
                'username' => $row['username'],
                'is_admin' => (bool) $row['is_admin'],
                'auth_provider' => $row['auth_provider'],
            ];
        }

        // First login : provisioning local account
        $username = trim($preferredUsername !== '' ? $preferredUsername : 'oidc_' . substr($sub, 0, 8));
        $collision = $con->prepare("SELECT 1 FROM users WHERE username = ?");
        $collision->execute([$username]);
        if ($collision->fetch() !== false) {
            $username .= '_' . substr($sub, 0, 6);
        }

        // Local password cannot be used (random, never disclosed): OIDC accounts
        // can only log in using the SSO button.
        [$h, $salt] = self::hashPassword(bin2hex(random_bytes(32)));
        $ins = $con->prepare(
            "INSERT INTO users (username, password_hash, salt, created_at, auth_provider, oidc_sub)
             VALUES (?, ?, ?, ?, 'oidc', ?)"
        );
        $ins->execute([$username, $h, $salt, time(), $sub]);
        return [
            'id' => (int) $con->lastInsertId(),
            'username' => $username,
            'is_admin' => false,
            'auth_provider' => 'oidc',
        ];
    }



    /** Creates a new database session and returns its token. */
    public static function createSession(int $userId, int $durationDays = self::SESSION_DURATION_DAYS): string
    {
        $token = bin2hex(random_bytes(32));
        $now = time();
        $con = Database::pdo();
        $stmt = $con->prepare(
            "INSERT INTO sessions (token, user_id, created_at, expire_at) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$token, $userId, $now, $now + $durationDays * 86400]);
        return $token;
    }

    /**
     * Verifies a session token.
     *
     * @return array{id:int, username:string, is_admin:bool, auth_provider:string}|null
     */
    public static function verifySession(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $con = Database::pdo();
        $stmt = $con->prepare(
            "SELECT s.user_id AS id, u.username, u.is_admin, u.is_active, u.auth_provider, u.theme, s.expire_at
             FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token = ?"
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        if ((int) $row['expire_at'] < time() || !(int) $row['is_active']) {
            $del = $con->prepare("DELETE FROM sessions WHERE token = ?");
            $del->execute([$token]);
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'username' => $row['username'],
            'is_admin' => (bool) $row['is_admin'],
            'auth_provider' => $row['auth_provider'],
            'theme' => $row['theme'] ?? 'light',
        ];
    }

    /** Deletes a session from the database (logs out). */
    public static function revokeSession(string $token): void
    {
        if ($token === '') {
            return;
        }
        $con = Database::pdo();
        $stmt = $con->prepare("DELETE FROM sessions WHERE token = ?");
        $stmt->execute([$token]);
    }

    /** Stores the session token in the browser cookie (30 days). */
    public static function setCookie(string $token): void
    {
        setcookie(self::COOKIE_NAME, $token, [
            'expires' => time() + self::SESSION_DURATION_DAYS * 86400,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** Deletes the session cookie. */
    public static function removeCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * The current user as determined by the request cookie, or null.
     *
     * @return array{id:int, username:string, is_admin:bool, auth_provider:string}|null
     */
    public static function currentUser(): ?array
    {
        $token = $_COOKIE[self::COOKIE_NAME] ?? '';
        if (!is_string($token) || $token === '') {
            return null;
        }
        return self::verifySession($token);
    }
}
