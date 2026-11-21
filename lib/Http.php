<?php
/**
 * Client HTTP
 * ===========================
 * Curl wrapper for outbound calls (LLM, weather, wiki, Whisper, TTS, etc.).
 * Supports SSE (Server-Sent Events) streaming for LLM responses.
 */

declare(strict_types=1);

namespace Hermes;

class Http
{
    /**
     * Simple HTTP request (JSON). Returns [status, body_decode, raw_body].
     *
     * @param array<string,mixed>|null $json JSON body (POST/PUT)
     * @param callable():bool|null $checkStop if non-null and returns true,
     *        the transfer is aborted (RequestAbortedException)
     * @return array{0:int, 1:mixed, 2:string}
     */
    public static function request(
        string $method,
        string $url,
        ?array $json = null,
        int $timeout = 30,
        array $headers = [],
        ?string $rawBody = null,
        ?callable $checkStop = null,
    ): array {
        $ch = curl_init($url);
        $httpHeaders = ['Accept: application/json'];
        foreach ($headers as $k => $v) {
            $httpHeaders[] = "$k: $v";
        }
        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_FOLLOWLOCATION => true,
        ];
        if ($json !== null) {
            $httpHeaders[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } elseif ($rawBody !== null) {
            $httpHeaders[] = 'Content-Type: application/octet-stream';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
        }
        curl_setopt_array($ch, $options);

        if ($checkStop !== null) {
            // Multi loop: checks for stop periodically (100 ms) even if
            // the server is silent (cURL progress callbacks
            // are not reliable on all builds).
            [$ok, $code] = self::execMulti($ch, $checkStop, 100);
            if (!$ok) {
                if ($code === -1) {
                    throw new RequestAbortedException('Requête arrêtée par l\'utilisateur.');
                }
                throw new \RuntimeException("Erreur réseau vers $url : " . curl_error($ch));
            }
            $raw = curl_multi_getcontent($ch);
        } else {
            $raw = curl_exec($ch);
            if ($raw === false) {
                throw new \RuntimeException("Erreur réseau vers $url : " . curl_error($ch));
            }
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $raw = (string) $raw;

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $decoded = null;
        }
        return [$status, $decoded, $raw];
    }

    /**
     * Sends a file (multipart/form-data) to an endpoint.
     *
     * @param array<string,string> $champs text fields
     * @param array{nom:string, contenu:string, mime:string, fichier:string} $fichier
     * @return array{0:int, 1:mixed, 2:string}
     */
    public static function uploadMultipart(
        string $url,
        array $champs,
        array $fichier,
        int $timeout = 1200,
    ): array {
        $contenu = $fichier['contenu'];
        // If $contenu is an existing file path, use it directly
        if (is_file($contenu)) {
            $tmp = $contenu;
        } else {
            $tmp = tempnam('/var/tmp', 'hermes_up_');
            file_put_contents($tmp, (string) $contenu);
        }

        $ch = curl_init($url);
        $postFields = $champs;
        $postFields[$fichier['fichier']] = new \CURLFile($tmp, $fichier['mime'], $fichier['nom']);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            if (!is_file($tmp)) {
                @unlink($tmp);
            }
            throw new \RuntimeException("Erreur réseau vers $url : $err");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $raw = (string) $raw;
        if (!is_file($tmp)) {
            @unlink($tmp);
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $decoded = null;
        }
        return [$status, $decoded, $raw];
    }

