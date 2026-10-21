<?php

declare(strict_types=1);

namespace DhlPoland\HyvaDhl\Plugin\Hyva\Checkout\Magewire;

use DhlPoland\Dhl\Model\Carrier;
use DhlPoland\Dhl\Model\MethodCatalog;
use DhlPoland\Dhl\Model\ServicePoint\ServicePointValidator;
use DhlPoland\HyvaDhl\Magewire\Checkout\Shipping\Method\PointSelector;
use DhlPoland\HyvaDhl\Model\Checkout\ServicePointSessionRegistry;
use Hyva\Checkout\Magewire\Checkout\Shipping\MethodList;
use Hyva\Checkout\Model\Magewire\Component\Evaluation\EvaluationResult;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Plugin on Hyvä Checkout Shipping MethodList to intercept step completion and method change.
 */
class ShippingMethodListPlugin
{
    /**
     * @param CheckoutSession $sessionCheckout
     * @param CartRepositoryInterface $quoteRepository
     * @param ServicePointValidator $servicePointValidator
     * @param MethodCatalog $methodCatalog
     * @param ServicePointSessionRegistry $sessionRegistry
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CheckoutSession $sessionCheckout,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly ServicePointValidator $servicePointValidator,
        private readonly MethodCatalog $methodCatalog,
        private readonly ServicePointSessionRegistry $sessionRegistry,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Intercept evaluateCompletion to ensure required DHL pickup point is selected.
     *
     * @param MethodList $subject
     * @param EvaluationResult $result
     * @param EvaluationResultFactory $resultFactory
     *
     * @return EvaluationResult
     */
    public function afterEvaluateCompletion(
        MethodList $subject,
        EvaluationResultInterface $result,
        EvaluationResultFactory $resultFactory
    ): EvaluationResult {
        $selectedMethod = (string) $subject->method;
        if (!str_starts_with($selectedMethod, Carrier::CODE_PREFIX)) {
            return $result;
        }

        $cleanMethod = substr($selectedMethod, strlen(Carrier::CODE_PREFIX));
        if (!$this->methodCatalog->isServicePointMethod($cleanMethod)) {
            return $result;
        }

        try {
            $quote = $this->sessionCheckout->getQuote();
            $shippingAddress = $quote?->getShippingAddress();
            $servicePoint = $this->sessionRegistry->extractFromAddress($shippingAddress);

            $this->servicePointValidator->validate(Carrier::CODE, $cleanMethod, $servicePoint);
        } catch (LocalizedException $exception) {
            return $resultFactory->createErrorMessageEvent()
                ->withCustomEvent(PointSelector::EVENT_SHIPPING_METHOD_ERROR)
                ->withMessage($exception->getMessage());
        } catch (Throwable $exception) {
            $this->logger->error(
                'DHL Hyvä: Unexpected error during MethodList completion evaluation: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
            return $resultFactory->createErrorMessageEvent()
                ->withCustomEvent(PointSelector::EVENT_SHIPPING_METHOD_ERROR)
                ->withMessage((string) __('Please select a DHL pickup point on the map.'));
        }

        return $result;
    }

    /**
     * Intercept updatedMethod to clean up or restore service point data when switching delivery methods.
     *
     * @param MethodList $subject
     * @param string $value
     *
     * @return string
     */
    public function afterUpdatedMethod(MethodList $subject, string $value): string
    {
        $this->syncServicePointForMethod($value);

        return $value;
    }

    /**
     * Synchronize service point data on quote shipping address for the newly selected method.
     *
     * @param string $methodCode
     *
     * @return void
     */
    private function syncServicePointForMethod(string $methodCode): void
    {
        try {
            $quote = $this->sessionCheckout->getQuote();
            if (!$quote) {
                return;
            }

            $shippingAddress = $quote->getShippingAddress();
            if (!$shippingAddress) {
                return;
            }

            $cleanMethod = $methodCode;
            if (str_starts_with($cleanMethod, Carrier::CODE_PREFIX)) {
                $cleanMethod = substr($cleanMethod, strlen(Carrier::CODE_PREFIX));
            }

            $isPointMethod = $this->methodCatalog->isServicePointMethod($cleanMethod);
            $countryId = $shippingAddress->getCountryId();
            $destinationCountry = (string) ($countryId ?: ServicePointSessionRegistry::DEFAULT_COUNTRY);

            if ($isPointMethod) {
                $savedPoint = $this->sessionRegistry->getPointForMethod($methodCode, $destinationCountry);
                if ($savedPoint !== null && $savedPoint->isValid()) {
                    $this->sessionRegistry->applyToAddress($shippingAddress, $savedPoint);
                    $this->quoteRepository->save($quote);
                    return;
                }
            }

            if ($this->sessionRegistry->clearAddressData($shippingAddress)) {
                $this->quoteRepository->save($quote);
            }
        } catch (Throwable $exception) {
            $this->logger->warning(
                'DHL Hyvä: Error syncing service point on method switch: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
        }
    }
}
