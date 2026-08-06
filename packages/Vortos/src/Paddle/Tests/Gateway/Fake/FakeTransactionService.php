<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Gateway\Fake;

use Vortos\Paddle\Transaction\Contract\ImmediateTransactionServiceInterface;
use Vortos\Paddle\Transaction\Operation\CreateTransactionRequest;
use Vortos\Paddle\Transaction\Operation\UpdateTransactionRequest;
use Vortos\Paddle\Transaction\PayoutTotals;
use Vortos\Paddle\Transaction\Transaction;
use Vortos\Paddle\Transaction\TransactionLineItem;
use Vortos\Paddle\Transaction\TransactionPreviewResult;
use Vortos\Paddle\ValueObject\PaddleCustomerId;
use Vortos\Paddle\ValueObject\PaddleTransactionId;
use Vortos\Paddle\ValueObject\TransactionStatus;

/**
 * A transaction service that records what it was asked for.
 *
 * `$created` is the assertion surface: it is how a test proves the adapter sent
 * the exact minor-unit amounts it was given, rather than something that merely
 * displays the same.
 */
final class FakeTransactionService implements ImmediateTransactionServiceInterface
{
    public const SETTLED_ID = 'txn_settled';

    /** @var list<CreateTransactionRequest> */
    public array $created = [];

    public function create(CreateTransactionRequest $request): PaddleTransactionId
    {
        $this->created[] = $request;

        return PaddleTransactionId::of('txn_fake_' . count($this->created));
    }

    public function get(PaddleTransactionId $id): Transaction
    {
        $billedAt = new \DateTimeImmutable('2026-08-01T10:00:00+00:00');

        return new Transaction(
            id:             $id,
            customerId:     PaddleCustomerId::of('ctm_fake'),
            subscriptionId: null,
            status:         TransactionStatus::Completed,
            currencyCode:   'USD',
            total:          '2000',
            billedAt:       $billedAt,
            createdAt:      $billedAt,
            updatedAt:      $billedAt,
            lineItems:      [
                new TransactionLineItem('txnitm_1', 'pri_1', 1, '1800', '1800', '0'),
                new TransactionLineItem('txnitm_2', 'pri_2', 1, '200', '200', '0'),
            ],
            payoutTotals:   new PayoutTotals(
                subtotal:     '2000',
                discount:     '0',
                tax:          '0',
                total:        '2000',
                credit:       '0',
                balance:      '0',
                grandTotal:   '2000',
                fee:          '150',
                earnings:     '1850',
                currencyCode: 'USD',
                feeRate:      '0.05',
                exchangeRate: '1',
            ),
            customData:     ['reference' => 'reg-conformance-1'],
        );
    }

    public function update(PaddleTransactionId $id, UpdateTransactionRequest $request): void
    {
        throw new \LogicException('The gateway adapter does not update transactions.');
    }

    public function preview(CreateTransactionRequest $request): TransactionPreviewResult
    {
        throw new \LogicException('The gateway adapter does not preview transactions.');
    }

    public function getInvoicePdfUrl(PaddleTransactionId $id): string
    {
        throw new \LogicException('The gateway adapter does not fetch invoices.');
    }

    public function list(): array
    {
        throw new \LogicException('The gateway adapter does not list transactions.');
    }
}
