<?php

declare(strict_types=1);

namespace DhlPoland\HyvaDhl\Model\Checkout;

use DhlPoland\Dhl\Model\Carrier;
use DhlPoland\Dhl\Model\ServicePoint\ServicePointValueObject;
use Magento\Checkout\Model\Session as CheckoutSession;
use Psr\Log\LoggerInterface;
use Throwable;

class ServicePointSessionRegistry
{
    public const SESSION_KEY = 'dhl_selected_service_points_map';

    public const FIELD_SERVICE_POINT_ID = 'dhl_service_point_id';
    public const FIELD_SERVICE_POINT_NAME = 'dhl_service_point_name';
    public const FIELD_SERVICE_POINT_STREET = 'dhl_service_point_street';
    public const FIELD_SERVICE_POINT_POSTCODE = 'dhl_service_point_postcode';
    public const FIELD_SERVICE_POINT_CITY = 'dhl_service_point_city';
    public const FIELD_IS_PACKSTATION = 'dhl_is_packstation';
    public const FIELD_IS_POSTFILIALE = 'dhl_is_postfiliale';
    public const FIELD_POSTNUMMER = 'dhl_postnummer';

    public const ALL_FIELDS = [
        self::FIELD_SERVICE_POINT_ID,
        self::FIELD_SERVICE_POINT_NAME,
        self::FIELD_SERVICE_POINT_STREET,
        self::FIELD_SERVICE_POINT_POSTCODE,
        self::FIELD_SERVICE_POINT_CITY,
        self::FIELD_IS_PACKSTATION,
        self::FIELD_IS_POSTFILIALE,
        self::FIELD_POSTNUMMER,
    ];

    public const DEFAULT_COUNTRY = 'PL';
    public const GERMANY_COUNTRY_CODE = 'DE';

    /**
     * @param CheckoutSession $checkoutSession
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Retrieve the stored service point for the specified shipping method.
     *
     * @param string $methodCode
     * @param string|null $destinationCountry
     *
     * @return ServicePointValueObject|null
     */
    public function getPointForMethod(string $methodCode, ?string $destinationCountry = null): ?ServicePointValueObject
    {
        $pointsMap = $this->loadPointsMap();
        $key = $this->normalizeKey($methodCode);

        if (!isset($pointsMap[$key]) || !is_array($pointsMap[$key])) {
            return null;
        }

        $point = $this->deserializePoint($pointsMap[$key]);
        if ($point === null) {
            return null;
        }

        if ($destinationCountry !== null && trim($destinationCountry) !== '') {
            $destUpper = strtoupper(trim($destinationCountry));
            $pointCountry = strtoupper(trim($point->getCountry()));
            if ($pointCountry !== '' && $pointCountry !== $destUpper) {
                return null;
            }
        }

        return $point;
    }

    /**
     * Save the selected service point for the specified shipping method.
     *
     * @param string $methodCode
     * @param ServicePointValueObject $point
     *
     * @return void
     */
    public function savePointForMethod(string $methodCode, ServicePointValueObject $point): void
    {
        $pointsMap = $this->loadPointsMap();
        $key = $this->normalizeKey($methodCode);

        $pointsMap[$key] = $this->serializePoint($point);
        $this->savePointsMap($pointsMap);
    }

    /**
     * Remove the stored service point for the specified shipping method.
     *
     * @param string $methodCode
     *
     * @return void
     */
    public function removePointForMethod(string $methodCode): void
    {
        $pointsMap = $this->loadPointsMap();
        $key = $this->normalizeKey($methodCode);

        if (isset($pointsMap[$key])) {
            unset($pointsMap[$key]);
            $this->savePointsMap($pointsMap);
        }
    }

