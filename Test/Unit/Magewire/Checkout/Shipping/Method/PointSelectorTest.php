<?php

declare(strict_types=1);

namespace DhlPoland\HyvaDhl\Test\Unit\Magewire\Checkout\Shipping\Method;

use DhlPoland\Dhl\Model\Carrier;
use DhlPoland\Dhl\Model\MethodCatalog;
use DhlPoland\Dhl\Model\ServicePoint\ServicePointValidator;
use DhlPoland\Dhl\Model\ServicePoint\ServicePointValueObject;
use DhlPoland\HyvaDhl\Magewire\Checkout\Shipping\Method\PointSelector;
use DhlPoland\HyvaDhl\Model\ServicePointAttributes;
use Hyva\Checkout\Model\Magewire\Component\Evaluation\ErrorEventMessage;
use Hyva\Checkout\Model\Magewire\Component\Evaluation\Success;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class PointSelectorTest extends TestCase
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
     * @var ServicePointValidator|MockObject
     */
    private ServicePointValidator|MockObject $validatorMock;

    /**
     * @var MethodCatalog|MockObject
     */
    private MethodCatalog|MockObject $methodCatalogMock;

    /**
     * @var LoggerInterface|MockObject
     */
    private LoggerInterface|MockObject $loggerMock;

    /**
     * @var EvaluationResultFactory|MockObject
     */
    private EvaluationResultFactory|MockObject $evaluationResultFactoryMock;

    /**
     * @var Quote|MockObject
     */
    private Quote|MockObject $quoteMock;

    /**
     * @var QuoteAddress|MockObject
     */
    private QuoteAddress|MockObject $shippingAddressMock;

    /**
     * @var PointSelector
     */
    private PointSelector $pointSelector;

    protected function setUp(): void
    {
        $this->checkoutSessionMock = $this->createMock(CheckoutSession::class);
        $this->quoteRepositoryMock = $this->createMock(CartRepositoryInterface::class);
        $this->validatorMock = $this->createMock(ServicePointValidator::class);
        $this->methodCatalogMock = $this->createMock(MethodCatalog::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->evaluationResultFactoryMock = $this->createMock(EvaluationResultFactory::class);
        $this->quoteMock = $this->createMock(Quote::class);
        $this->shippingAddressMock = $this->createMock(QuoteAddress::class);

        $this->checkoutSessionMock->method('getQuote')->willReturn($this->quoteMock);
        $this->quoteMock->method('getShippingAddress')->willReturn($this->shippingAddressMock);

        $this->pointSelector = new PointSelector(
            $this->checkoutSessionMock,
            $this->quoteRepositoryMock,
            $this->validatorMock,
            $this->methodCatalogMock,
            $this->loggerMock
        );
    }

    public function testMountInitializesPropertiesFromShippingAddress(): void
    {
        $this->shippingAddressMock->method('getData')->willReturnCallback(function (string $key) {
            return match ($key) {
                ServicePointAttributes::FIELD_SERVICE_POINT_ID => 'POP-WAW-123',
                ServicePointAttributes::FIELD_SERVICE_POINT_NAME => 'Żabka Marszałkowska',
                ServicePointAttributes::FIELD_SERVICE_POINT_STREET => 'Marszałkowska 10',
                ServicePointAttributes::FIELD_SERVICE_POINT_POSTCODE => '00-001',
                ServicePointAttributes::FIELD_SERVICE_POINT_CITY => 'Warszawa',
                ServicePointAttributes::FIELD_IS_PACKSTATION => 1,
                ServicePointAttributes::FIELD_IS_POSTFILIALE => 0,
                ServicePointAttributes::FIELD_POSTNUMMER => '12345678',
                default => null,
            };
        });
        $this->shippingAddressMock->method('getCountryId')->willReturn('DE');
        $this->shippingAddressMock->method('getShippingMethod')->willReturn('dhlpoland_connect_packstation');

        $this->pointSelector->mount();

        $this->assertSame('POP-WAW-123', $this->pointSelector->pointId);
        $this->assertSame('Żabka Marszałkowska', $this->pointSelector->pointName);
        $this->assertSame('Marszałkowska 10', $this->pointSelector->pointStreet);
        $this->assertSame('00-001', $this->pointSelector->pointPostcode);
        $this->assertSame('Warszawa', $this->pointSelector->pointCity);
        $this->assertTrue($this->pointSelector->isPackstation);
        $this->assertFalse($this->pointSelector->isPostfiliale);
        $this->assertSame('12345678', $this->pointSelector->postnummer);
        $this->assertSame('DE', $this->pointSelector->country);
    }

    public function testSelectPointSavesPointToAddressAndQuote(): void
    {
        $pointData = [
            'sap' => 'BOX-KRK-999',
            'name' => 'DHL Box Automat',
            'street' => 'Floriańska',
            'streetNo' => '15',
            'zip' => '31-019',
            'city' => 'Kraków',
            'country' => 'PL',
            'isPackstation' => true,
            'isPostfiliale' => false,
            'postnummer' => null,
        ];

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

        $this->pointSelector->selectPoint($pointData);

        $this->assertSame('BOX-KRK-999', $this->pointSelector->pointId);
        $this->assertSame('DHL Box Automat', $this->pointSelector->pointName);
        $this->assertSame('Floriańska 15', $this->pointSelector->pointStreet);
        $this->assertSame('31-019', $this->pointSelector->pointPostcode);
        $this->assertSame('Kraków', $this->pointSelector->pointCity);
        $this->assertTrue($this->pointSelector->isPackstation);
        $this->assertFalse($this->pointSelector->isPostfiliale);

        $this->assertSame('BOX-KRK-999', $savedData[ServicePointAttributes::FIELD_SERVICE_POINT_ID] ?? null);
        $this->assertSame('Floriańska 15', $savedData[ServicePointAttributes::FIELD_SERVICE_POINT_STREET] ?? null);
    }

    public function testClearPointClearsAddressDataAndQuote(): void
    {
        $this->pointSelector->pointId = 'BOX-KRK-999';
        $this->pointSelector->pointName = 'DHL Box';

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

        $this->pointSelector->clearPoint();

        $this->assertNull($this->pointSelector->pointId);
        $this->assertNull($this->pointSelector->pointName);
        $this->assertNull($savedData[ServicePointAttributes::FIELD_SERVICE_POINT_ID]);
    }

    public function testUpdatedPostnummerSavesToAddressAndQuote(): void
    {
        $this->shippingAddressMock->expects($this->once())
            ->method('setData')
            ->with(ServicePointAttributes::FIELD_POSTNUMMER, '98765432');

        $this->quoteRepositoryMock->expects($this->once())
            ->method('save')
            ->with($this->quoteMock);

        $res = $this->pointSelector->updatedPostnummer('98765432');
        $this->assertSame('98765432', $res);
        $this->assertSame('98765432', $this->pointSelector->postnummer);
    }

    public function testEvaluateCompletionReturnsSuccessWhenValidatorPasses(): void
    {
        $this->shippingAddressMock->method('getShippingMethod')->willReturn('dhlpoland_economy_box');
        $this->shippingAddressMock->method('getData')->willReturnCallback(function (string $key) {
            return match ($key) {
                ServicePointAttributes::FIELD_SERVICE_POINT_ID => 'BOX-123',
                ServicePointAttributes::FIELD_SERVICE_POINT_NAME => 'Box',
                ServicePointAttributes::FIELD_SERVICE_POINT_STREET => 'Street 1',
                ServicePointAttributes::FIELD_SERVICE_POINT_POSTCODE => '00-001',
                ServicePointAttributes::FIELD_SERVICE_POINT_CITY => 'City',
                default => null,
            };
        });

        $this->validatorMock->expects($this->once())
            ->method('validate')
            ->with(Carrier::CODE, 'economy_box', $this->isInstanceOf(ServicePointValueObject::class));

        $successMock = $this->createMock(Success::class);
        $this->evaluationResultFactoryMock->expects($this->once())
            ->method('createSuccess')
            ->willReturn($successMock);

        $result = $this->pointSelector->evaluateCompletion($this->evaluationResultFactoryMock);
        $this->assertSame($successMock, $result);
    }

    public function testEvaluateCompletionReturnsErrorWhenValidatorThrowsException(): void
    {
        $this->shippingAddressMock->method('getShippingMethod')->willReturn('dhlpoland_economy_box');

        $this->validatorMock->expects($this->once())
            ->method('validate')
            ->willThrowException(new LocalizedException(__('Please select a DHL pickup point on the map.')));

        $errorMock = $this->createMock(ErrorEventMessage::class);
        $errorMock->expects($this->once())
            ->method('withCustomEvent')
            ->with(ServicePointAttributes::EVENT_SHIPPING_METHOD_ERROR)
            ->willReturnSelf();
        $errorMock->expects($this->once())
            ->method('withMessage')
            ->willReturnSelf();

        $this->evaluationResultFactoryMock->expects($this->once())
            ->method('createErrorMessageEvent')
            ->willReturn($errorMock);

        $result = $this->pointSelector->evaluateCompletion($this->evaluationResultFactoryMock);
        $this->assertSame($errorMock, $result);
    }

    public function testBootSyncsPropertiesFromShippingAddress(): void
    {
        $this->shippingAddressMock->method('getData')->willReturnCallback(function (string $key) {
            return match ($key) {
                ServicePointAttributes::FIELD_SERVICE_POINT_ID => 'POP-KRK-1',
                ServicePointAttributes::FIELD_SERVICE_POINT_NAME => 'POP Kraków',
                default => null,
            };
        });

        $this->pointSelector->boot();

        $this->assertSame('POP-KRK-1', $this->pointSelector->pointId);
        $this->assertSame('POP Kraków', $this->pointSelector->pointName);
    }

    public function testSyncFromQuoteResetsPropertiesWhenPointClearedOnAddress(): void
    {
        $this->pointSelector->pointId = 'PREVIOUS-POINT';
        $this->pointSelector->pointName = 'Previous Point';

        $this->shippingAddressMock->method('getData')->willReturnCallback(function (string $key) {
            return match ($key) {
                ServicePointAttributes::FIELD_SERVICE_POINT_ID => '',
                default => null,
            };
        });

        $this->pointSelector->syncFromQuote();

        $this->assertNull($this->pointSelector->pointId);
        $this->assertSame('', $this->pointSelector->pointName);
    }
}
