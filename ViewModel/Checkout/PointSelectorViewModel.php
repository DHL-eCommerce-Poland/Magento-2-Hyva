<?php

declare(strict_types=1);

namespace DhlPoland\HyvaDhl\ViewModel\Checkout;

use DhlPoland\Dhl\Model\Carrier;
use DhlPoland\Dhl\Model\Checkout\ConfigProvider;
use DhlPoland\Dhl\Model\MethodCatalog;
use DhlPoland\Dhl\Model\ServicePoint\ServicePointValidator;
use DhlPoland\HyvaDhl\Model\Checkout\ServicePointSessionRegistry;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class PointSelectorViewModel implements ArgumentInterface
{
    /**
     * @param MethodCatalog $methodCatalog
     * @param ServicePointValidator $servicePointValidator
     * @param CheckoutSession $checkoutSession
     * @param SerializerInterface $serializer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly MethodCatalog $methodCatalog,
        private readonly ServicePointValidator $servicePointValidator,
        private readonly CheckoutSession $checkoutSession,
        private readonly SerializerInterface $serializer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Retrieve current destination country from quote shipping address.
     *
     * @return string
     */
    public function getDestinationCountry(): string
    {
        try {
            $quote = $this->checkoutSession->getQuote();
            if ($quote) {
                $shippingAddress = $quote->getShippingAddress();
                $countryId = $shippingAddress?->getCountryId();
                if ($countryId) {
                    return strtoupper((string) $countryId);
                }
            }
        } catch (Throwable $exception) {
            $this->logger->debug(
                'DHL Hyvä: Unable to read quote destination country: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
        }

        return ServicePointSessionRegistry::DEFAULT_COUNTRY;
    }

    /**
     * Get default base URL for the DHL map iframe widget.
     *
     * @return string
     */
    public function getBaseMapUrl(): string
    {
        return ConfigProvider::DEFAULT_MAP_URL;
    }

    /**
     * Build iframe URL for DHL map with recipient country and service filters.
     *
     * @param string $methodCode
     * @param string|null $countryCode
     *
     * @return string
     */
    public function getMapUrl(string $methodCode, ?string $countryCode = null): string
    {
        $baseUrl = $this->getBaseMapUrl();

        $cleanMethod = $this->normalizeMethodCode($methodCode);
        $isCod = $this->isCodMethod($cleanMethod);
        $isPackstation = $this->methodCatalog->isPackstationMethod($cleanMethod);
        $isParcelShop = $this->methodCatalog->isParcelShopMethod($cleanMethod);

        $country = strtoupper(trim((string) ($countryCode ?: $this->getDestinationCountry())));
        if ($country === '' || $country === ServicePointSessionRegistry::DEFAULT_COUNTRY) {
            if ($isPackstation || $isParcelShop) {
                $country = ServicePointSessionRegistry::GERMANY_COUNTRY_CODE;
            } else {
                $country = ServicePointSessionRegistry::DEFAULT_COUNTRY;
            }
        }

        $isBoxDomestic = in_array(
            $cleanMethod,
            [MethodCatalog::METHOD_ECONOMY_BOX, MethodCatalog::METHOD_ECONOMY_BOX_COD],
            true
        );
        $isPopDomestic = in_array(
            $cleanMethod,
            [MethodCatalog::METHOD_ECONOMY_POP, MethodCatalog::METHOD_ECONOMY_POP_COD],
            true
        );

        $params = [];

        if ($country !== ServicePointSessionRegistry::DEFAULT_COUNTRY) {
            $params[] = 'country=' . urlencode($country);

            if ($isPackstation) {
                $params[] = 'ptype=packStation';
            } elseif ($isParcelShop) {
                $params[] = 'ptype=parcelShop';
            }
        } else {
            if ($isCod) {
                $params[] = 'options_pickup_cod=T';
            }

            if ($isBoxDomestic) {
                $params[] = 'show_on_map_parcelshop=N&show_on_map_parcelshop_late=N&show_on_map_parcelstation=T';
            } elseif ($isPopDomestic) {
                $params[] = 'show_on_map_parcelshop=T&show_on_map_parcelshop_late=N&show_on_map_parcelstation=N';
            }
        }

        if (empty($params)) {
            return $baseUrl;
        }

        $separator = str_contains($baseUrl, '?') ? '&' : '?';
        return $baseUrl . $separator . implode('&', $params);
    }

    /**
     * Determine map point type (all, box, pop) for given shipping method code.
     *
     * @param string $methodCode
     *
     * @return string
     */
    public function getPointMapType(string $methodCode): string
    {
        $clean = $this->normalizeMethodCode($methodCode);
        if (in_array($clean, [MethodCatalog::METHOD_ECONOMY_BOX, MethodCatalog::METHOD_ECONOMY_BOX_COD], true)
            || $this->methodCatalog->isPackstationMethod($clean)) {
            return 'box';
        }

        if (in_array($clean, [MethodCatalog::METHOD_ECONOMY_POP, MethodCatalog::METHOD_ECONOMY_POP_COD], true)
            || $this->methodCatalog->isParcelShopMethod($clean)) {
            return 'pop';
        }

        return 'all';
    }

    /**
     * Check whether shipping method is Cash On Delivery (COD).
     *
     * @param string $methodCode
     *
     * @return bool
     */
    public function isCodMethod(string $methodCode): bool
    {
        return $this->methodCatalog->isCodMethod($methodCode);
    }

    /**
     * Check whether service point selection is mandatory for the given shipping method.
     *
     * @param string $methodCode
     *
     * @return bool
     */
    public function isServicePointRequired(string $methodCode): bool
    {
        $cleanMethod = $this->normalizeMethodCode($methodCode);
        return $this->servicePointValidator->isPointSelectionRequired(Carrier::CODE, $cleanMethod);
    }

    /**
     * Check whether the shipping method targets a Packstation.
     *
     * @param string $methodCode
     *
     * @return bool
     */
    public function isPackstation(string $methodCode): bool
    {
        $cleanMethod = $this->normalizeMethodCode($methodCode);
        return $this->methodCatalog->isPackstationMethod($cleanMethod);
    }

    /**
     * Check whether the shipping method targets a parcel shop (POP).
     *
     * @param string $methodCode
     *
     * @return bool
     */
    public function isParcelShop(string $methodCode): bool
    {
        $cleanMethod = $this->normalizeMethodCode($methodCode);
        return $this->methodCatalog->isParcelShopMethod($cleanMethod);
    }

    /**
     * Determine if customer number (Postnummer) is required for German Packstation delivery.
     *
     * @param string|null $countryCode
     * @param string $methodCode
     *
     * @return bool
     */
    public function isGermanPackstation(?string $countryCode, string $methodCode): bool
    {
        $country = strtoupper(trim((string) ($countryCode ?: $this->getDestinationCountry())));
        return $country === ServicePointSessionRegistry::GERMANY_COUNTRY_CODE && $this->isPackstation($methodCode);
    }

    /**
     * Serialize configuration array into JSON string using Magento SerializerInterface.
     *
     * @param array $config
     *
     * @return string
     */
    public function encodeConfig(array $config): string
    {
        return (string) $this->serializer->serialize($config);
    }

    /**
     * Normalize shipping method code by removing carrier code prefix.
     *
     * @param string|null $methodCode
     *
     * @return string
     */
    public function normalizeMethodCode(?string $methodCode): string
    {
        $code = strtolower(trim((string) $methodCode));
        if (str_starts_with($code, Carrier::CODE_PREFIX)) {
            return substr($code, strlen(Carrier::CODE_PREFIX));
        }

        return $code;
    }
}