    /**
     * SSE streaming of an LLM request (OpenAI-compatible).
     *
     * Calls $onDelta(string $texte) for each content fragment received.
     * Returns the complete assembled content.
     *
     * @param callable(string):void $onDelta
     * @param callable():bool|null $checkStop if non-null and returns true, the
     *        transfer to the LLM is killed and RequestAbortedException is raised
     *        (it carries the partial text already received)
     * @return string complete content
     */
    public static function streamSse(
        string $url,
        string $apiKey,
        array $payload,
        int $timeout,
        callable $onDelta,
        ?callable $checkStop = null,
    ): string {
        $ch = curl_init($url);
        $buffer = '';
        $complet = '';

        // SSE events are separated by an empty line: the stream is split
        // into complete blocks as it flows (a chunk can cut a block in two).
        $onChunk = function ($ch, string $chunk) use (&$buffer, &$complet, $onDelta): int {
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $bloc = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                foreach (preg_split('/\r?\n/', $bloc) as $ligne) {
                    if (str_starts_with($ligne, 'data:')) {
                        $data = trim(substr($ligne, 5));
                        if ($data === '[DONE]') {
                            continue;
                        }
                        $json = json_decode($data, true);
                        if (is_array($json)) {
                            $delta = $json['choices'][0]['delta']['content']
                                ?? $json['choices'][0]['delta']['reasoning_delta'] ?? null;
                            if (is_string($delta) && $delta !== '') {
                                $complet .= $delta;
                                $onDelta($delta);
                            }
                        }
                    }
                }
            }
            return strlen($chunk);
        };

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'Accept: text/event-stream',
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_WRITEFUNCTION => $onChunk,
        ]);

        if ($checkStop !== null) {
            // Multi loop: the loop pumps the transfer every 100 ms
            // (even if the LLM is silent during its
            // reasoning phase) to check for a stop request.
            [$ok, $code] = self::execMulti($ch, $checkStop, 100);
            if (!$ok) {
                if ($code === -1) {
                    // Voluntary stop: the partial text already received is passed on.
                    throw new RequestAbortedException('Génération arrêtée par l\'utilisateur.', $complet);
                }
                throw new \RuntimeException("Erreur réseau (streaming) vers $url : " . curl_error($ch));
            }
        } else {
            $ok = curl_exec($ch);
            if ($ok === false) {
                throw new \RuntimeException("Erreur réseau (streaming) vers $url : " . curl_error($ch));
            }
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($status >= 400) {
            throw new \RuntimeException("Erreur HTTP $status lors du streaming LLM.");
        }
        return $complet;
    }

    /**
     * Runs the transfer of a cURL handle in a multi-handle loop:
     * the loop "pumps" the transfer every $intervalMs ms (usleep) so
     * that $checkStop can be checked, even when the server is silent
     * (LLM's reasoning phase, non-streaming response waiting).
     *
     * Why not curl_multi_select($mh, $timeout)? On some builds
     * of libcurl, it ignores the timeout when no socket is active and sleeps
     * until the next internal cURL timer (connection timeout, etc.):
     * the stop would then be detected far too late.
     *
     * @param \CurlHandle $ch already-configured handle
     * @param callable():bool|null $checkStop if it returns true, the transfer is
     *        interrupted and [false, -1] is returned
     * @return array{0:bool, 1:int} [success, cURL result code (-1 = stop)]
     */
    private static function execMulti(\CurlHandle $ch, ?callable $checkStop, int $intervalMs): array
    {
        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $ch);
        try {
            while (true) {
                if ($checkStop !== null && $checkStop()) {
                    return [false, -1];
                }
                $mrc = curl_multi_exec($multi, $running);
                if ($mrc !== CURLM_OK) {
                    throw new \RuntimeException('Erreur cURL multi (' . $mrc . ') : ' . curl_multi_strerror($mrc));
                }
                $termine = false;
                $code = 0;
                while (($info = curl_multi_info_read($multi)) !== false) {
                    if ($info['msg'] === CURLMSG_DONE) {
                        $termine = true;
                        $code = (int) $info['result'];
                    }
                }
                if ($termine) {
                    return [$code === 0, $code];
                }
                usleep($intervalMs * 1000);
            }
        } finally {
            curl_multi_remove_handle($multi, $ch);
            curl_multi_close($multi);
        }
    }
}