    /**
     * Clear all stored points from the checkout session.
     *
     * @return void
     */
    public function clear(): void
    {
        try {
            $this->checkoutSession->setData(self::SESSION_KEY, null);
        } catch (Throwable $e) {
            $this->logger->warning(
                'DHL Hyvä: Error clearing service point session registry: ' . $e->getMessage(),
                ['exception' => $e]
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
    public function extractFromAddress(mixed $shippingAddress): ?ServicePointValueObject
    {
        if (!$shippingAddress || !is_object($shippingAddress)) {
            return null;
        }

        $sap = trim((string) ($shippingAddress->getData(self::FIELD_SERVICE_POINT_ID) ?? ''));
        if ($sap === '') {
            return null;
        }

        $name = trim((string) ($shippingAddress->getData(self::FIELD_SERVICE_POINT_NAME) ?? ''));
        $street = (string) ($shippingAddress->getData(self::FIELD_SERVICE_POINT_STREET) ?? '');
        $postcode = (string) ($shippingAddress->getData(self::FIELD_SERVICE_POINT_POSTCODE) ?? '');
        $city = (string) ($shippingAddress->getData(self::FIELD_SERVICE_POINT_CITY) ?? '');
        $isPackstation = (bool) ($shippingAddress->getData(self::FIELD_IS_PACKSTATION) ?? false);
        $isPostfiliale = (bool) ($shippingAddress->getData(self::FIELD_IS_POSTFILIALE) ?? false);
        $postnummer = trim((string) ($shippingAddress->getData(self::FIELD_POSTNUMMER) ?? ''));
        $country = strtoupper((string) ($shippingAddress->getCountryId() ?? self::DEFAULT_COUNTRY));

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

    /**
     * Apply ServicePointValueObject attributes to quote shipping address and its extension attributes.
     *
     * @param mixed $shippingAddress
     * @param ServicePointValueObject $point
     *
     * @return void
     */
    public function applyToAddress(mixed $shippingAddress, ServicePointValueObject $point): void
    {
        if (!$shippingAddress || !is_object($shippingAddress)) {
            return;
        }

        $shippingAddress->setData(self::FIELD_SERVICE_POINT_ID, $point->getId());
        $shippingAddress->setData(self::FIELD_SERVICE_POINT_NAME, $point->getName());
        $shippingAddress->setData(self::FIELD_SERVICE_POINT_STREET, $point->getStreet());
        $shippingAddress->setData(self::FIELD_SERVICE_POINT_POSTCODE, $point->getPostcode());
        $shippingAddress->setData(self::FIELD_SERVICE_POINT_CITY, $point->getCity());
        $shippingAddress->setData(self::FIELD_IS_PACKSTATION, $point->isPackstation() ? 1 : 0);
        $shippingAddress->setData(self::FIELD_IS_POSTFILIALE, $point->isPostfiliale() ? 1 : 0);
        $shippingAddress->setData(self::FIELD_POSTNUMMER, $point->getPostnummer());

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
     * Clear DHL service point attributes from quote shipping address.
     *
     * @param mixed $shippingAddress
     *
     * @return bool True if an existing point was cleared, false if no point was present.
     */
    public function clearAddressData(mixed $shippingAddress): bool
    {
        if (!$shippingAddress || !is_object($shippingAddress)) {
            return false;
        }

        $pointId = trim((string) ($shippingAddress->getData(self::FIELD_SERVICE_POINT_ID) ?? ''));
        if ($pointId === '') {
            return false;
        }

        foreach (self::ALL_FIELDS as $field) {
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

        return true;
    }

    /**
     * Load the points map from the checkout session, verifying quote identity.
     *
     * @return array
     */
    private function loadPointsMap(): array
    {
        try {
            $data = $this->checkoutSession->getData(self::SESSION_KEY);
            if (!is_array($data)) {
                return [];
            }

            $currentQuoteId = (string) ($this->checkoutSession->getQuote()?->getId() ?? '');
            $storedQuoteId = (string) ($data['quote_id'] ?? '');

            if ($storedQuoteId !== '' && $currentQuoteId !== '' && $storedQuoteId !== $currentQuoteId) {
                $this->clear();
                return [];
            }

            return isset($data['points']) && is_array($data['points']) ? $data['points'] : [];
        } catch (Throwable $e) {
            $this->logger->warning(
                'DHL Hyvä: Error loading points map from session: ' . $e->getMessage(),
                ['exception' => $e]
            );
            return [];
        }
    }

    /**
     * Persist the points map to the checkout session.
     *
     * @param array $pointsMap
     *
     * @return void
     */
    private function savePointsMap(array $pointsMap): void
    {
        try {
            $currentQuoteId = (string) ($this->checkoutSession->getQuote()?->getId() ?? '');
            $payload = [
                'quote_id' => $currentQuoteId,
                'points' => $pointsMap,
            ];
            $this->checkoutSession->setData(self::SESSION_KEY, $payload);
        } catch (Throwable $e) {
            $this->logger->warning(
                'DHL Hyvä: Error saving points map to session: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * Normalize shipping method code to prefixed key (e.g. 'dhlpoland_economy_box').
     *
     * @param string $methodCode
     *
     * @return string
     */
    private function normalizeKey(string $methodCode): string
    {
        $code = strtolower(trim($methodCode));
        if (!str_starts_with($code, Carrier::CODE_PREFIX)) {
            $code = Carrier::CODE_PREFIX . $code;
        }
        return $code;
    }

    /**
     * Serialize ServicePointValueObject into an array.
     *
     * @param ServicePointValueObject $point
     *
     * @return array
     */
    private function serializePoint(ServicePointValueObject $point): array
    {
        return [
            'id' => $point->getId(),
            'name' => $point->getName(),
            'street' => $point->getStreet(),
            'postcode' => $point->getPostcode(),
            'city' => $point->getCity(),
            'is_packstation' => $point->isPackstation(),
            'is_postfiliale' => $point->isPostfiliale(),
            'postnummer' => $point->getPostnummer(),
            'country' => $point->getCountry(),
        ];
    }

    /**
     * Deserialize an array into ServicePointValueObject.
     *
     * @param array $data
     *
     * @return ServicePointValueObject|null
     */
    private function deserializePoint(array $data): ?ServicePointValueObject
    {
        $id = trim((string) ($data['id'] ?? ''));
        if ($id === '') {
            return null;
        }

        return new ServicePointValueObject(
            $id,
            (string) ($data['name'] ?? ''),
            (string) ($data['street'] ?? ''),
            (string) ($data['postcode'] ?? ''),
            (string) ($data['city'] ?? ''),
            (bool) ($data['is_packstation'] ?? false),
            (bool) ($data['is_postfiliale'] ?? false),
            isset($data['postnummer']) && $data['postnummer'] !== '' ? (string) $data['postnummer'] : null,
            (string) ($data['country'] ?? '')
        );
    }
}
