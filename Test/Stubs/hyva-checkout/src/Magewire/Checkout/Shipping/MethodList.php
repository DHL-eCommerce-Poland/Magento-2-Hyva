<?php

declare(strict_types=1);

namespace Hyva\Checkout\Magewire\Checkout\Shipping;

use Hyva\Checkout\Model\Magewire\Component\EvaluationInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use Magewirephp\Magewire\Component;

/**
 * Stub class for Hyvä Checkout shipping MethodList component.
 */
class MethodList extends Component implements EvaluationInterface
{
    /**
     * @var string|null
     */
    public ?string $method = null;

    /**
     * @param EvaluationResultFactory $resultFactory
     * @return EvaluationResultInterface
     */
    public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResultInterface
    {
        return $resultFactory->createSuccess();
    }
}
