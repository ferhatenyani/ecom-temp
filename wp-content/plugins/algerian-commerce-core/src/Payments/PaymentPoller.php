<?php

declare(strict_types=1);

namespace AlgerianCommerce\Payments;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Core\Logger;

/**
 * Asks every gateway what became of the payments still waiting — roadmap §59.
 *
 * **This exists because of one gap in the webhook rule.** docs/SECURITY.md sets
 * a five-minute timestamp tolerance, and Chargily does not document how long it
 * keeps retrying a delivery — so a genuine retry arriving late is refused, and
 * quite right: the alternative is accepting an event captured off the wire last
 * week. The cost of that strictness has to land somewhere, and it lands here. A
 * webhook this shop turned away, or never received because it was down, becomes
 * a few minutes' delay instead of a payment nobody ever recorded.
 *
 * It covers three other holes the same way, all of them ordinary:
 *
 * ```
 * the customer closed the tab before the redirect came back
 * the shop was unreachable for the whole retry window
 * a checkout expired quietly, which no provider need announce at all
 * ```
 *
 * Provider-agnostic on purpose: it knows `PaymentProviderInterface` through
 * `PaymentService`, so a second gateway is polled by this same class the day its
 * adapter is registered. Cash on delivery is polled too and answers `pending`
 * forever, which is honest and costs nothing — there is no network call behind
 * it.
 *
 * **No `Permissions::assert()`, and no route.** This runs from WP-CLI and from
 * cron, where there is no user to check — the same position as `ShipmentPoller`.
 * Nothing here accepts a status from a caller: every value written comes from a
 * gateway that was asked directly.
 */
final class PaymentPoller
{
    /**
     * How long a `pending` payment is chased before it is given up on.
     *
     * A Chargily checkout dies of its own accord after thirty minutes, so a day
     * is far past generous — it is a backstop against polling a dead row hourly
     * forever, which would fill the queue and starve the payments that are
     * actually live. See `abandon()` on why this is a safe thing to write down
     * rather than a guess about money.
     */
    public const ABANDON_AFTER_HOURS = 24;

    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly PaymentService $payments,
        private readonly Logger $logger
    ) {
    }

    /**
     * @param string $provider  only this gateway, or all of them when empty
     * @param int    $limit     payments per run
     * @param int    $minMinutes leave a payment alone if it was checked more
     *                           recently than this — a customer three minutes
     *                           into typing a card number has not finished
     *
     * @return array{checked: int, updated: int, unchanged: int, abandoned: int, failed: int, problems: list<string>}
     */
    public function run(string $provider = '', int $limit = 50, int $minMinutes = 5): array
    {
        $staleBefore = $minMinutes > 0
            ? gmdate('Y-m-d H:i:s', time() - ($minMinutes * 60))
            : '';

        $report = [
            'checked' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'abandoned' => 0,
            'failed' => 0,
            'problems' => [],
        ];

        foreach ($this->transactions->open($provider, $limit, $staleBefore) as $transaction) {
            $report['checked']++;

            try {
                $before = $transaction->status;
                $this->payments->refresh($transaction);

                $after = $this->transactions->find($transaction->id);

                if ($after !== null && $after->status !== $before) {
                    $report['updated']++;

                    continue;
                }

                if ($this->abandon($transaction)) {
                    $report['abandoned']++;

                    continue;
                }

                $report['unchanged']++;
            } catch (ApiException $exception) {
                $report['failed']++;
                $report['problems'][] = sprintf(
                    '#%d (%s %s): %s',
                    $transaction->id,
                    $transaction->provider,
                    $transaction->providerTransactionId,
                    $exception->getMessage()
                );

                $this->logger->warning('Payment poll failed', [
                    'transaction_id' => $transaction->id,
                    'provider' => $transaction->provider,
                    'error' => $exception->errorCode(),
                ]);

                /*
                 * One gateway being rate limited or refusing our key says
                 * nothing useful about the next payment in the queue, but
                 * hammering it for another forty-nine does. Those two answers
                 * end the run; an amount mismatch does not, because it is about
                 * one order and a human has already been told.
                 */
                if (in_array($exception->errorCode(), ['provider_rate_limited', 'provider_auth_failed'], true)) {
                    break;
                }
            }
        }

        return $report;
    }

    /**
     * Stop chasing a payment that is never going to change.
     *
     * This writes `expired` on a row the gateway still calls `pending`, which
     * looks like inventing a fact about money and is worth being explicit about.
     * It is not, for one reason: a hosted checkout has a documented lifetime
     * measured in minutes, so a day later there is no state in which a customer
     * can still pay it. What is being recorded is *this shop has given up
     * asking*, and the gateway's own last word is kept beside it in `metadata`
     * so the disagreement is visible rather than erased.
     *
     * It is also strictly one-directional and safe against a late truth: if
     * Chargily ever does report `paid` for this checkout, `expired` is terminal
     * and `PaymentStatus::accepts()` will refuse to move it — so the money would
     * not be silently swallowed, it would show up as a payment the shop can see
     * and a human can settle. The opposite ordering — leaving it `pending` — is
     * the one that hides things, because the row stays in a queue nobody reads.
     */
    private function abandon(Transaction $transaction): bool
    {
        $opened = strtotime($transaction->createdAt . ' UTC');

        if ($opened === false || time() - $opened < self::ABANDON_AFTER_HOURS * 3600) {
            return false;
        }

        $abandoned = $transaction->withStatus(PaymentStatus::EXPIRED, gmdate('Y-m-d H:i:s'), [
            'abandoned_by' => 'poller',
            'abandoned_after_hours' => self::ABANDON_AFTER_HOURS,
        ]);

        if (!$this->transactions->update($abandoned)) {
            return false;
        }

        $this->logger->info('Payment abandoned after no answer', [
            'transaction_id' => $transaction->id,
            'provider' => $transaction->provider,
        ]);

        return true;
    }
}
