<?php

declare(strict_types=1);

namespace AlgerianCommerce\Payments;

use wpdb;

/**
 * The only place that touches the `ac_payment_transactions` table.
 *
 * Rows are **updated**, like a shipment's and unlike the stock ledger's: one row
 * is one payment attempt whose status changes as a gateway learns things about
 * it. What was done to it along the way is in the audit trail, which is the
 * store that is append-only by design — so the row answers "where is this
 * payment" and the trail answers "what has happened to it", and neither has to
 * pretend to be the other.
 */
final class TransactionRepository
{
    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function table(): string
    {
        return $this->wpdb->prefix . 'ac_payment_transactions';
    }

    /** @return int|null the new row id, or null when the insert failed */
    public function insert(Transaction $transaction): ?int
    {
        $inserted = $this->wpdb->insert($this->table(), $transaction->toRow(), $transaction->rowFormats());

        if ($inserted === false) {
            return null;
        }

        return (int) $this->wpdb->insert_id;
    }

    public function update(Transaction $transaction): bool
    {
        if ($transaction->id <= 0) {
            return false;
        }

        $row = $transaction->toRow();

        // A row's order and its birth time are not rewritable: an update that
        // could move created_at would let a status change quietly re-date a
        // payment, and one that could move order_id would let it change whose.
        unset($row['created_at'], $row['order_id']);

        // provider, provider_transaction_id, reference, amount, currency,
        // status, metadata, updated_at — toRow() order, minus the two unset.
        return $this->wpdb->update(
            $this->table(),
            $row,
            ['id' => $transaction->id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        ) !== false;
    }

    public function find(int $id): ?Transaction
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? Transaction::fromRow($row) : null;
    }

    /**
     * The attempt a gateway is talking about.
     *
     * Scoped by provider as well as by id, because an identifier is only unique
     * inside the gateway that issued it — this is the lookup a webhook uses, and
     * the one place a collision between two providers would apply an event to
     * the wrong shop's payment.
     */
    public function findByProviderId(string $provider, string $providerTransactionId): ?Transaction
    {
        $provider = trim($provider);
        $providerTransactionId = trim($providerTransactionId);

        if ($provider === '' || $providerTransactionId === '') {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table()}
                 WHERE provider = %s AND provider_transaction_id = %s
                 ORDER BY id DESC LIMIT 1",
                $provider,
                $providerTransactionId
            ),
            ARRAY_A
        );

        return is_array($row) ? Transaction::fromRow($row) : null;
    }

    /** @return list<Transaction> newest first */
    public function forOrder(int $orderId): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE order_id = %d ORDER BY id DESC",
                $orderId
            ),
            ARRAY_A
        );

        return array_map([Transaction::class, 'fromRow'], is_array($rows) ? $rows : []);
    }

    /**
     * The payment for this order that has already succeeded, if there is one.
     *
     * This is the guard behind "an order is not paid for twice". It is a read,
     * and a read is not a lock — two simultaneous requests could both find
     * nothing. That is deliberate and is not the same gamble migration 006 was
     * fixing for shipments: the cost of losing this race is a second *checkout
     * page*, which expires unclicked in thirty minutes, where the cost of losing
     * the shipping one was a second van. A customer is redirected once and can
     * only be charged twice by paying twice, on purpose.
     */
    public function settledForOrder(int $orderId): ?Transaction
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table()}
                 WHERE order_id = %d AND status = %s
                 ORDER BY id DESC LIMIT 1",
                $orderId,
                PaymentStatus::PAID
            ),
            ARRAY_A
        );

        return is_array($row) ? Transaction::fromRow($row) : null;
    }

    /**
     * Payments still waiting, least recently checked first — the queue
     * `PaymentPoller` works through.
     *
     * Ordered by `updated_at` rather than by id so a capped run moves through
     * the backlog instead of asking about the same rows every time; a row a poll
     * updates goes to the back of the queue by that alone.
     *
     * `$staleBefore` expresses "do not ask about a checkout opened ninety
     * seconds ago" — the customer is still typing their card number.
     *
     * @return list<Transaction>
     */
    public function open(string $provider = '', int $limit = 50, string $staleBefore = ''): array
    {
        $clauses = ['status = %s'];
        $params = [PaymentStatus::PENDING];

        if (trim($provider) !== '') {
            $clauses[] = 'provider = %s';
            $params[] = trim($provider);
        }

        // An attempt the gateway never accepted has no id to ask about, and
        // asking anyway is a guaranteed 404 against somebody's rate limit.
        $clauses[] = "provider_transaction_id <> ''";

        if (trim($staleBefore) !== '') {
            $clauses[] = 'updated_at <= %s';
            $params[] = trim($staleBefore);
        }

        $params[] = max(1, $limit);

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table()}
                 WHERE " . implode(' AND ', $clauses) . '
                 ORDER BY updated_at ASC, id ASC LIMIT %d',
                $params
            ),
            ARRAY_A
        );

        return array_map([Transaction::class, 'fromRow'], is_array($rows) ? $rows : []);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<Transaction>
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $sql = "SELECT * FROM {$this->table()} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";

        $params[] = $perPage;
        $params[] = max(0, ($page - 1) * $perPage);

        // The table name comes from $wpdb->prefix, never from input; every
        // value goes through prepare().
        $rows = $this->wpdb->get_results($this->wpdb->prepare($sql, $params), ARRAY_A);

        return array_map([Transaction::class, 'fromRow'], is_array($rows) ? $rows : []);
    }

    /** @param array<string, mixed> $filters */
    public function count(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);

        $sql = "SELECT COUNT(*) FROM {$this->table()} {$where}";

        return (int) $this->wpdb->get_var($params === [] ? $sql : $this->wpdb->prepare($sql, $params));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        $fields = [
            'order_id' => '%d',
            'provider' => '%s',
            'status' => '%s',
            'provider_transaction_id' => '%s',
            'reference' => '%s',
        ];

        foreach ($fields as $field => $format) {
            if (empty($filters[$field])) {
                continue;
            }

            $clauses[] = "{$field} = {$format}";
            $params[] = $format === '%d' ? (int) $filters[$field] : (string) $filters[$field];
        }

        // Dates arrive as Y-m-d and are widened to cover the whole day, so
        // date_from=date_to=today returns today rather than nothing.
        if (!empty($filters['date_from'])) {
            $clauses[] = 'created_at >= %s';
            $params[] = ((string) $filters['date_from']) . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $clauses[] = 'created_at <= %s';
            $params[] = ((string) $filters['date_to']) . ' 23:59:59';
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }
}
