<?php

declare(strict_types=1);

namespace Hyva\Checkout\Model\Magewire\Component;

/**
 * Stub interface for Hyvä Checkout EvaluationInterface.
 */
interface EvaluationInterface
{
    /**
     * @param EvaluationResultFactory $resultFactory
     * @return EvaluationResultInterface
     */
    public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResultInterface;
}
