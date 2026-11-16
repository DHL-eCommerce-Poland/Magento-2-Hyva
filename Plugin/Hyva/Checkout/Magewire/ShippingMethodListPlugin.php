<?php

declare(strict_types=1);

namespace DhlPoland\HyvaDhl\Plugin\Hyva\Checkout\Magewire;

use DhlPoland\Dhl\Model\Carrier;
use DhlPoland\Dhl\Model\MethodCatalog;
use DhlPoland\HyvaDhl\Model\Checkout\ServicePointSessionRegistry;
use Hyva\Checkout\Magewire\Checkout\Shipping\MethodList;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Plugin on Hyvä Checkout Shipping MethodList to synchronize service point data on method change.
 */
class ShippingMethodListPlugin
{
    /**
     * @param CheckoutSession $sessionCheckout
     * @param CartRepositoryInterface $quoteRepository
     * @param MethodCatalog $methodCatalog
     * @param ServicePointSessionRegistry $sessionRegistry
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CheckoutSession $sessionCheckout,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly MethodCatalog $methodCatalog,
        private readonly ServicePointSessionRegistry $sessionRegistry,
        private readonly LoggerInterface $logger
    ) {
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
