<?php

declare(strict_types=1);

namespace DhlPoland\HyvaDhl\Plugin\Hyva\Checkout\Magewire;

use DhlPoland\Dhl\Model\Carrier;
use DhlPoland\Dhl\Model\MethodCatalog;
use DhlPoland\Dhl\Model\ServicePoint\ServicePointValidator;
use DhlPoland\Dhl\Model\ServicePoint\ServicePointValueObject;
use DhlPoland\HyvaDhl\Model\ServicePointAttributes;
use Hyva\Checkout\Magewire\Checkout\Shipping\MethodList;
use Hyva\Checkout\Model\Magewire\Component\Evaluation\EvaluationResult;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
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
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CheckoutSession $sessionCheckout,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly ServicePointValidator $servicePointValidator,
        private readonly MethodCatalog $methodCatalog,
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
        EvaluationResult $result,
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
            $servicePoint = $this->buildServicePointFromAddress($shippingAddress);

            $this->servicePointValidator->validate(Carrier::CODE, $cleanMethod, $servicePoint);
        } catch (LocalizedException $exception) {
            return $resultFactory->createErrorMessageEvent()
                ->withCustomEvent(ServicePointAttributes::EVENT_SHIPPING_METHOD_ERROR)
                ->withMessage($exception->getMessage());
        } catch (Throwable $exception) {
            $this->logger->error(
                'DHL Hyvä: Unexpected error during MethodList completion evaluation: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
            return $resultFactory->createErrorMessageEvent()
                ->withCustomEvent(ServicePointAttributes::EVENT_SHIPPING_METHOD_ERROR)
                ->withMessage((string) __('Please select a DHL pickup point on the map.'));
        }

        return $result;
    }

    /**
     * Intercept updatedMethod to clean up service point data when switching delivery methods.
     *
     * @param MethodList $subject
     * @param string $value
     *
     * @return string
     */
    public function afterUpdatedMethod(MethodList $subject, string $value): string
    {
        $this->resetQuoteServicePoint();

        return $value;
    }

    /**
     * Reset service point data on active quote shipping address.
     *
     * @return void
     */
    private function resetQuoteServicePoint(): void
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

            $pointId = (string) ($shippingAddress->getData(ServicePointAttributes::FIELD_SERVICE_POINT_ID) ?? '');
            if ($pointId === '') {
                return;
            }

            foreach (ServicePointAttributes::ALL_FIELDS as $field) {
                $defaultValue = str_starts_with($field, 'dhl_is_') ? 0 : null;
                $shippingAddress->setData($field, $defaultValue);
            }

            $extAttributes = $shippingAddress->getExtensionAttributes();
            if ($extAttributes !== null && method_exists($extAttributes, 'setDhlServicePointId')) {
                $extAttributes->setDhlServicePointId(null);
                $extAttributes->setDhlServicePointName(null);
                $extAttributes->setDhlServicePointStreet(null);
                $extAttributes->setDhlServicePointPostcode(null);
                $extAttributes->setDhlServicePointCity(null);
                $extAttributes->setDhlIsPackstation(0);
                $extAttributes->setDhlIsPostfiliale(0);
                $extAttributes->setDhlPostnummer(null);
            }

            $this->quoteRepository->save($quote);
        } catch (Throwable $exception) {
            $this->logger->warning(
                'DHL Hyvä: Error resetting service point on method switch: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
        }
    }

    /**
     * Construct ServicePointValueObject from address data.
     *
     * @param mixed $shippingAddress
     *
     * @return ServicePointValueObject|null
     */
    private function buildServicePointFromAddress(mixed $shippingAddress): ?ServicePointValueObject
    {
        if (!$shippingAddress || !is_object($shippingAddress)) {
            return null;
        }

        $sap = trim((string) ($shippingAddress->getData(ServicePointAttributes::FIELD_SERVICE_POINT_ID) ?? ''));
        if ($sap === '') {
            return null;
        }

        $name = trim((string) ($shippingAddress->getData(ServicePointAttributes::FIELD_SERVICE_POINT_NAME) ?? ''));
        $street = (string) ($shippingAddress->getData(ServicePointAttributes::FIELD_SERVICE_POINT_STREET) ?? '');
        $postcode = (string) ($shippingAddress->getData(ServicePointAttributes::FIELD_SERVICE_POINT_POSTCODE) ?? '');
        $city = (string) ($shippingAddress->getData(ServicePointAttributes::FIELD_SERVICE_POINT_CITY) ?? '');
        $isPackstation = (bool) ($shippingAddress->getData(ServicePointAttributes::FIELD_IS_PACKSTATION) ?? false);
        $isPostfiliale = (bool) ($shippingAddress->getData(ServicePointAttributes::FIELD_IS_POSTFILIALE) ?? false);
        $postnummer = (string) ($shippingAddress->getData(ServicePointAttributes::FIELD_POSTNUMMER) ?? '');
        $country = strtoupper((string) ($shippingAddress->getCountryId() ?? ServicePointAttributes::DEFAULT_COUNTRY));

        return new ServicePointValueObject(
            $sap,
            $name !== '' ? $name : $sap,
            $street,
            $postcode,
            $city,
            $isPackstation,
            $isPostfiliale,
            $postnummer !== '' ? $postnummer : null,
            $country
        );
    }
}
