<?php
/**
 * Conversations
 * =============================
 * Persistence of conversations and messages for the current user.
 *
 * A conversation belongs to a user and has a title (the first
 * message, truncated). Each message has a role (user / assistant), content,
 * and an optional list of attachments (JSON: name, type, size).
 *
 * The complete history (messages + selected amphora) is sent back to the LLM at
 * each turn.
 */

declare(strict_types=1);

namespace Hermes;

class Conversations
{
    /**
     * Creates a new conversation and returns its ID.
     * $amphore_id: amphora ID or '' (no system context).
     */
    public static function create(int $userId, string $title, string $amphoreId = ''): int
    {
        $title = trim($title) !== '' ? trim($title) : 'Nouvelle conversation';
        $now = time();
        $con = Database::pdo();
        $stmt = $con->prepare(
            "INSERT INTO conversations (user_id, titre, amphore_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$userId, $title, $amphoreId, $now, $now]);
        return (int) $con->lastInsertId();
    }

    /**
     * Lists a user's conversations, with the most recent ones first.
     *
     * @return list<array<string,mixed>>
     */
    public static function list(int $userId): array
    {
        $con = Database::pdo();
        $stmt = $con->prepare(
            "SELECT id, titre, amphore_id, created_at, updated_at,
                    (SELECT COUNT(*) FROM messages WHERE conversation_id = conversations.id) AS nb_messages
             FROM conversations
             WHERE user_id = ?
             ORDER BY updated_at DESC, id DESC"
        );
        $stmt->execute([$userId]);
        return array_map(fn ($row) => [
            'id' => (int) $row['id'],
            'titre' => $row['titre'],
            'amphore_id' => $row['amphore_id'] ?? '',
            'created_at' => (int) $row['created_at'],
            'updated_at' => (int) $row['updated_at'],
            'nb_messages' => (int) $row['nb_messages'],
        ], $stmt->fetchAll());
    }

    /**
     * Returns a conversation along with its messages (if it belongs to
     * the user), or null.
     *
     * @return array{id:int, titre:string, amphore_id:string, messages:list<array<string,mixed>>}|null
     */
    public static function load(int $conversationId, int $userId): ?array
    {
        $con = Database::pdo();
        $stmt = $con->prepare(
            "SELECT id, titre, amphore_id, created_at, updated_at
             FROM conversations WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$conversationId, $userId]);
        $conversation = $stmt->fetch();
        if ($conversation === false) {
            return null;
        }
        $stmt = $con->prepare(
            "SELECT id, role, content, created_at, pieces_jointes, audio_b64, meta
             FROM messages WHERE conversation_id = ? ORDER BY id ASC"
        );
        $stmt->execute([$conversationId]);
        $messages = array_map(function ($row) {
            $pj = json_decode((string) $row['pieces_jointes'], true);
            $meta = json_decode((string) $row['meta'], true);
            return [
                'id' => (int) $row['id'],
                'role' => $row['role'],
                'content' => $row['content'],
                'created_at' => (int) $row['created_at'],
                'pieces_jointes' => is_array($pj) ? $pj : [],
                'audio_b64' => $row['audio_b64'] !== null ? (string) $row['audio_b64'] : null,
                'meta' => is_array($meta) ? $meta : [],
            ];
        }, $stmt->fetchAll());

        return [
            'id' => (int) $conversation['id'],
            'titre' => $conversation['titre'],
            'amphore_id' => $conversation['amphore_id'] ?? '',
            'messages' => $messages,
        ];
    }

    /**
     * Adds a message to a conversation (from the user only) and
     * updates its date. If this is the first message, the title becomes the
     * beginning of the message (truncated to 40 characters).
     *
     * @param list<array{nom:string, type:string, taille:int}> $pieces_jointes
     */
    public static function addMessage(int $conversationId, int $userId, string $role, string $content, array $attachments = [], string $audioB64 = '', string $metadata = ''): void
    {
        $con = Database::pdo();
        $now = time();
        $pj = $attachments === [] ? null : json_encode($attachments, JSON_UNESCAPED_UNICODE);
        $audioB64 = $audioB64 !== '' ? $audioB64 : null;
        $metadata = $metadata !== '' ? $metadata : null;

        $stmt = $con->prepare(
            "INSERT INTO messages (conversation_id, role, content, created_at, pieces_jointes, audio_b64, meta)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$conversationId, $role, $content, $now, $pj, $audioB64, $metadata]);

        if ($role === 'user') {
            $title = mb_strimwidth($content, 0, 40, '', 'UTF-8');
            $stmt = $con->prepare(
                "UPDATE conversations
                 SET updated_at = ?, titre = CASE WHEN titre = 'Nouvelle conversation' THEN ? ELSE titre END
                 WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$now, $title, $conversationId, $userId]);
        } else {
            $stmt = $con->prepare("UPDATE conversations SET updated_at = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$now, $conversationId, $userId]);
        }
    }

    /**
     * Deletes a conversation (and its messages) from the user.
     * Returns [success, message].
     *
     * @return array{0:bool, 1:string}
     */
    public static function delete(int $conversationId, int $userId): array
    {
        $con = Database::pdo();
        $stmt = $con->prepare("DELETE FROM conversations WHERE id = ? AND user_id = ?");
        $stmt->execute([$conversationId, $userId]);
        return $stmt->rowCount() > 0
            ? [true, 'Conversation supprimée.']
            : [false, 'Conversation introuvable.'];
    }

    /**
     * Changes the amphora (system context) of a conversation.
     *
     * @return array{0:bool, 1:string}
     */
    public static function changeAmphora(int $conversationId, int $userId, string $amphoreId): array
    {
        $con = Database::pdo();
        $stmt = $con->prepare(
            "UPDATE conversations SET amphore_id = ?, updated_at = ? WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$amphoreId, time(), $conversationId, $userId]);
        return $stmt->rowCount() > 0
            ? [true, 'Contexte système mis à jour.']
            : [false, 'Conversation introuvable.'];
    }

    public static function changeTitle(int $conversationId, int $userId, string $newTitle): array
    {
        $con = Database::pdo();
        $stmt = $con->prepare(
            "UPDATE conversations SET titre = ?, updated_at = ? WHERE id = ? AND user_id = ?"
        );
        $stmt->execute([$newTitle, time(), $conversationId, $userId]);
        return $stmt->rowCount() > 0
            ? [true, 'Conversation renommée.']
            : [false, 'Conversation introuvable.'];
    }
}
