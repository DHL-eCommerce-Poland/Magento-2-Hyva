<?php

declare(strict_types=1);

namespace DhlPoland\HyvaDhl\Magewire\Checkout\Shipping\Method;

use DhlPoland\Dhl\Model\Carrier;
use DhlPoland\Dhl\Model\MethodCatalog;
use DhlPoland\Dhl\Model\ServicePoint\ServicePointValidator;
use DhlPoland\Dhl\Model\ServicePoint\ServicePointValueObject;
use DhlPoland\HyvaDhl\Model\ServicePointAttributes;
use Hyva\Checkout\Model\Magewire\Component\Evaluation\EvaluationResult;
use Hyva\Checkout\Model\Magewire\Component\EvaluationInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magewirephp\Magewire\Component;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Magewire component managing DHL pickup point selection and validation on Hyvä Checkout.
 */
class PointSelector extends Component implements EvaluationInterface
{
    /**
     * @var array
     */
    protected $listeners = [
        'shipping_method_selected' => 'syncFromQuote',
    ];

    /**
     * @var string|null
     */
    public ?string $pointId = null;

    /**
     * @var string|null
     */
    public ?string $pointName = null;

    /**
     * @var string|null
     */
    public ?string $pointStreet = null;

    /**
     * @var string|null
     */
    public ?string $pointPostcode = null;

    /**
     * @var string|null
     */
    public ?string $pointCity = null;

    /**
     * @var bool
     */
    public bool $isPackstation = false;

    /**
     * @var bool
     */
    public bool $isPostfiliale = false;

    /**
     * @var string|null
     */
    public ?string $postnummer = null;

    /**
     * @var string|null
     */
    public ?string $country = null;

    /**
     * @var string|null
     */
    public ?string $methodCode = null;

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
     * Mount component and populate state from active quote.
     *
     * @return void
     */
    public function mount(): void
    {
        $this->syncFromQuote();
    }

    /**
     * Boot component lifecycle hook.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->syncFromQuote();
    }

    /**
     * Handle DHL pickup point selection triggered from frontend iframe/postMessage.
     *
     * @param array $pointData
     *
     * @return void
     */
    public function selectPoint(array $pointData): void
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

            $sap = trim((string) ($pointData['sap'] ?? ''));
            if ($sap === '') {
                return;
            }

            $name = trim((string) ($pointData['name'] ?? ''));
            $street = trim((string) ($pointData['street'] ?? ''));
            $streetNo = trim((string) ($pointData['streetNo'] ?? $pointData['houseNo'] ?? ''));
            if ($streetNo !== '' && !str_contains($street, $streetNo)) {
                $street = trim($street . ' ' . $streetNo);
            }

            $postcode = trim((string) ($pointData['zip'] ?? $pointData['postcode'] ?? $pointData['postalCode'] ?? ''));
            $city = trim((string) ($pointData['city'] ?? ''));

            $fullMethodCode = (string) ($this->methodCode ?: $shippingAddress->getShippingMethod());
            $cleanMethodCode = strtolower(trim($fullMethodCode));
            if (str_starts_with($cleanMethodCode, Carrier::CODE_PREFIX)) {
                $cleanMethodCode = substr($cleanMethodCode, strlen(Carrier::CODE_PREFIX));
            }

            $isPackstation = (bool) ($pointData['isPackstation'] ?? false)
                || $this->methodCatalog->isPackstationMethod($cleanMethodCode);
            $isPostfiliale = (bool) ($pointData['isPostfiliale'] ?? false)
                || $this->methodCatalog->isParcelShopMethod($cleanMethodCode);

            $fallbackCountry = ($isPackstation || $isPostfiliale)
                ? ServicePointAttributes::GERMANY_COUNTRY_CODE
                : ($shippingAddress->getCountryId() ?: ServicePointAttributes::DEFAULT_COUNTRY);
            $country = strtoupper(trim((string) ($pointData['country'] ?? $fallbackCountry)));
            $postnummer = trim((string) ($pointData['postnummer'] ?? $this->postnummer ?? ''));

