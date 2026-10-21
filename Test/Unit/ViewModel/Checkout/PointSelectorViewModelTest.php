<?php

declare(strict_types=1);

namespace DhlPoland\HyvaDhl\Test\Unit\ViewModel\Checkout;

use DhlPoland\Dhl\Model\Carrier;
use DhlPoland\Dhl\Model\Checkout\ConfigProvider;
use DhlPoland\Dhl\Model\MethodCatalog;
use DhlPoland\Dhl\Model\ServicePoint\ServicePointValidator;
use DhlPoland\HyvaDhl\Model\Checkout\ServicePointSessionRegistry;
use DhlPoland\HyvaDhl\ViewModel\Checkout\PointSelectorViewModel;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class PointSelectorViewModelTest extends TestCase
{
    /**
     * @var MethodCatalog|MockObject
     */
    private MethodCatalog|MockObject $methodCatalogMock;

    /**
     * @var ServicePointValidator|MockObject
     */
    private ServicePointValidator|MockObject $validatorMock;

    /**
     * @var CheckoutSession|MockObject
     */
    private CheckoutSession|MockObject $checkoutSessionMock;

    /**
     * @var SerializerInterface|MockObject
     */
    private SerializerInterface|MockObject $serializerMock;

    /**
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface|MockObject $loggerMock;

    /**
     * @var PointSelectorViewModel
     */
    private PointSelectorViewModel $viewModel;

    protected function setUp(): void
    {
        $this->methodCatalogMock = $this->createMock(MethodCatalog::class);
        $this->methodCatalogMock->method('isCodMethod')->willReturnCallback(
            fn(?string $code) => str_ends_with(strtolower(trim((string) $code)), '_cod')
        );
        $this->validatorMock = $this->createMock(ServicePointValidator::class);
        $this->checkoutSessionMock = $this->createMock(CheckoutSession::class);
        $this->serializerMock = $this->createMock(SerializerInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->viewModel = new PointSelectorViewModel(
            $this->methodCatalogMock,
            $this->validatorMock,
            $this->checkoutSessionMock,
            $this->serializerMock,
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

        $this->assertSame(ServicePointSessionRegistry::DEFAULT_COUNTRY, $this->viewModel->getDestinationCountry());
    }

    public function testGetBaseMapUrlReturnsDefaultMapUrl(): void
    {
        $this->assertSame(ConfigProvider::DEFAULT_MAP_URL, $this->viewModel->getBaseMapUrl());
    }

    public function testGetMapUrlForDomesticAllMethod(): void
    {
        $this->methodCatalogMock->method('isPackstationMethod')->willReturn(false);
        $this->methodCatalogMock->method('isParcelShopMethod')->willReturn(false);

        $url = $this->viewModel->getMapUrl('dhlpoland_economy_all', 'PL');

        $this->assertSame(ConfigProvider::DEFAULT_MAP_URL, $url);
    }

    public function testGetMapUrlForDomesticAllCodMethod(): void
    {
        $this->methodCatalogMock->method('isPackstationMethod')->willReturn(false);
        $this->methodCatalogMock->method('isParcelShopMethod')->willReturn(false);

        $url = $this->viewModel->getMapUrl('dhlpoland_economy_all_cod', 'PL');

        $this->assertSame(ConfigProvider::DEFAULT_MAP_URL . '?options_pickup_cod=T', $url);
    }

    public function testGetMapUrlForDomesticBoxMethod(): void
    {
        $this->methodCatalogMock->method('isPackstationMethod')->willReturn(false);
        $this->methodCatalogMock->method('isParcelShopMethod')->willReturn(false);

        $url = $this->viewModel->getMapUrl('dhlpoland_economy_box', 'PL');

        $this->assertStringContainsString(ConfigProvider::DEFAULT_MAP_URL, $url);
        $this->assertStringContainsString('show_on_map_parcelstation=T', $url);
        $this->assertStringContainsString('show_on_map_parcelshop=N', $url);
        $this->assertStringNotContainsString('options_pickup_cod=T', $url);
    }

    public function testGetMapUrlForDomesticBoxMethodWithCod(): void
    {
        $this->methodCatalogMock->method('isPackstationMethod')->willReturn(false);
        $this->methodCatalogMock->method('isParcelShopMethod')->willReturn(false);

        $url = $this->viewModel->getMapUrl('dhlpoland_economy_box_cod', 'PL');

        $this->assertStringContainsString('options_pickup_cod=T', $url);
        $this->assertStringContainsString('show_on_map_parcelstation=T', $url);
        $this->assertStringContainsString('show_on_map_parcelshop=N', $url);
    }

    public function testGetMapUrlForDomesticPopMethodWithoutCod(): void
    {
        $this->methodCatalogMock->method('isPackstationMethod')->willReturn(false);
        $this->methodCatalogMock->method('isParcelShopMethod')->willReturn(false);

        $url = $this->viewModel->getMapUrl('dhlpoland_economy_pop', 'PL');

        $this->assertStringContainsString('show_on_map_parcelshop=T', $url);
        $this->assertStringContainsString('show_on_map_parcelstation=N', $url);
        $this->assertStringNotContainsString('options_pickup_cod=T', $url);
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

    public function testGetMapUrlForPackstationDefaultsToGermanyWhenCountryIsPoland(): void
    {
        $this->methodCatalogMock->method('isPackstationMethod')->willReturn(true);
        $this->methodCatalogMock->method('isParcelShopMethod')->willReturn(false);

        $url = $this->viewModel->getMapUrl('dhlpoland_connect_packstation', 'PL');

        $this->assertStringContainsString('country=DE', $url);
        $this->assertStringContainsString('ptype=packStation', $url);
    }

    public function testGetMapUrlForInternationalParcelShop(): void
    {
        $this->methodCatalogMock->method('isPackstationMethod')->willReturn(false);
        $this->methodCatalogMock->method('isParcelShopMethod')->willReturn(true);

        $url = $this->viewModel->getMapUrl('dhlpoland_connect_parcelshop', 'AT');

        $this->assertStringContainsString('country=AT', $url);
        $this->assertStringContainsString('ptype=parcelShop', $url);
    }

    public function testGetPointMapTypeResolvesCorrectTypes(): void
    {
        $this->methodCatalogMock->method('isPackstationMethod')->willReturnCallback(
            fn(string $code) => str_contains($code, 'packstation')
        );
        $this->methodCatalogMock->method('isParcelShopMethod')->willReturnCallback(
            fn(string $code) => str_contains($code, 'parcelshop')
        );

        $this->assertSame('box', $this->viewModel->getPointMapType('dhlpoland_economy_box'));
        $this->assertSame('box', $this->viewModel->getPointMapType('dhlpoland_economy_box_cod'));
        $this->assertSame('box', $this->viewModel->getPointMapType('dhlpoland_connect_packstation'));
        $this->assertSame('pop', $this->viewModel->getPointMapType('dhlpoland_economy_pop'));
        $this->assertSame('pop', $this->viewModel->getPointMapType('dhlpoland_economy_pop_cod'));
        $this->assertSame('pop', $this->viewModel->getPointMapType('dhlpoland_connect_parcelshop'));
        $this->assertSame('all', $this->viewModel->getPointMapType('dhlpoland_economy_all'));
        $this->assertSame('all', $this->viewModel->getPointMapType('dhlpoland_economy_all_cod'));
    }

    public function testIsCodMethodIdentifiesCod(): void
    {
        $this->assertTrue($this->viewModel->isCodMethod('dhlpoland_economy_box_cod'));
        $this->assertTrue($this->viewModel->isCodMethod('economy_all_cod'));
        $this->assertFalse($this->viewModel->isCodMethod('dhlpoland_economy_box'));
        $this->assertFalse($this->viewModel->isCodMethod('economy_all'));
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

    public function testEncodeConfigDelegatesToSerializer(): void
    {
        $config = ['mapUrl' => ConfigProvider::DEFAULT_MAP_URL];
        $this->serializerMock->expects($this->once())
            ->method('serialize')
            ->with($config)
            ->willReturn('{"mapUrl":"' . ConfigProvider::DEFAULT_MAP_URL . '"}');

        $this->assertSame(
            '{"mapUrl":"' . ConfigProvider::DEFAULT_MAP_URL . '"}',
            $this->viewModel->encodeConfig($config)
        );
    }
}
