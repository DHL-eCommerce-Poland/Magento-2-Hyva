<?php

declare(strict_types=1);

namespace Hyva\Checkout\Model\Magewire\Component\Evaluation;

/**
 * Stub class for Hyvä Checkout Success evaluation result.
 */
class Success extends EvaluationResult
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
     * @param array $details
     * @return static
     */
    public function withDetails(array $details): static
    {
        return $this;
    }
}