            $servicePoint = new ServicePointValueObject(
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

            $shippingAddress->setData(
                ServicePointAttributes::FIELD_SERVICE_POINT_ID,
                $servicePoint->getId()
            );
            $shippingAddress->setData(
                ServicePointAttributes::FIELD_SERVICE_POINT_NAME,
                $servicePoint->getName()
            );
            $shippingAddress->setData(
                ServicePointAttributes::FIELD_SERVICE_POINT_STREET,
                $servicePoint->getStreet()
            );
            $shippingAddress->setData(
                ServicePointAttributes::FIELD_SERVICE_POINT_POSTCODE,
                $servicePoint->getPostcode()
            );
            $shippingAddress->setData(
                ServicePointAttributes::FIELD_SERVICE_POINT_CITY,
                $servicePoint->getCity()
            );
            $shippingAddress->setData(
                ServicePointAttributes::FIELD_IS_PACKSTATION,
                $servicePoint->isPackstation() ? 1 : 0
            );
            $shippingAddress->setData(
                ServicePointAttributes::FIELD_IS_POSTFILIALE,
                $servicePoint->isPostfiliale() ? 1 : 0
            );
            $shippingAddress->setData(
                ServicePointAttributes::FIELD_POSTNUMMER,
                $servicePoint->getPostnummer()
            );

            $extAttributes = $shippingAddress->getExtensionAttributes();
            if ($extAttributes !== null && method_exists($extAttributes, 'setDhlServicePointId')) {
                $extAttributes->setDhlServicePointId($servicePoint->getId());
                $extAttributes->setDhlServicePointName($servicePoint->getName());
                $extAttributes->setDhlServicePointStreet($servicePoint->getStreet());
                $extAttributes->setDhlServicePointPostcode($servicePoint->getPostcode());
                $extAttributes->setDhlServicePointCity($servicePoint->getCity());
                $extAttributes->setDhlIsPackstation($servicePoint->isPackstation() ? 1 : 0);
                $extAttributes->setDhlIsPostfiliale($servicePoint->isPostfiliale() ? 1 : 0);
                $extAttributes->setDhlPostnummer($servicePoint->getPostnummer());
            }

            $this->pointId = $servicePoint->getId();
            $this->pointName = $servicePoint->getName();
            $this->pointStreet = $servicePoint->getStreet();
            $this->pointPostcode = $servicePoint->getPostcode();
            $this->pointCity = $servicePoint->getCity();
            $this->isPackstation = $servicePoint->isPackstation();
            $this->isPostfiliale = $servicePoint->isPostfiliale();
            $this->postnummer = $servicePoint->getPostnummer();
            $this->country = $servicePoint->getCountry();

            $this->quoteRepository->save($quote);
        } catch (Throwable $exception) {
            $this->logger->error(
                'DHL Hyvä: Error saving pickup point to quote address: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
            $this->dispatchErrorMessage(__('Could not save DHL pickup point. Please try again.'));
        }
    }

