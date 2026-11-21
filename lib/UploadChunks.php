<?php
/**
 * Chunked file upload
 * =============================================
 * Splits and reassembles large files to work around
 * PHP limits (upload_max_filesize, post_max_size).
 *
 * Flow:
 *  1. Frontend sends the chunks via POST /api/upload/chunk
 *  2. Backend stores each chunk in a temporary directory
 *  3. Frontend calls POST /api/upload/assemble to reassemble
 *  4. Backend reconstructs the original file and returns its path
 */

declare(strict_types=1);

namespace Hermes;

class UploadChunks
{
    /** Default chunk size in bytes (1 MB). */
    public const DEFAULT_CHUNK_SIZE = 1048576;

    /** Upload expiration delay in seconds (1 hour). */
    public const EXPIRATION = 3600;

    /** Chunk storage directory (relative to the project root). */
    public const CHUNKS_DIR = 'data/chunks';

    /**
     * Receives a chunk and stores it.
     *
     * @param string $uploadId Unique upload identifier.
     * @param int    $index    Chunk index (0-based).
     * @param string $nom      Original name of the file.
     * @param string $mime     MIME type of the file.
     * @param int    $taille   Expected total size of the file.
     * @return array{status:string, message?:string, uploadId?:string, totalChunks?:int, chunksRecus?:int}
     */
    public static function receiveChunk(
        string $uploadId,
        int $index,
        string $nom,
        string $mime,
        int $taille,
        string $donneesChunk
    ): array {
        $directory = self::verifyDirectory();
        $uploadDir = $directory . '/' . self::cleanIdentifier($uploadId);

        // Create the directory if it does not exist
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0700, true);
        }

        // Store the metadata
        $metaFile = $uploadDir . '/.meta.json';
        if (!file_exists($metaFile)) {
            file_put_contents($metaFile, json_encode([
                'upload_id' => $uploadId,
                'nom' => $nom,
                'mime' => $mime,
                'taille' => $taille,
                'chunks_total' => max(1, (int) ceil($taille / self::DEFAULT_CHUNK_SIZE)),
                'date_creation' => time(),
            ], JSON_UNESCAPED_SLASHES));
        }

        // Store the chunk
        $chunkPath = $uploadDir . '/chunk_' . str_pad((string) $index, 6, '0', STR_PAD_LEFT);
        $result = file_put_contents($chunkPath, $donneesChunk, LOCK_EX);
        if ($result === false || $result !== strlen($donneesChunk)) {
            @unlink($chunkPath);
            return [
                'status' => 'erreur',
                'message' => "Erreur écriture chunk $index (attendu " . strlen($donneesChunk) . " octets).",
            ];
        }

        // Count the received chunks
        $chunksReceived = self::countChunks($uploadDir);

        return [
            'status' => 'ok',
            'uploadId' => $uploadId,
            'totalChunks' => max(1, (int) ceil($taille / self::DEFAULT_CHUNK_SIZE)),
            'chunksRecus' => $chunksReceived,
        ];
    }

    /**
     * Reassembles all the chunks into a single file.
     *
     * @return array{status:string, message?:string, chemin?:string, nom?:string, mime?:string, taille?:int}
     */
    public static function reassemble(string $uploadId): array
    {
        $directory = self::verifyDirectory();
        $uploadDir = $directory . '/' . self::cleanIdentifier($uploadId);

        if (!is_dir($uploadDir)) {
            return ['status' => 'erreur', 'message' => 'Upload introuvable.'];
        }

        // Check the metadata
        $metaFile = $uploadDir . '/.meta.json';
        if (!file_exists($metaFile)) {
            return ['status' => 'erreur', 'message' => 'Métadonnées d\'upload manquantes.'];
        }

        $meta = json_decode((string) file_get_contents($metaFile), true);
        if ($meta === null) {
            return ['status' => 'erreur', 'message' => 'Métadonnées corrompues.'];
        }

        // Check that all chunks are present
        $totalChunks = $meta['chunks_total'] ?? 0;
        $chunksReceived = self::countChunks($uploadDir);

        if ($chunksReceived < $totalChunks) {
            return [
                'status' => 'erreur',
                'message' => "Upload incomplet : $chunksReceived/$totalChunks chunks reçus.",
            ];
        }

        // Reassemble the chunks in order
        $finalFile = $uploadDir . '/' . $meta['nom'];
        @unlink($finalFile);

        $fpFinal = fopen($finalFile, 'wb');
        if ($fpFinal === false) {
            return ['status' => 'erreur', 'message' => 'Impossible de créer le fichier final.'];
        }

        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = $uploadDir . '/chunk_' . str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            if (!file_exists($chunkPath)) {
                fclose($fpFinal);
                return ['status' => 'erreur', 'message' => "Chunk $i introuvable lors de l'assemblage."];
            }
            $fpChunk = fopen($chunkPath, 'rb');
            if ($fpChunk === false) {
                fclose($fpFinal);
                return ['status' => 'erreur', 'message' => "Impossible d'ouvrir le chunk $i."];
            }
            if (stream_copy_to_stream($fpChunk, $fpFinal) === false) {
                fclose($fpChunk);
                fclose($fpFinal);
                return ['status' => 'erreur', 'message' => "Erreur de lecture du chunk $i."];
            }
            fclose($fpChunk);
            @unlink($chunkPath);
        }

        fclose($fpFinal);

        // Check the size
        $actualSize = filesize($finalFile);
        if ($actualSize !== $meta['taille']) {
            @unlink($finalFile);
            return [
                'status' => 'erreur',
                'message' => "Taille incohérente : attendu {$meta['taille']}, obtenu $actualSize.",
            ];
        }

        return [
            'status' => 'ok',
            'chemin' => $finalFile,
            'nom' => $meta['nom'],
            'mime' => $meta['mime'] ?? 'application/octet-stream',
            'taille' => $actualSize,
        ];
    }

    /**
     * Cleans an upload directory (removes chunks + assembled file + meta).
     */
    public static function clean(string $uploadId): void
    {
        $directory = self::verifyDirectory();
        $uploadDir = $directory . '/' . self::cleanIdentifier($uploadId);

        if (is_dir($uploadDir)) {
            self::deleteDirectory($uploadDir);
        }
    }

    /**
     * Cleans all expired uploads.
     */
    public static function cleanExpired(): void
    {
        $directory = self::verifyDirectory();
        if (!is_dir($directory)) {
            return;
        }

        $directories = scandir($directory);
        if ($directories === false) {
            return;
        }

        foreach ($directories as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }

            $metaFile = $directory . '/' . $dir . '/.meta.json';
            if (!file_exists($metaFile)) {
                continue;
            }

            $meta = json_decode((string) file_get_contents($metaFile), true);
            if ($meta === null) {
                continue;
            }

            $createdAt = $meta['date_creation'] ?? 0;
            if (time() - $createdAt > self::EXPIRATION) {
                self::deleteDirectory($directory . '/' . $dir);
            }
        }
    }

    /**
     * Checks that the chunks directory exists and is accessible.
     */
    private static function verifyDirectory(): string
    {
        $directory = __DIR__ . '/../' . self::CHUNKS_DIR;
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        return $directory;
    }

    /**
     * Counts the number of chunks in an upload directory.
     */
    private static function countChunks(string $uploadDir): int
    {
        $count = 0;
        $entries = scandir($uploadDir);
        if ($entries === false) {
            return 0;
        }
        foreach ($entries as $file) {
            if (str_starts_with($file, 'chunk_') && is_file($uploadDir . '/' . $file)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Cleans an identifier for use in the file system.
     */
    private static function cleanIdentifier(string $id): string
    {
        return substr(preg_replace('/[^a-f0-9]/i', '', $id), 0, 32) ?: bin2hex(random_bytes(16));
    }

    /**
     * Recursively removes a directory and its contents.
     */
    private static function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $fullPath = $path . '/' . $entry;
            if (is_dir($fullPath)) {
                self::deleteDirectory($fullPath);
            } else {
                @unlink($fullPath);
            }
        }

        @rmdir($path);
    }
}
