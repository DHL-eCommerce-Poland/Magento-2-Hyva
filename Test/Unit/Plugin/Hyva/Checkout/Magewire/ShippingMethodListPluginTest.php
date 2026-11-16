<?php

declare(strict_types=1);

namespace DhlPoland\HyvaDhl\Test\Unit\Plugin\Hyva\Checkout\Magewire;

use DhlPoland\Dhl\Model\MethodCatalog;
use DhlPoland\Dhl\Model\ServicePoint\ServicePointValueObject;
use DhlPoland\HyvaDhl\Model\Checkout\ServicePointSessionRegistry;
use DhlPoland\HyvaDhl\Plugin\Hyva\Checkout\Magewire\ShippingMethodListPlugin;
use Hyva\Checkout\Magewire\Checkout\Shipping\MethodList;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class ShippingMethodListPluginTest extends TestCase
{
    /**
     * @var CheckoutSession|MockObject
     */
    private CheckoutSession|MockObject $checkoutSessionMock;

    /**
     * @var CartRepositoryInterface|MockObject
     */
    private CartRepositoryInterface|MockObject $quoteRepositoryMock;

    /**
     * @var MethodCatalog|MockObject
     */
    private MethodCatalog|MockObject $methodCatalogMock;

    /**
     * @var ServicePointSessionRegistry|MockObject
     */
    private ServicePointSessionRegistry|MockObject $sessionRegistryMock;

    /**
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface|MockObject $loggerMock;

    /**
     * @var MethodList|MockObject
     */
    private MethodList|MockObject $subjectMock;

    /**
     * @var Quote|MockObject
     */
    private Quote|MockObject $quoteMock;

    /**
     * @var QuoteAddress|MockObject
     */
    private QuoteAddress|MockObject $shippingAddressMock;

    /**
     * @var ShippingMethodListPlugin
     */
    private ShippingMethodListPlugin $plugin;

    protected function setUp(): void
    {
        $this->checkoutSessionMock = $this->createMock(CheckoutSession::class);
        $this->quoteRepositoryMock = $this->createMock(CartRepositoryInterface::class);
        $this->methodCatalogMock = $this->createMock(MethodCatalog::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->sessionRegistryMock = $this->getMockBuilder(ServicePointSessionRegistry::class)
            ->setConstructorArgs([$this->checkoutSessionMock, $this->loggerMock])
            ->onlyMethods(['getPointForMethod', 'savePointForMethod', 'removePointForMethod', 'clear'])
            ->getMock();
        $this->subjectMock = $this->createMock(MethodList::class);
        $this->quoteMock = $this->createMock(Quote::class);
        $this->shippingAddressMock = $this->createMock(QuoteAddress::class);

        $this->checkoutSessionMock->method('getQuote')->willReturn($this->quoteMock);
        $this->quoteMock->method('getShippingAddress')->willReturn($this->shippingAddressMock);

        $this->plugin = new ShippingMethodListPlugin(
            $this->checkoutSessionMock,
            $this->quoteRepositoryMock,
            $this->methodCatalogMock,
            $this->sessionRegistryMock,
            $this->loggerMock
        );
    }

    public function testAfterUpdatedMethodClearsServicePointWhenSwitchingBetweenPointMethods(): void
    {
        $this->shippingAddressMock->method('getData')
            ->with(ServicePointSessionRegistry::FIELD_SERVICE_POINT_ID)
            ->willReturn('BOX-123');

        $savedData = [];
        $this->shippingAddressMock->expects($this->atLeastOnce())
            ->method('setData')
            ->willReturnCallback(function ($key, $val = null) use (&$savedData) {
                if (is_string($key)) {
                    $savedData[$key] = $val;
                }
                return $this->shippingAddressMock;
            });

        $this->quoteRepositoryMock->expects($this->once())
            ->method('save')
            ->with($this->quoteMock);

        $result = $this->plugin->afterUpdatedMethod(
            $this->subjectMock,
            'dhlpoland_economy_pop'
        );

        $this->assertSame('dhlpoland_economy_pop', $result);
        $this->assertArrayHasKey(ServicePointSessionRegistry::FIELD_SERVICE_POINT_ID, $savedData);
        $this->assertNull($savedData[ServicePointSessionRegistry::FIELD_SERVICE_POINT_ID]);
    }

    public function testAfterUpdatedMethodClearsServicePointWhenSwitchingToNonPointMethod(): void
    {
        $this->shippingAddressMock->method('getData')
            ->with(ServicePointSessionRegistry::FIELD_SERVICE_POINT_ID)
            ->willReturn('BOX-123');

        $savedData = [];
        $this->shippingAddressMock->expects($this->atLeastOnce())
            ->method('setData')
            ->willReturnCallback(function ($key, $val = null) use (&$savedData) {
                if (is_string($key)) {
                    $savedData[$key] = $val;
                }
                return $this->shippingAddressMock;
            });

        $this->quoteRepositoryMock->expects($this->once())
            ->method('save')
            ->with($this->quoteMock);

        $result = $this->plugin->afterUpdatedMethod(
            $this->subjectMock,
            'dhlpoland_parcel_polska'
        );

        $this->assertSame('dhlpoland_parcel_polska', $result);
        $this->assertArrayHasKey(ServicePointSessionRegistry::FIELD_SERVICE_POINT_ID, $savedData);
        $this->assertNull($savedData[ServicePointSessionRegistry::FIELD_SERVICE_POINT_ID]);
    }

    public function testAfterUpdatedMethodDoesNotSaveQuoteWhenNoPointExisted(): void
    {
        $this->shippingAddressMock->method('getData')
            ->with(ServicePointSessionRegistry::FIELD_SERVICE_POINT_ID)
            ->willReturn(null);

        $this->shippingAddressMock->expects($this->never())->method('setData');
        $this->quoteRepositoryMock->expects($this->never())->method('save');

        $result = $this->plugin->afterUpdatedMethod(
            $this->subjectMock,
            'dhlpoland_economy_box'
        );

        $this->assertSame('dhlpoland_economy_box', $result);
    }

    public function testAfterUpdatedMethodRestoresPreviouslySavedPointForTargetMethod(): void
    {
        $this->methodCatalogMock->method('isServicePointMethod')->with('economy_box')->willReturn(true);
        $this->shippingAddressMock->method('getCountryId')->willReturn('PL');

        $savedPoint = new ServicePointValueObject(
            'SAVED-BOX-01',
            'Saved Box Point',
            'Pocztowa 5',
            '00-002',
            'Warszawa',
            true,
            false,
            null,
            'PL'
        );

        $this->sessionRegistryMock->expects($this->once())
            ->method('getPointForMethod')
            ->with('dhlpoland_economy_box', 'PL')
            ->willReturn($savedPoint);

        $savedData = [];
        $this->shippingAddressMock->expects($this->atLeastOnce())
            ->method('setData')
            ->willReturnCallback(function ($key, $val = null) use (&$savedData) {
                if (is_string($key)) {
                    $savedData[$key] = $val;
                }
                return $this->shippingAddressMock;
            });

        $this->quoteRepositoryMock->expects($this->once())
            ->method('save')
            ->with($this->quoteMock);

        $result = $this->plugin->afterUpdatedMethod(
            $this->subjectMock,
            'dhlpoland_economy_box'
        );

        $this->assertSame('dhlpoland_economy_box', $result);
        $this->assertSame('SAVED-BOX-01', $savedData[ServicePointSessionRegistry::FIELD_SERVICE_POINT_ID]);
        $this->assertSame('Saved Box Point', $savedData[ServicePointSessionRegistry::FIELD_SERVICE_POINT_NAME]);
    }
}
