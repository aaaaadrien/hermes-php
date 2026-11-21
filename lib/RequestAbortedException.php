<?php
/**
 * Request aborted by the user
 * =====================================================
 * Raised when the client interrupts an in-flight LLM request (stop button
 * /api/stop, or closed tab). Carries the text already generated at the time
 * of the stop: it is saved with a “generation stopped” marker.
 */

declare(strict_types=1);

namespace Hermes;

class RequestAbortedException extends \RuntimeException
{
    private string $partiel;

    public function __construct(string $message, string $partiel = '')
    {
        parent::__construct($message);
        $this->partiel = $partiel;
    }

    /** Text generated before the stop (may be empty). */
    public function getPartiel(): string
    {
        return $this->partiel;
    }
}
