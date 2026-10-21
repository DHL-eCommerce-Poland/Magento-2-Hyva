<?php

declare(strict_types=1);

namespace DhlPoland\HyvaDhl\Test\Unit\Model\Checkout;

use DhlPoland\Dhl\Model\Carrier;
use DhlPoland\Dhl\Model\ServicePoint\ServicePointValueObject;
use DhlPoland\HyvaDhl\Model\Checkout\ServicePointSessionRegistry;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class ServicePointSessionRegistryTest extends TestCase
{
    /**
     * @var CheckoutSession
     */
    private CheckoutSession $checkoutSession;

    /**
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface|MockObject $loggerMock;

    /**
     * @var Quote|MockObject
     */
    private Quote|MockObject $quoteMock;

    /**
     * @var ServicePointSessionRegistry
     */
    private ServicePointSessionRegistry $registry;

    protected function setUp(): void
    {
        $this->quoteMock = $this->createMock(Quote::class);
        $this->quoteMock->method('getId')->willReturn('100');

        $this->checkoutSession = new class($this->quoteMock) extends CheckoutSession {
            /**
             * @var array
             */
            private array $inMemoryData = [];

            public function __construct(private readonly mixed $mockQuote)
            {
            }

            public function getData($key = '', $clear = false)
            {
                if ($key === '') {
                    return $this->inMemoryData;
                }
                $val = $this->inMemoryData[$key] ?? null;
                if ($clear) {
                    unset($this->inMemoryData[$key]);
                }
                return $val;
            }

            public function setData($key, $value = null)
            {
                if (is_array($key)) {
                    $this->inMemoryData = $key;
                } else {
                    $this->inMemoryData[$key] = $value;
                }
                return $this;
            }

            public function getQuote()
            {
                return $this->mockQuote;
            }
        };

        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->registry = new ServicePointSessionRegistry(
            $this->checkoutSession,
            $this->loggerMock
        );
    }

    public function testGetPointReturnsNullWhenEmpty(): void
    {
        $point = $this->registry->getPointForMethod('dhlpoland_economy_box');
        $this->assertNull($point);
    }

    public function testSaveAndRetrievePointForMethod(): void
    {
        $point = new ServicePointValueObject(
            'BOX-WAW-01',
            'Paczkomat WAW 01',
            'Marszalkowska 10',
            '00-001',
            'Warszawa',
            true,
            false,
            null,
            'PL'
        );

        $this->registry->savePointForMethod('dhlpoland_economy_box', $point);

        // Retrieve for same method
        $retrieved = $this->registry->getPointForMethod('dhlpoland_economy_box', 'PL');
        $this->assertNotNull($retrieved);
        $this->assertSame('BOX-WAW-01', $retrieved->getId());
        $this->assertSame('Warszawa', $retrieved->getCity());

        // Normalizes prefix: unprefixed economy_box retrieves same point
        $retrievedShort = $this->registry->getPointForMethod('economy_box', 'PL');
        $this->assertNotNull($retrievedShort);
        $this->assertSame('BOX-WAW-01', $retrievedShort->getId());

        // Other method has no point yet
        $otherPoint = $this->registry->getPointForMethod('dhlpoland_economy_pop', 'PL');
        $this->assertNull($otherPoint);
    }

    public function testGetPointReturnsNullWhenDestinationCountryDoesNotMatch(): void
    {
        $this->checkoutSession->setData(ServicePointSessionRegistry::SESSION_KEY, [
            'quote_id' => '100',
            'points' => [
                Carrier::CODE_PREFIX . 'economy_box' => [
                    'id' => 'BOX-WAW-01',
                    'name' => 'Box',
                    'street' => 'Street',
                    'postcode' => '00-001',
                    'city' => 'Warsaw',
                    'is_packstation' => true,
                    'is_postfiliale' => false,
                    'postnummer' => null,
                    'country' => 'PL',
                ]
            ]
        ]);

        // Destination DE doesn't match point country PL
        $point = $this->registry->getPointForMethod('dhlpoland_economy_box', 'DE');
        $this->assertNull($point);
    }

    public function testRemovePointForMethod(): void
    {
        $point = new ServicePointValueObject('POP-1', 'Pop 1', '', '', '', false, true, null, 'PL');
        $this->registry->savePointForMethod('dhlpoland_economy_pop', $point);

        $this->assertNotNull($this->registry->getPointForMethod('dhlpoland_economy_pop'));

        $this->registry->removePointForMethod('dhlpoland_economy_pop');

        $this->assertNull($this->registry->getPointForMethod('dhlpoland_economy_pop'));
    }

    public function testQuoteIdMismatchClearsStoredPoints(): void
    {
        $this->checkoutSession->setData(ServicePointSessionRegistry::SESSION_KEY, [
            'quote_id' => '99', // Old quote
            'points' => [
                Carrier::CODE_PREFIX . 'economy_box' => [
                    'id' => 'OLD-BOX',
                    'name' => 'Old',
                    'street' => '',
                    'postcode' => '',
                    'city' => '',
                    'is_packstation' => false,
                    'is_postfiliale' => false,
                    'postnummer' => null,
                    'country' => 'PL',
                ]
            ]
        ]);

        // Current quote is '100', stored is '99'
        $point = $this->registry->getPointForMethod('dhlpoland_economy_box');
        $this->assertNull($point);
    }

    public function testExtractFromAddressReturnsNullWhenNoPointId(): void
    {
        $addressMock = $this->createMock(QuoteAddress::class);
        $addressMock->method('getData')
            ->with(ServicePointSessionRegistry::FIELD_SERVICE_POINT_ID)
            ->willReturn('');

        $this->assertNull($this->registry->extractFromAddress($addressMock));
        $this->assertNull($this->registry->extractFromAddress(null));
    }

    public function testExtractFromAddressBuildsValueObject(): void
    {
        $addressMock = $this->createMock(QuoteAddress::class);
        $addressMock->method('getData')->willReturnCallback(function (string $key) {
            return match ($key) {
                ServicePointSessionRegistry::FIELD_SERVICE_POINT_ID => 'POP-123',
                ServicePointSessionRegistry::FIELD_SERVICE_POINT_NAME => 'Point Name',
                ServicePointSessionRegistry::FIELD_SERVICE_POINT_STREET => 'Main St 1',
                ServicePointSessionRegistry::FIELD_SERVICE_POINT_POSTCODE => '00-001',
                ServicePointSessionRegistry::FIELD_SERVICE_POINT_CITY => 'Warsaw',
                ServicePointSessionRegistry::FIELD_IS_PACKSTATION => 1,
                ServicePointSessionRegistry::FIELD_IS_POSTFILIALE => 0,
                ServicePointSessionRegistry::FIELD_POSTNUMMER => '12345678',
                default => null,
            };
        });
        $addressMock->method('getCountryId')->willReturn('de');

        $point = $this->registry->extractFromAddress($addressMock);

        $this->assertNotNull($point);
        $this->assertSame('POP-123', $point->getId());
        $this->assertSame('Point Name', $point->getName());
        $this->assertSame('Main St 1', $point->getStreet());
        $this->assertSame('00-001', $point->getPostcode());
        $this->assertSame('Warsaw', $point->getCity());
        $this->assertTrue($point->isPackstation());
        $this->assertFalse($point->isPostfiliale());
        $this->assertSame('12345678', $point->getPostnummer());
        $this->assertSame('DE', $point->getCountry());
    }

    public function testApplyToAddressAndClearAddressData(): void
    {
        $addressMock = $this->createMock(QuoteAddress::class);
        $savedData = [];
        $addressMock->method('setData')
            ->willReturnCallback(function ($key, $val = null) use (&$savedData, $addressMock) {
                if (is_string($key)) {
                    $savedData[$key] = $val;
                }
                return $addressMock;
            });
        $addressMock->method('getData')
            ->willReturnCallback(function (string $key) use (&$savedData) {
                return $savedData[$key] ?? null;
            });

        $point = new ServicePointValueObject(
            'BOX-99',
            'Box 99',
            'Street 99',
            '11-111',
            'Krakow',
            true,
            false,
            '87654321',
            'DE'
        );

        $this->registry->applyToAddress($addressMock, $point);

        $this->assertSame('BOX-99', $savedData[ServicePointSessionRegistry::FIELD_SERVICE_POINT_ID]);
        $this->assertSame('Box 99', $savedData[ServicePointSessionRegistry::FIELD_SERVICE_POINT_NAME]);
        $this->assertSame(1, $savedData[ServicePointSessionRegistry::FIELD_IS_PACKSTATION]);
        $this->assertSame(0, $savedData[ServicePointSessionRegistry::FIELD_IS_POSTFILIALE]);
        $this->assertSame('87654321', $savedData[ServicePointSessionRegistry::FIELD_POSTNUMMER]);

        $this->assertTrue($this->registry->clearAddressData($addressMock));
        $this->assertNull($savedData[ServicePointSessionRegistry::FIELD_SERVICE_POINT_ID]);
        $this->assertSame(0, $savedData[ServicePointSessionRegistry::FIELD_IS_PACKSTATION]);
        $this->assertFalse($this->registry->clearAddressData($addressMock));
    }
}
