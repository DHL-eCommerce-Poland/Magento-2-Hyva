<?php

declare(strict_types=1);

namespace DhlPoland\HyvaDhl\ViewModel\Checkout;

use DhlPoland\Dhl\Model\Carrier;
use DhlPoland\Dhl\Model\Checkout\ConfigProvider;
use DhlPoland\Dhl\Model\MethodCatalog;
use DhlPoland\Dhl\Model\ServicePoint\ServicePointValidator;
use DhlPoland\HyvaDhl\Model\ServicePointAttributes;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class PointSelectorViewModel implements ArgumentInterface
{
    /**
     * @param MethodCatalog $methodCatalog
     * @param ServicePointValidator $servicePointValidator
     * @param CheckoutSession $checkoutSession
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly MethodCatalog $methodCatalog,
        private readonly ServicePointValidator $servicePointValidator,
        private readonly CheckoutSession $checkoutSession,
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

        return ServicePointAttributes::DEFAULT_COUNTRY;
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
        $baseUrl = ConfigProvider::DEFAULT_MAP_URL;

        $country = strtoupper(trim((string) ($countryCode ?: $this->getDestinationCountry())));
        if ($country === '') {
            $country = ServicePointAttributes::DEFAULT_COUNTRY;
        }

        $cleanMethod = $this->normalizeMethodCode($methodCode);
        $isCod = str_ends_with($cleanMethod, '_cod') || str_contains($cleanMethod, '_cod');

        $isPackstation = $this->methodCatalog->isPackstationMethod($cleanMethod);
        $isParcelShop = $this->methodCatalog->isParcelShopMethod($cleanMethod);
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

        if ($country !== ServicePointAttributes::DEFAULT_COUNTRY) {
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
        return $country === ServicePointAttributes::GERMANY_COUNTRY_CODE && $this->isPackstation($methodCode);
    }

    /**
     * Retrieve currently selected DHL service point from active quote shipping address.
     *
     * @return array|null
     */
    public function getSelectedPoint(): ?array
    {
        try {
            $quote = $this->checkoutSession->getQuote();
            if (!$quote) {
                return null;
            }

            $shippingAddress = $quote->getShippingAddress();
            if (!$shippingAddress) {
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

            return [
                'sap' => $sap,
                'name' => $name !== '' ? $name : $sap,
                'street' => $street,
                'postcode' => $postcode,
                'city' => $city,
                'isPackstation' => $isPackstation,
                'isPostfiliale' => $isPostfiliale,
                'postnummer' => $postnummer,
                'country' => $country,
            ];
        } catch (Throwable $exception) {
            $this->logger->debug(
                'DHL Hyvä: Unable to read selected service point from quote: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
            return null;
        }
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
