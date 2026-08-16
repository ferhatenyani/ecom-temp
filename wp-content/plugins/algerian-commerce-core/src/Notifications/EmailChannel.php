<?php

declare(strict_types=1);

namespace AlgerianCommerce\Notifications;

use AlgerianCommerce\Core\Logger;

/**
 * Email — docs/PLAN.md §30, the one channel this project can configure.
 *
 * `wp_mail()` rather than an SMTP library. WordPress already owns the transport,
 * the `SMTP_*` settings in `.env` reach it through whatever mail plugin or
 * `phpmailer_init` hook a deployment uses, and reimplementing it would mean
 * owning TLS negotiation and header encoding that core already does — the
 * argument `Auth/AuthService` makes about credentials, applied to mail.
 *
 * **`wp_mail()` returning true does not mean the message arrived.** It means the
 * message was handed to the transport without an error. That is the strongest
 * claim this channel can make and the row is marked `sent` on it; delivery
 * confirmation would need a provider with a webhook, which is §29's "potential
 * channels" list rather than this one.
 *
 * In development there is no SMTP server, so `wp_mail()` fails with
 * `sendmail: can't connect` and the row stays `pending` with the reason in
 * `last_error`. That is the correct behaviour rather than a test failure: the
 * queue is doing its job, and `scripts/test.sh` never asserts a successful send
 * because asserting one would require a mail server the suite does not have.
 */
final class EmailChannel implements NotificationChannelInterface
{
    public const NAME = 'email';

    public function __construct(
        private readonly Logger $logger,
        private readonly string $fromName = '',
        private readonly string $fromAddress = ''
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(Notification $notification): bool
    {
        return filter_var($notification->recipient, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function send(Notification $notification): NotificationResult
    {
        if (!$this->supports($notification)) {
            // Permanent: a malformed address will not become valid on a retry,
            // and leaving it retryable keeps a dead row at the head of the queue.
            return NotificationResult::rejected('Not a deliverable email address.');
        }

        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        if ($this->fromAddress !== '') {
            $from = $this->fromName !== ''
                ? sprintf('%s <%s>', $this->fromName, $this->fromAddress)
                : $this->fromAddress;
            $headers[] = 'From: ' . $from;
        }

        $sent = wp_mail($notification->recipient, $notification->subject, $notification->body, $headers);

        if ($sent) {
            // The recipient is deliberately not logged. docs/SECURITY.md is
            // explicit that customer PII does not go into logs beyond what an
            // audit record needs, and "which customer was emailed about which
            // order" is exactly that.
            $this->logger->info('Notification sent', [
                'channel' => self::NAME,
                'event' => $notification->event,
                'subject_id' => $notification->subjectId,
            ]);

            return NotificationResult::sent();
        }

        return NotificationResult::failed('wp_mail() did not accept the message.');
    }
}