    /**
     * Clear selected pickup point from active shipping address and state.
     *
     * @return void
     */
    public function clearPoint(): void
    {
        try {
            $quote = $this->sessionCheckout->getQuote();
            if ($quote) {
                $shippingAddress = $quote->getShippingAddress();
                if ($shippingAddress) {
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
                }
            }
        } catch (Throwable $exception) {
            $this->logger->warning(
                'DHL Hyvä: Error clearing pickup point from quote address: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
        }

        $this->pointId = null;
        $this->pointName = null;
        $this->pointStreet = null;
        $this->pointPostcode = null;
        $this->pointCity = null;
        $this->isPackstation = false;
        $this->isPostfiliale = false;
        $this->postnummer = null;
    }

    /**
     * Listen for German customer number (Postnummer) updates.
     *
     * @param string|null $value
     *
     * @return string|null
     */
    public function updatedPostnummer(?string $value): ?string
    {
        $cleanValue = trim((string) $value);
        $this->postnummer = $cleanValue !== '' ? $cleanValue : null;

        try {
            $quote = $this->sessionCheckout->getQuote();
            if ($quote) {
                $shippingAddress = $quote->getShippingAddress();
                if ($shippingAddress) {
                    $shippingAddress->setData(ServicePointAttributes::FIELD_POSTNUMMER, $this->postnummer);
                    $this->quoteRepository->save($quote);
                }
            }
        } catch (Throwable $exception) {
            $this->logger->error(
                'DHL Hyvä: Error updating Postnummer on quote address: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
            $this->dispatchErrorMessage(__('Could not update German Customer Number (Postnummer).'));
        }

        return $this->postnummer;
    }

    /**
     * Evaluate completion of shipping step for DHL point selection.
     *
     * @param EvaluationResultFactory $resultFactory
     *
     * @return EvaluationResult
     */
    public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResult
    {
        $errorMessageEvent = $resultFactory->createErrorMessageEvent();
        $errorMessageEvent->withCustomEvent(ServicePointAttributes::EVENT_SHIPPING_METHOD_ERROR);

        try {
            $quote = $this->sessionCheckout->getQuote();
            $shippingAddress = $quote?->getShippingAddress();
            if (!$shippingAddress) {
                return $resultFactory->createSuccess([], ServicePointAttributes::EVENT_SHIPPING_METHOD_SUCCESS);
            }

            $carrierCode = (string) $shippingAddress->getShippingCarrierCode();
            $fullMethodCode = (string) $shippingAddress->getShippingMethod();
            if ($carrierCode === '') {
                $carrierCode = Carrier::CODE;
            }

            $cleanMethodCode = strtolower(trim($fullMethodCode));
            if (str_starts_with($cleanMethodCode, Carrier::CODE_PREFIX)) {
                $cleanMethodCode = substr($cleanMethodCode, strlen(Carrier::CODE_PREFIX));
            }

            $servicePoint = $this->buildServicePointFromAddress($shippingAddress);

            $this->servicePointValidator->validate(Carrier::CODE, $cleanMethodCode, $servicePoint);
        } catch (LocalizedException $exception) {
            return $errorMessageEvent->withMessage($exception->getMessage());
        } catch (Throwable $exception) {
            $this->logger->error(
                'DHL Hyvä: Unexpected error during point evaluation: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
            return $errorMessageEvent->withMessage((string) __('Please select a DHL pickup point on the map.'));
        }

        return $resultFactory->createSuccess([], ServicePointAttributes::EVENT_SHIPPING_METHOD_SUCCESS);
    }

    /**
     * Populate internal state from quote shipping address.
     *
     * @return void
     */
    public function syncFromQuote(): void
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

            $sap = trim((string) ($shippingAddress->getData(ServicePointAttributes::FIELD_SERVICE_POINT_ID) ?? ''));
            $this->pointId = $sap !== '' ? $sap : null;
            $this->pointName = (string) (
                $shippingAddress->getData(ServicePointAttributes::FIELD_SERVICE_POINT_NAME) ?? ''
            );
            $this->pointStreet = (string) (
                $shippingAddress->getData(ServicePointAttributes::FIELD_SERVICE_POINT_STREET) ?? ''
            );
            $this->pointPostcode = (string) (
                $shippingAddress->getData(ServicePointAttributes::FIELD_SERVICE_POINT_POSTCODE) ?? ''
            );
            $this->pointCity = (string) (
                $shippingAddress->getData(ServicePointAttributes::FIELD_SERVICE_POINT_CITY) ?? ''
            );
            $this->isPackstation = (bool) (
                $shippingAddress->getData(ServicePointAttributes::FIELD_IS_PACKSTATION) ?? false
            );
            $this->isPostfiliale = (bool) (
                $shippingAddress->getData(ServicePointAttributes::FIELD_IS_POSTFILIALE) ?? false
            );
            $this->postnummer = (string) (
                $shippingAddress->getData(ServicePointAttributes::FIELD_POSTNUMMER) ?? ''
            ) ?: null;
            $this->country = strtoupper(
                (string) ($shippingAddress->getCountryId() ?? ServicePointAttributes::DEFAULT_COUNTRY)
            );
            $this->methodCode = (string) $shippingAddress->getShippingMethod();
        } catch (Throwable $exception) {
            $this->logger->debug(
                'DHL Hyvä: Error syncing state from quote: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
        }
    }

    /**
     * Construct ServicePointValueObject from quote shipping address data.
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
