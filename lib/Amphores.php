<?php
/**
 * Amphores (system contexts)
 * ============================================
 * An “amphore” (amphora) is a reusable system context: a system prompt
 * that guides the agent’s behavior (tone, role, constraints, etc.).
 *
 * Two levels (like the Python version):
 *  - global: visible to everyone (amphores_global tables);
 *  - personal: private, created by the current user (amphores_perso).
 *
 * Identifiers are v4 UUIDs; a personal amphora has a different ID
 * from any global amphora (no collisions possible).

Translated with DeepL.com (free version)
 */

declare(strict_types=1);

namespace Hermes;

class Amphores
{
    /** Generates an amphora identifier (v4 UUID, without hyphens). */
    public static function generateId(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return bin2hex($b);
    }

    /**
     * Lists all amphorae accessible to a user:
     * global amphorae + the user's own, sorted by name.
     *
     * @return list<array<string,mixed>>
     */
    public static function listFor(int $user_id): array
    {
        $con = Database::pdo();
        $globales = $con->query(
            "SELECT id, nom, description, system_prompt, created_at,
                    'global' AS niveau
             FROM amphores_global ORDER BY nom ASC"
        )->fetchAll();
        $perso = $con->prepare(
            "SELECT id, nom, description, system_prompt, created_at,
                    'perso' AS niveau
             FROM amphores_perso WHERE user_id = ? ORDER BY nom ASC"
        );
        $perso->execute([$user_id]);
        $perso = $perso->fetchAll();

        $toutes = array_merge($globales, $perso);
        usort($toutes, fn ($a, $b) => strcasecmp((string) $a['nom'], (string) $b['nom']));
        return array_map(fn ($a) => [
            'id' => $a['id'],
            'nom' => $a['nom'],
            'description' => $a['description'] ?? '',
            'system_prompt' => $a['system_prompt'],
            'niveau' => $a['niveau'],
            'created_at' => (int) $a['created_at'],
        ], $toutes);
    }

    /**
     * Creates an amphora (global if $est_global, otherwise personal).
     *
     * @return array{id:string, nom:string}
     */
    public static function create(string $nom, string $systemPrompt, ?string $description, int $userId, bool $isGlobal = false): array
    {
        $nom = trim($nom);
        $system_prompt = trim($system_prompt);
        if ($nom === '' || $system_prompt === '') {
            throw new \InvalidArgumentException('Le nom et le prompt système sont obligatoires.');
        }
        $id = self::generateId();
        $con = Database::pdo();
        if ($est_global) {
            $stmt = $con->prepare(
                "INSERT INTO amphores_global (id, nom, description, system_prompt, created_at)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$id, $nom, $description, $system_prompt, time()]);
        } else {
            $stmt = $con->prepare(
                "INSERT INTO amphores_perso (id, user_id, nom, description, system_prompt, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$id, $user_id, $nom, $description, $system_prompt, time()]);
        }
        return ['id' => $id, 'nom' => $nom];
    }

    /**
     * Updates an amphora. A user can only edit their own
     * personal amphoras; global amphoras are reserved for administrators.
     * Returns [success, message].
     *
     * @return array{0:bool, 1:string}
     */
    public static function update(
        string $id,
        string $nom,
        string $systemPrompt,
        ?string $description,
        int $userId,
        bool $isAdmin,
    ): array {
        $nom = trim($nom);
        $systemPrompt = trim($systemPrompt);
        if ($nom === '' || $systemPrompt === '') {
            return [false, 'Le nom et le prompt système sont obligatoires.'];
        }
        $con = Database::pdo();

        $stmt = $con->prepare("SELECT 1 FROM amphores_perso WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        if ($stmt->fetch() !== false) {
            $upd = $con->prepare(
                "UPDATE amphores_perso SET nom = ?, description = ?, system_prompt = ? WHERE id = ?"
            );
            $upd->execute([$nom, $description, $systemPrompt, $id]);
            return [true, 'Amphore mise à jour.'];
        }

        $stmt = $con->prepare("SELECT 1 FROM amphores_global WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetch() !== false) {
            if (!$isAdmin) {
                return [false, 'Seul un administrateur peut modifier une amphore globale.'];
            }
            $upd = $con->prepare(
                "UPDATE amphores_global SET nom = ?, description = ?, system_prompt = ? WHERE id = ?"
            );
            $upd->execute([$nom, $description, $systemPrompt, $id]);
            return [true, 'Amphore globale mise à jour.'];
        }

        return [false, 'Amphore introuvable.'];
    }

    /**
     * Deletes an amphora. A user can only delete their own amphoras
     * personal amphoras; global amphoras can only be deleted by an administrator.
     * Returns [success, message].
     *
     * @return array{0:bool, 1:string}
     */
    public static function delete(string $id, int $userId, bool $isAdmin): array
    {
        $con = Database::pdo();

        $stmt = $con->prepare("SELECT 1 FROM amphores_perso WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        if ($stmt->fetch() !== false) {
            $del = $con->prepare("DELETE FROM amphores_perso WHERE id = ?");
            $del->execute([$id]);
            return [true, 'Amphore supprimée.'];
        }

        $stmt = $con->prepare("SELECT 1 FROM amphores_global WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetch() !== false) {
            if (!$isAdmin) {
                return [false, 'Seul un administrateur peut supprimer une amphore globale.'];
            }
            $del = $con->prepare("DELETE FROM amphores_global WHERE id = ?");
            $del->execute([$id]);
            return [true, 'Amphore globale supprimée.'];
        }

        return [false, 'Amphore introuvable.'];
    }

    /**
     * Retrieves the system prompt from an amphora accessible to the user
     * (global or user-specific), or null if not found.
     */
    public static function systemPrompt(string $id, int $userId): ?string
    {
        if ($id === '') {
            return null;
        }
        $con = Database::pdo();
        $stmt = $con->prepare("SELECT system_prompt FROM amphores_global WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return $row['system_prompt'];
        }
        $stmt = $con->prepare("SELECT system_prompt FROM amphores_perso WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row['system_prompt'];
    }
}
