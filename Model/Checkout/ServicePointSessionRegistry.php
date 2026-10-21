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
