<?php

declare(strict_types=1);

namespace DhlPoland\HyvaDhl\Magewire\Checkout\Shipping\Method;

use DhlPoland\Dhl\Model\Carrier;
use DhlPoland\Dhl\Model\MethodCatalog;
use DhlPoland\Dhl\Model\ServicePoint\ServicePointValidator;
use DhlPoland\Dhl\Model\ServicePoint\ServicePointValueObject;
use DhlPoland\HyvaDhl\Model\Checkout\ServicePointSessionRegistry;
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
    public const EVENT_SHIPPING_METHOD_ERROR = 'shipping:method:error';
    public const EVENT_SHIPPING_METHOD_SUCCESS = 'shipping:method:success';
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
                ? ServicePointSessionRegistry::GERMANY_COUNTRY_CODE
                : ($shippingAddress->getCountryId() ?: ServicePointSessionRegistry::DEFAULT_COUNTRY);
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

            $this->sessionRegistry->applyToAddress($shippingAddress, $servicePoint);

            $this->pointId = $servicePoint->getId();
            $this->pointName = $servicePoint->getName();
            $this->pointStreet = $servicePoint->getStreet();
            $this->pointPostcode = $servicePoint->getPostcode();
            $this->pointCity = $servicePoint->getCity();
            $this->isPackstation = $servicePoint->isPackstation();
            $this->isPostfiliale = $servicePoint->isPostfiliale();
            $this->postnummer = $servicePoint->getPostnummer();
            $this->country = $servicePoint->getCountry();

            $this->sessionRegistry->savePointForMethod($fullMethodCode, $servicePoint);
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
                    $this->sessionRegistry->clearAddressData($shippingAddress);

                    $fullMethodCode = (string) ($this->methodCode ?: $shippingAddress->getShippingMethod());
                    $this->sessionRegistry->removePointForMethod($fullMethodCode);

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
                    $shippingAddress->setData(ServicePointSessionRegistry::FIELD_POSTNUMMER, $this->postnummer);

                    $extAttributes = $shippingAddress->getExtensionAttributes();
                    if ($extAttributes !== null && method_exists($extAttributes, 'setDhlPostnummer')) {
                        $extAttributes->setDhlPostnummer($this->postnummer);
                    }

                    $currentPoint = $this->sessionRegistry->extractFromAddress($shippingAddress);
                    if ($currentPoint !== null) {
                        $fullMethodCode = (string) ($this->methodCode ?: $shippingAddress->getShippingMethod());
                        $this->sessionRegistry->savePointForMethod($fullMethodCode, $currentPoint);
                    }

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
        $errorMessageEvent->withCustomEvent(self::EVENT_SHIPPING_METHOD_ERROR);

        try {
            $quote = $this->sessionCheckout->getQuote();
            $shippingAddress = $quote?->getShippingAddress();
            if (!$shippingAddress) {
                return $resultFactory->createSuccess([], self::EVENT_SHIPPING_METHOD_SUCCESS);
            }

            $fullMethodCode = (string) $shippingAddress->getShippingMethod();
            $cleanMethodCode = strtolower(trim($fullMethodCode));
            if (str_starts_with($cleanMethodCode, Carrier::CODE_PREFIX)) {
                $cleanMethodCode = substr($cleanMethodCode, strlen(Carrier::CODE_PREFIX));
            }

            $servicePoint = $this->sessionRegistry->extractFromAddress($shippingAddress);

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

        return $resultFactory->createSuccess([], self::EVENT_SHIPPING_METHOD_SUCCESS);
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

            $point = $this->sessionRegistry->extractFromAddress($shippingAddress);
            $this->pointId = $point?->getId();
            $this->pointName = $point?->getName() ?? '';
            $this->pointStreet = $point?->getStreet() ?? '';
            $this->pointPostcode = $point?->getPostcode() ?? '';
            $this->pointCity = $point?->getCity() ?? '';
            $this->isPackstation = $point?->isPackstation() ?? false;
            $this->isPostfiliale = $point?->isPostfiliale() ?? false;
            $this->postnummer = $point !== null
                ? $point->getPostnummer()
                : ((string) ($shippingAddress->getData(ServicePointSessionRegistry::FIELD_POSTNUMMER) ?? '') ?: null);
            $this->country = strtoupper(
                (string) ($shippingAddress->getCountryId() ?? ServicePointSessionRegistry::DEFAULT_COUNTRY)
            );
            $this->methodCode = (string) $shippingAddress->getShippingMethod();
        } catch (Throwable $exception) {
            $this->logger->debug(
                'DHL Hyvä: Error syncing state from quote: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
        }
    }
}
