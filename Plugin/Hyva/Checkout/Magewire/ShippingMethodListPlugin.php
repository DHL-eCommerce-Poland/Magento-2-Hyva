<?php

declare(strict_types=1);

namespace DhlPoland\HyvaDhl\Plugin\Hyva\Checkout\Magewire;

use DhlPoland\Dhl\Model\Carrier;
use DhlPoland\Dhl\Model\MethodCatalog;
use DhlPoland\Dhl\Model\ServicePoint\ServicePointValidator;
use DhlPoland\Dhl\Model\ServicePoint\ServicePointValueObject;
use DhlPoland\HyvaDhl\Model\Checkout\ServicePointSessionRegistry;
use DhlPoland\HyvaDhl\Model\ServicePointAttributes;
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
            $destinationCountry = (string) ($countryId ?: ServicePointAttributes::DEFAULT_COUNTRY);

            if ($isPointMethod) {
                $savedPoint = $this->sessionRegistry->getPointForMethod($methodCode, $destinationCountry);
                if ($savedPoint !== null && $savedPoint->isValid()) {
                    $this->applyServicePointToAddress($shippingAddress, $savedPoint);
                    $this->quoteRepository->save($quote);
                    return;
                }
            }

            $this->resetQuoteServicePoint();
        } catch (Throwable $exception) {
            $this->logger->warning(
                'DHL Hyvä: Error syncing service point on method switch: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
        }
    }

    /**
     * Apply ServicePointValueObject attributes to quote shipping address.
     *
     * @param mixed $shippingAddress
     * @param ServicePointValueObject $point
     *
     * @return void
     */
    private function applyServicePointToAddress(mixed $shippingAddress, ServicePointValueObject $point): void
    {
        $shippingAddress->setData(ServicePointAttributes::FIELD_SERVICE_POINT_ID, $point->getId());
        $shippingAddress->setData(ServicePointAttributes::FIELD_SERVICE_POINT_NAME, $point->getName());
        $shippingAddress->setData(ServicePointAttributes::FIELD_SERVICE_POINT_STREET, $point->getStreet());
        $shippingAddress->setData(ServicePointAttributes::FIELD_SERVICE_POINT_POSTCODE, $point->getPostcode());
        $shippingAddress->setData(ServicePointAttributes::FIELD_SERVICE_POINT_CITY, $point->getCity());
        $shippingAddress->setData(ServicePointAttributes::FIELD_IS_PACKSTATION, $point->isPackstation() ? 1 : 0);
        $shippingAddress->setData(ServicePointAttributes::FIELD_IS_POSTFILIALE, $point->isPostfiliale() ? 1 : 0);
        $shippingAddress->setData(ServicePointAttributes::FIELD_POSTNUMMER, $point->getPostnummer());

        $extAttributes = $shippingAddress->getExtensionAttributes();
        if ($extAttributes !== null && method_exists($extAttributes, 'setDhlServicePointId')) {
            $extAttributes->setDhlServicePointId($point->getId());
            $extAttributes->setDhlServicePointName($point->getName());
            $extAttributes->setDhlServicePointStreet($point->getStreet());
            $extAttributes->setDhlServicePointPostcode($point->getPostcode());
            $extAttributes->setDhlServicePointCity($point->getCity());
            $extAttributes->setDhlIsPackstation($point->isPackstation() ? 1 : 0);
            $extAttributes->setDhlIsPostfiliale($point->isPostfiliale() ? 1 : 0);
            $extAttributes->setDhlPostnummer($point->getPostnummer());
        }
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
