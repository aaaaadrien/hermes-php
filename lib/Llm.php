<?php
/**
 * LLM client
 * ==========================
 * HTTP client for a local LLM (llama.cpp, OpenAI-compatible API).
 *
 * Mirrors the behavior of the Python version:
 *  - reasoning level parameters added to the request body
 *    (both common conventions, unrecognized parameters being
 *    ignored by the server: reasoning_effort + chat_template_kwargs);
 *  - if the server rejects these parameters, retries without them;
 *  - non-streaming for calls with tools, SSE streaming for the
 *    final response without tools.
 */

declare(strict_types=1);

namespace Hermes;

class Llm
{
    /** Recognized reasoning levels (like the Python version). */
    public const LEVELS = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh'];

    private Config $conf;
    private string $base_url;
    private string $api_key;
    private string $model;
    private int $max_tokens;
    private float $temperature;
    private string $reasoningLevel;
    /** @var callable():bool|null */
    private $checkStop;

    /**
     * @param callable():bool|null $checkStop checked during LLM calls
     *        (stop button / closed tab); if true, the transfers are killed
     *        and RequestAbortedException is raised.
     */
    public function __construct(Config $conf, ?callable $checkStop = null)
    {
        $this->conf = $conf;
        $this->checkStop = $checkStop;
        $this->base_url = rtrim($conf->get('llm', 'base_url'), '/');
        $this->api_key = $conf->get('llm', 'api_key', '');
        $this->model = $conf->get('llm', 'model');
        $this->max_tokens = $conf->getint('llm', 'max_tokens', 2048);
        $this->temperature = $conf->getfloat('llm', 'temperature', 0.7);
        $this->reasoningLevel = strtolower(trim($conf->get('llm', 'raisonnement', '')));
    }

    public function infos(): array
    {
        return [
            'model' => $this->model,
            'base_url' => $this->base_url,
            'whisper_url' => $this->conf->get('whisper', 'base_url'),
            'temperature' => $this->temperature,
            'max_tokens' => $this->max_tokens,
        ];
    }

    /**
     * Reasoning level parameters to attach to the request body,
     * according to [llm] reasoning (fixed value) or the user's choice
     * (mode "user"). Returns [] if no level is configured.
     *
     * @return array<string,mixed>
     */
    public function extraReasoning(?string $niveauUser = null): array
    {
        $niveau = $this->reasoningLevel === 'user'
            ? ($niveauUser ?? 'medium')
            : $this->reasoningLevel;
        if (!in_array($niveau, self::LEVELS, true)) {
            return [];
        }
        // gpt-oss-style chat templates only accept low / medium / high:
        // levels outside this range are clamped to the nearest one.
        $effort_template = ['none' => 'low', 'minimal' => 'low', 'xhigh' => 'high'][$niveau] ?? $niveau;
        return [
            'reasoning_effort' => $niveau,
            'chat_template_kwargs' => [
                'enable_thinking' => $niveau !== 'none',
                'reasoning_effort' => $effort_template,
            ],
        ];
    }

    /**
     * Non-streaming call (with or without tools).
     *
     * @param list<array<string,mixed>> $messages
     * @param list<array<string,mixed>>|null $outils
     * @param mixed $tool_choice "auto", null, or {"type":"function","function":{"name":...}}
     * @return array<string,mixed> complete message (choices[0].message)
     */
    public function completion(
        array $messages,
        ?array $outils = null,
        $tool_choice = 'auto',
        ?string $userLevel = null,
    ): array {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $this->max_tokens,
            'temperature' => $this->temperature,
            'stream' => false,
        ];
        if ($outils !== null) {
            $payload['tools'] = $outils;
            if ($tool_choice !== null) {
                $payload['tool_choice'] = $tool_choice;
            }
        }

        $extra = $this->extraReasoning($userLevel);
        if ($extra !== []) {
            $payload = array_merge($payload, $extra);
            try {
                return $this->create($payload);
            } catch (RequestAbortedException $e) {
                throw $e; // Stop by the user: no new attempt.
            } catch (\Throwable) {
                // The server rejects these parameters: retry without them (rather than crashing).
            }
        }
        return $this->create($payload);
    }

    /**
     * SSE streaming call (final response without tools).
     *
     * @param list<array<string,mixed>> $messages
     * @param callable(string):void $onDelta called for each text fragment
     * @return string complete assembled text
     */
    public function completion_stream(
        array $messages,
        callable $onDelta,
        ?string $niveauUser = null,
    ): string {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $this->max_tokens,
            'temperature' => $this->temperature,
            'stream' => true,
        ];
        $extra = $this->extraReasoning($niveauUser);
        if ($extra !== []) {
            $payload = array_merge($payload, $extra);
            try {
                return $this->stream($payload, $onDelta);
            } catch (RequestAbortedException $e) {
                throw $e; // Stop by the user: no new attempt.
            } catch (\Throwable) {
                // The server rejects these parameters: retry without them.
                $payload = [
                    'model' => $this->model,
                    'messages' => $messages,
                    'max_tokens' => $this->max_tokens,
                    'temperature' => $this->temperature,
                    'stream' => true,
                ];
            }
        }
        return $this->stream($payload, $onDelta);
    }

    /** Non-streaming POST /chat/completions, returns choices[0].message. */
    private function create(array $payload): array
    {
        $timeout = $this->conf->getint('llm', 'timeout', 600);
        [$status, $decoded, $raw] = Http::request(
            'POST',
            $this->base_url . '/chat/completions',
            $payload,
            $timeout,
            $this->api_key !== '' ? ['Authorization' => 'Bearer ' . $this->api_key] : [],
            null,
            $this->checkStop,
        );
        if (!is_array($decoded) || empty($decoded['choices'])) {
            $detail = is_array($decoded) && isset($decoded['error']['message'])
                ? (string) $decoded['error']['message']
                : mb_substr($raw, 0, 300);
            throw new \RuntimeException("Erreur LLM (HTTP $status) : $detail");
        }
        $message = $decoded['choices'][0]['message'] ?? null;
        if (!is_array($message)) {
            throw new \RuntimeException('Réponse LLM malformée (pas de message).');
        }
        return $message;
    }

    /** POST /chat/completions streaming SSE. */
    private function stream(array $payload, callable $onDelta): string
    {
        $timeout = $this->conf->getint('llm', 'timeout', 600);
        return Http::streamSse(
            $this->base_url . '/chat/completions',
            $this->api_key,
            $payload,
            $timeout,
            $onDelta,
            $this->checkStop,
        );
    }
}
