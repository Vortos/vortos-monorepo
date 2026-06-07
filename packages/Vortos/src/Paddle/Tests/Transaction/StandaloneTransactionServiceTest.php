<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Transaction;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Transaction\Contract\TransactionServiceInterface;
use Vortos\Paddle\Transaction\StandaloneTransactionService;
use Vortos\Paddle\Transaction\Transaction;
use Vortos\Paddle\Transaction\Operation\CreateTransactionRequest;
use Vortos\Paddle\Transaction\Operation\TransactionItemRequest;
use Vortos\Paddle\Transaction\Operation\UpdateTransactionRequest;
use Vortos\Paddle\ValueObject\PaddleCustomerId;
use Vortos\Paddle\ValueObject\PaddlePriceId;
use Vortos\Paddle\ValueObject\PaddleTransactionId;
use Vortos\Paddle\ValueObject\TransactionStatus;

final class StandaloneTransactionServiceTest extends TestCase
{
    private function makeTransaction(string $id = 'txn_test'): Transaction
    {
        return new Transaction(
            id:             PaddleTransactionId::of($id),
            customerId:     PaddleCustomerId::of('ctm_test'),
            subscriptionId: null,
            status:         TransactionStatus::Draft,
            currencyCode:   'USD',
            total:          '1200',
            billedAt:       null,
            createdAt:      new \DateTimeImmutable('2024-01-01'),
            updatedAt:      new \DateTimeImmutable('2024-01-02'),
            lineItems:      [],
        );
    }

    public function test_create_wraps_in_transaction_when_not_active(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($this->once())->method('transactional')
                   ->willReturnCallback(static fn(callable $cb): mixed => $cb());

        $transactional = $this->createMock(TransactionServiceInterface::class);
        $transactional->method('create')->willReturn(PaddleTransactionId::of('txn_new'));

        $service = new StandaloneTransactionService($connection, $transactional);
        $service->create(new CreateTransactionRequest(
            customerId: PaddleCustomerId::of('ctm_123'),
            items:      [new TransactionItemRequest(PaddlePriceId::of('pri_123'), 1)],
        ));
    }

    public function test_create_delegates_directly_when_transaction_active(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects($this->never())->method('transactional');

        $transactional = $this->createMock(TransactionServiceInterface::class);
        $transactional->expects($this->once())
                      ->method('create')
                      ->willReturn(PaddleTransactionId::of('txn_123'));

        $service = new StandaloneTransactionService($connection, $transactional);
        $service->create(new CreateTransactionRequest(
            customerId: PaddleCustomerId::of('ctm_123'),
            items:      [new TransactionItemRequest(PaddlePriceId::of('pri_123'), 1)],
        ));
    }

    public function test_update_wraps_in_transaction_when_not_active(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->expects($this->once())->method('transactional');

        $transactional = $this->createMock(TransactionServiceInterface::class);
        $service       = new StandaloneTransactionService($connection, $transactional);
        $service->update(PaddleTransactionId::of('txn_123'), new UpdateTransactionRequest());
    }

    public function test_get_delegates_to_transactional(): void
    {
        $connection    = $this->createMock(Connection::class);
        $transactional = $this->createMock(TransactionServiceInterface::class);
        $transactional->expects($this->once())
                      ->method('get')
                      ->willReturn($this->makeTransaction('txn_xyz'));

        $service     = new StandaloneTransactionService($connection, $transactional);
        $transaction = $service->get(PaddleTransactionId::of('txn_xyz'));

        $this->assertSame('txn_xyz', $transaction->id->value);
    }

    public function test_list_delegates_to_transactional(): void
    {
        $connection    = $this->createMock(Connection::class);
        $transactional = $this->createMock(TransactionServiceInterface::class);
        $transactional->expects($this->once())
                      ->method('list')
                      ->willReturn([$this->makeTransaction('txn_1'), $this->makeTransaction('txn_2')]);

        $service      = new StandaloneTransactionService($connection, $transactional);
        $transactions = $service->list();

        $this->assertCount(2, $transactions);
        $this->assertContainsOnlyInstancesOf(Transaction::class, $transactions);
    }
}
