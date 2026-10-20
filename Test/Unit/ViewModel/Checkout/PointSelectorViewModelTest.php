<?php

declare(strict_types=1);

namespace DhlPoland\HyvaDhl\Test\Unit\ViewModel\Checkout;

use DhlPoland\Dhl\Model\Carrier;
use DhlPoland\Dhl\Model\MethodCatalog;
use DhlPoland\Dhl\Model\ServicePoint\ServicePointValidator;
use DhlPoland\HyvaDhl\Model\ServicePointAttributes;
use DhlPoland\HyvaDhl\ViewModel\Checkout\PointSelectorViewModel;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class PointSelectorViewModelTest extends TestCase
{
    private MethodCatalog|MockObject $methodCatalogMock;
    private ServicePointValidator|MockObject $validatorMock;
    private CheckoutSession|MockObject $checkoutSessionMock;
    private LoggerInterface|MockObject $loggerMock;
    private PointSelectorViewModel $viewModel;

    protected function setUp(): void
    {
        $this->methodCatalogMock = $this->createMock(MethodCatalog::class);
        $this->validatorMock = $this->createMock(ServicePointValidator::class);
        $this->checkoutSessionMock = $this->createMock(CheckoutSession::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->viewModel = new PointSelectorViewModel(
            $this->methodCatalogMock,
            $this->validatorMock,
            $this->checkoutSessionMock,
            $this->loggerMock
        );
    }

    public function testGetDestinationCountryReturnsShippingAddressCountry(): void
    {
        $quoteMock = $this->createMock(Quote::class);
        $addressMock = $this->createMock(QuoteAddress::class);

        $this->checkoutSessionMock->expects($this->once())
            ->method('getQuote')
            ->willReturn($quoteMock);

        $quoteMock->expects($this->once())
            ->method('getShippingAddress')
            ->willReturn($addressMock);

        $addressMock->expects($this->once())
            ->method('getCountryId')
            ->willReturn('de');

        $this->assertSame('DE', $this->viewModel->getDestinationCountry());
    }

    public function testGetDestinationCountryDefaultsToPlWhenNoAddress(): void
    {
        $this->checkoutSessionMock->expects($this->once())
            ->method('getQuote')
            ->willReturn(null);

        $this->assertSame(ServicePointAttributes::DEFAULT_COUNTRY, $this->viewModel->getDestinationCountry());
    }

    public function testGetMapUrlForDomesticBoxMethod(): void
    {
        $this->methodCatalogMock->method('isPackstationMethod')->willReturn(false);
        $this->methodCatalogMock->method('isParcelShopMethod')->willReturn(false);

        $url = $this->viewModel->getMapUrl('dhlpoland_economy_box', 'PL');

        $this->assertStringContainsString('https://parcelshop.dhl.pl/mapa', $url);
        $this->assertStringContainsString('show_on_map_parcelstation=T', $url);
        $this->assertStringContainsString('show_on_map_parcelshop=N', $url);
    }

    public function testGetMapUrlForDomesticPopMethodWithCod(): void
    {
        $this->methodCatalogMock->method('isPackstationMethod')->willReturn(false);
        $this->methodCatalogMock->method('isParcelShopMethod')->willReturn(false);

        $url = $this->viewModel->getMapUrl('dhlpoland_economy_pop_cod', 'PL');

        $this->assertStringContainsString('options_pickup_cod=T', $url);
        $this->assertStringContainsString('show_on_map_parcelshop=T', $url);
        $this->assertStringContainsString('show_on_map_parcelstation=N', $url);
    }

    public function testGetMapUrlForInternationalPackstation(): void
    {
        $this->methodCatalogMock->method('isPackstationMethod')->willReturn(true);
        $this->methodCatalogMock->method('isParcelShopMethod')->willReturn(false);

        $url = $this->viewModel->getMapUrl('dhlpoland_connect_packstation', 'DE');

        $this->assertStringContainsString('country=DE', $url);
        $this->assertStringContainsString('ptype=packStation', $url);
    }

    public function testIsServicePointRequiredDelegatesToValidator(): void
    {
        $this->validatorMock->expects($this->once())
            ->method('isPointSelectionRequired')
            ->with(Carrier::CODE, 'economy_box')
            ->willReturn(true);

        $this->assertTrue($this->viewModel->isServicePointRequired('dhlpoland_economy_box'));
    }

    public function testIsGermanPackstationReturnsTrueForDeAndPackstation(): void
    {
        $this->methodCatalogMock->method('isPackstationMethod')->willReturn(true);

        $this->assertTrue($this->viewModel->isGermanPackstation('de', 'connect_packstation'));
        $this->assertFalse($this->viewModel->isGermanPackstation('pl', 'connect_packstation'));
    }

    public function testGetSelectedPointReturnsNullWhenNoPointId(): void
    {
        $quoteMock = $this->createMock(Quote::class);
        $addressMock = $this->createMock(QuoteAddress::class);

        $this->checkoutSessionMock->method('getQuote')->willReturn($quoteMock);
        $quoteMock->method('getShippingAddress')->willReturn($addressMock);
        $addressMock->method('getData')->with(ServicePointAttributes::FIELD_SERVICE_POINT_ID)->willReturn(null);

        $this->assertNull($this->viewModel->getSelectedPoint());
    }

    public function testGetSelectedPointReturnsPointArray(): void
    {
        $quoteMock = $this->createMock(Quote::class);
        $addressMock = $this->createMock(QuoteAddress::class);

        $this->checkoutSessionMock->method('getQuote')->willReturn($quoteMock);
        $quoteMock->method('getShippingAddress')->willReturn($addressMock);

        $addressMock->method('getData')->willReturnCallback(function (string $key) {
            return match ($key) {
                ServicePointAttributes::FIELD_SERVICE_POINT_ID => 'POP-WAW-123',
                ServicePointAttributes::FIELD_SERVICE_POINT_NAME => 'Żabka',
                ServicePointAttributes::FIELD_SERVICE_POINT_STREET => 'Marszałkowska 1',
                ServicePointAttributes::FIELD_SERVICE_POINT_POSTCODE => '00-001',
                ServicePointAttributes::FIELD_SERVICE_POINT_CITY => 'Warszawa',
                ServicePointAttributes::FIELD_IS_PACKSTATION => 0,
                ServicePointAttributes::FIELD_IS_POSTFILIALE => 1,
                ServicePointAttributes::FIELD_POSTNUMMER => null,
                default => null,
            };
        });
        $addressMock->method('getCountryId')->willReturn('PL');

        $point = $this->viewModel->getSelectedPoint();

        $this->assertNotNull($point);
        $this->assertSame('POP-WAW-123', $point['sap']);
        $this->assertSame('Żabka', $point['name']);
        $this->assertSame('Marszałkowska 1', $point['street']);
        $this->assertSame('00-001', $point['postcode']);
        $this->assertSame('Warszawa', $point['city']);
        $this->assertFalse($point['isPackstation']);
        $this->assertTrue($point['isPostfiliale']);
        $this->assertSame('PL', $point['country']);
    }
}
