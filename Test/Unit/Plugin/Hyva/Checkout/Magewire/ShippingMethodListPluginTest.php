<?php

declare(strict_types=1);

namespace DhlPoland\HyvaDhl\Test\Unit\Plugin\Hyva\Checkout\Magewire;

use DhlPoland\Dhl\Model\Carrier;
use DhlPoland\Dhl\Model\MethodCatalog;
use DhlPoland\Dhl\Model\ServicePoint\ServicePointValidator;
use DhlPoland\Dhl\Model\ServicePoint\ServicePointValueObject;
use DhlPoland\HyvaDhl\Model\Checkout\ServicePointSessionRegistry;
use DhlPoland\HyvaDhl\Model\ServicePointAttributes;
use DhlPoland\HyvaDhl\Plugin\Hyva\Checkout\Magewire\ShippingMethodListPlugin;
use Hyva\Checkout\Magewire\Checkout\Shipping\MethodList;
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
     * @var ServicePointValidator|MockObject
     */
    private ServicePointValidator|MockObject $validatorMock;

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
     * @var EvaluationResultFactory|MockObject
     */
    private EvaluationResultFactory|MockObject $evaluationResultFactoryMock;

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
        $this->validatorMock = $this->createMock(ServicePointValidator::class);
        $this->methodCatalogMock = $this->createMock(MethodCatalog::class);
        $this->sessionRegistryMock = $this->createMock(ServicePointSessionRegistry::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->evaluationResultFactoryMock = $this->createMock(EvaluationResultFactory::class);
        $this->subjectMock = $this->createMock(MethodList::class);
        $this->quoteMock = $this->createMock(Quote::class);
        $this->shippingAddressMock = $this->createMock(QuoteAddress::class);

        $this->checkoutSessionMock->method('getQuote')->willReturn($this->quoteMock);
        $this->quoteMock->method('getShippingAddress')->willReturn($this->shippingAddressMock);

        $this->plugin = new ShippingMethodListPlugin(
            $this->checkoutSessionMock,
            $this->quoteRepositoryMock,
            $this->validatorMock,
            $this->methodCatalogMock,
            $this->sessionRegistryMock,
            $this->loggerMock
        );
    }

    public function testAfterEvaluateCompletionPassesThroughWhenNonDhlMethod(): void
    {
        $this->subjectMock->method = 'flatrate_flatrate';
        $successMock = $this->createMock(Success::class);

        $result = $this->plugin->afterEvaluateCompletion(
            $this->subjectMock,
            $successMock,
            $this->evaluationResultFactoryMock
        );

        $this->assertSame($successMock, $result);
    }

    public function testAfterEvaluateCompletionReturnsErrorWhenDhlPointValidationFails(): void
    {
        $this->subjectMock->method = 'dhlpoland_economy_box';
        $this->methodCatalogMock->method('isServicePointMethod')->with('economy_box')->willReturn(true);

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

        $result = $this->plugin->afterEvaluateCompletion(
            $this->subjectMock,
            $this->createMock(Success::class),
            $this->evaluationResultFactoryMock
        );

        $this->assertSame($errorMock, $result);
    }

    public function testAfterUpdatedMethodClearsServicePointWhenSwitchingBetweenPointMethods(): void
    {
        $this->shippingAddressMock->method('getData')
            ->with(ServicePointAttributes::FIELD_SERVICE_POINT_ID)
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
        $this->assertArrayHasKey(ServicePointAttributes::FIELD_SERVICE_POINT_ID, $savedData);
        $this->assertNull($savedData[ServicePointAttributes::FIELD_SERVICE_POINT_ID]);
    }

    public function testAfterUpdatedMethodClearsServicePointWhenSwitchingToNonPointMethod(): void
    {
        $this->shippingAddressMock->method('getData')
            ->with(ServicePointAttributes::FIELD_SERVICE_POINT_ID)
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
        $this->assertArrayHasKey(ServicePointAttributes::FIELD_SERVICE_POINT_ID, $savedData);
        $this->assertNull($savedData[ServicePointAttributes::FIELD_SERVICE_POINT_ID]);
    }

    public function testAfterUpdatedMethodDoesNotSaveQuoteWhenNoPointExisted(): void
    {
        $this->shippingAddressMock->method('getData')
            ->with(ServicePointAttributes::FIELD_SERVICE_POINT_ID)
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
        $this->assertSame('SAVED-BOX-01', $savedData[ServicePointAttributes::FIELD_SERVICE_POINT_ID]);
        $this->assertSame('Saved Box Point', $savedData[ServicePointAttributes::FIELD_SERVICE_POINT_NAME]);
    }
}
