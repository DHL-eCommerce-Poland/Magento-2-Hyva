<?php

declare(strict_types=1);

namespace DhlPoland\HyvaDhl\Test\Unit\Model\Checkout;

use DhlPoland\Dhl\Model\Carrier;
use DhlPoland\Dhl\Model\ServicePoint\ServicePointValueObject;
use DhlPoland\HyvaDhl\Model\Checkout\ServicePointSessionRegistry;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\Quote;
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
}
