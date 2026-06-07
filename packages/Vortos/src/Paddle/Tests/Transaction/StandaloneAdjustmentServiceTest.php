<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Transaction;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Transaction\Contract\AdjustmentServiceInterface;
use Vortos\Paddle\Transaction\StandaloneAdjustmentService;
use Vortos\Paddle\Transaction\Operation\AdjustmentItemRequest;
use Vortos\Paddle\Transaction\Operation\CreateRefundRequest;
use Vortos\Paddle\Transaction\Operation\CreateCreditRequest;
use Vortos\Paddle\ValueObject\PaddleAdjustmentId;
use Vortos\Paddle\ValueObject\PaddleTransactionId;

final class StandaloneAdjustmentServiceTest extends TestCase
{
    public function test_create_refund_wraps_in_transaction_when_not_active(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($this->once())->method('transactional')
                   ->willReturnCallback(static fn(callable $cb): mixed => $cb());

        $transactional = $this->createMock(AdjustmentServiceInterface::class);
        $transactional->method('createRefund')->willReturn(PaddleAdjustmentId::of('adj_new'));

        $service = new StandaloneAdjustmentService($connection, $transactional);
        $service->createRefund(new CreateRefundRequest(
            transactionId: PaddleTransactionId::of('txn_123'),
            reason:        'Test',
            items:         [new AdjustmentItemRequest('li_abc', '100')],
        ));
    }

    public function test_create_refund_delegates_directly_when_transaction_active(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects($this->never())->method('transactional');

        $transactional = $this->createMock(AdjustmentServiceInterface::class);
        $transactional->expects($this->once())
                      ->method('createRefund')
                      ->willReturn(PaddleAdjustmentId::of('adj_123'));

        $service = new StandaloneAdjustmentService($connection, $transactional);
        $service->createRefund(new CreateRefundRequest(
            transactionId: PaddleTransactionId::of('txn_123'),
            reason:        'Test',
            items:         [new AdjustmentItemRequest('li_abc', '100')],
        ));
    }

    public function test_create_credit_wraps_in_transaction_when_not_active(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($this->once())->method('transactional')
                   ->willReturnCallback(static fn(callable $cb): mixed => $cb());

        $transactional = $this->createMock(AdjustmentServiceInterface::class);
        $transactional->method('createCredit')->willReturn(PaddleAdjustmentId::of('adj_new'));

        $service = new StandaloneAdjustmentService($connection, $transactional);
        $service->createCredit(new CreateCreditRequest(
            transactionId: PaddleTransactionId::of('txn_123'),
            reason:        'Goodwill',
            items:         [new AdjustmentItemRequest('li_abc', '50')],
        ));
    }
}
