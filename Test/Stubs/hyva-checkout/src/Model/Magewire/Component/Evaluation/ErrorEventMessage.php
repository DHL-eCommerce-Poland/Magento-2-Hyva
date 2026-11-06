<?php

declare(strict_types=1);

namespace Hyva\Checkout\Model\Magewire\Component\Evaluation;

/**
 * Stub class for Hyvä Checkout ErrorEventMessage evaluation result.
 */
class ErrorEventMessage extends EvaluationResult
{
    /**
     * @param string $event
     * @return static
     */
    public function withCustomEvent(string $event): static
    {
        return $this;
    }

    /**
     * @param string $message
     * @return static
     */
    public function withMessage(string $message): static
    {
        return $this;
    }
}
