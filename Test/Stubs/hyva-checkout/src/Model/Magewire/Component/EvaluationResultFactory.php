<?php

declare(strict_types=1);

namespace Hyva\Checkout\Model\Magewire\Component;

use Hyva\Checkout\Model\Magewire\Component\Evaluation\ErrorEventMessage;
use Hyva\Checkout\Model\Magewire\Component\Evaluation\Success;

/**
 * Stub class for Hyvä Checkout EvaluationResultFactory.
 */
class EvaluationResultFactory
{
    /**
     * @param array $details
     * @param string|null $event
     * @return Success
     */
    public function createSuccess(array $details = [], ?string $event = null): Success
    {
        $result = new Success();
        if ($event !== null) {
            $result->withCustomEvent($event);
        }
        if (!empty($details)) {
            $result->withDetails($details);
        }
        return $result;
    }

    /**
     * @return ErrorEventMessage
     */
    public function createErrorMessageEvent(): ErrorEventMessage
    {
        return new ErrorEventMessage();
    }
}
