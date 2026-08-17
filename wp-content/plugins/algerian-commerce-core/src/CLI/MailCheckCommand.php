<?php

declare(strict_types=1);

namespace AlgerianCommerce\CLI;

use AlgerianCommerce\Account\PasswordResetService;
use AlgerianCommerce\Notifications\MailDns;
use AlgerianCommerce\Notifications\MailTransport;
use WP_CLI;

/**
 * wp algerian-commerce mail-check [--to=<address>]
 *
 * Can this shop send email, and does password reset work?
 *
 * **The command exists because the failure is silent.** `wp_mail()` returning
 * true means the message was handed to a transport, never that it arrived, and
 * with no transport configured at all WordPress falls back to PHP's `mail()` —
 * which in these containers fails with `sendmail: can't connect to remote host`
 * printed to a log nobody is reading. Every symptom of "email is broken here"
 * looks identical to "nobody has tried yet".
 *
 * So this reports what is configured, and with `--to` actually sends, which is
 * the only check that can fail for the real reason. It is the thing to run
 * after filling in `SMTP_*`, and the thing to run when a customer says the
 * reset link never came.
 *
 * It prints whether the password is set. It never prints the password.
 */
final class MailCheckCommand
{
    public function __construct(
        private readonly MailTransport $transport,
        private readonly PasswordResetService $reset,
        private readonly MailDns $dns
    ) {
    }

    /**
     * Report the mail configuration, and optionally send a test message.
     *
     * ## OPTIONS
     *
     * [--to=<address>]
     * : Send a test message here. Without it, nothing is sent.
     *
     * [--dkim-selector=<name>]
     * : The DKIM selector to look up. Defaults to Brevo's, which is `brevo`.
     *
     * [--skip-dns]
     * : Do not query SPF, DKIM and DMARC.
     *
     * ## EXAMPLES
     *
     *     wp algerian-commerce mail-check
     *     wp algerian-commerce mail-check --to=you@example.com
     *     wp algerian-commerce mail-check --dkim-selector=s1
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc = []): void
    {
        unset($args);

        $mail = $this->transport->describe();
        $readiness = $this->reset->readiness();

        WP_CLI\Utils\format_items(
            'table',
            [
                ['setting' => 'SMTP_HOST', 'value' => $mail['host'] ?: '(unset)'],
                ['setting' => 'SMTP_PORT', 'value' => (string) $mail['port']],
                ['setting' => 'SMTP_ENCRYPTION', 'value' => (string) $mail['encryption']],
                ['setting' => 'SMTP_USERNAME', 'value' => $mail['username'] ?: '(unset)'],
                // Never the value. Whether it is set is the whole question.
                ['setting' => 'SMTP_PASSWORD', 'value' => $mail['password_set'] ? '(set)' : '(unset)'],
                ['setting' => 'From', 'value' => (string) $mail['from']],
                ['setting' => 'storefront_url', 'value' => $readiness['storefront_url'] ?: '(unset)'],
            ],
            ['setting', 'value']
        );

        if (!$mail['configured']) {
            WP_CLI::warning(
                'No SMTP_HOST, so WordPress will use PHP mail() — which these containers cannot do. '
                . 'Set SMTP_HOST, SMTP_USERNAME and SMTP_PASSWORD in .env, then restart the stack. '
                . 'A variable in .env reaches the plugin only if compose.yaml passes it through.'
            );
        }

        if ($readiness['storefront_url'] === '') {
            WP_CLI::warning(
                'No store.storefront_url, so a password reset link cannot be built. '
                . 'Set it with client.json or PATCH /settings.'
            );
        }

        if ($readiness['ready']) {
            WP_CLI::log('Password reset: ready.');
        } else {
            // Not an error on its own — a shop that has not configured mail yet
            // is a normal state, and the endpoint says so with a 503 rather
            // than pretending to have sent something.
            WP_CLI::log('Password reset: unavailable until both of the above are set.');
        }

        if (!isset($assoc['skip-dns'])) {
            $this->reportDns(
                (string) $mail['from'],
                (string) ($assoc['dkim-selector'] ?? MailDns::DEFAULT_SELECTOR)
            );
        }

        $to = trim((string) ($assoc['to'] ?? ''));

        if ($to === '') {
            WP_CLI::log('Nothing was sent. Pass --to=<address> to send a test message.');

            return;
        }

        if (!is_email($to)) {
            WP_CLI::error("\"{$to}\" is not an email address.");
        }

        $sent = wp_mail(
            $to,
            sprintf('%s — mail check', (string) get_option('blogname', '')),
            "This is a test message from wp algerian-commerce mail-check.\n\n"
            . "If you are reading it, this shop can send email and password reset will work."
        );

        if (!$sent) {
            /*
             * Non-zero, because this runs in deploy scripts. A shop that cannot
             * send mail can still take orders, so this is not a health check —
             * but somebody asked the question, and the answer is no.
             */
            WP_CLI::error(
                'wp_mail() refused the message. Check the credentials and the port, '
                . 'and read the container log: docker compose logs wordpress'
            );
        }

        WP_CLI::success(
            "Handed to the transport for {$to}. That is not proof it arrived — check the inbox."
        );
    }

    /**
     * Is the sending domain's DNS published?
     *
     * Reported rather than enforced: a shop mid-setup, or one whose DNS has not
     * propagated, is a normal state and this command is a diagnostic. It warns
     * and never exits non-zero, because `--to` above is the check that answers
     * the question somebody actually asked.
     */
    private function reportDns(string $from, string $selector): void
    {
        $domain = MailDns::domainOf($from);

        if ($domain === '') {
            WP_CLI::warning(
                'No From address, so SPF, DKIM and DMARC cannot be checked. Set AC_MAIL_FROM in .env.'
            );

            return;
        }

        WP_CLI::log('');
        WP_CLI::log("Sending domain: {$domain}");

        $rows = $this->dns->check($domain, $selector);

        WP_CLI\Utils\format_items('table', $rows, ['record', 'host', 'status', 'detail']);

        foreach ($rows as $row) {
            if ($row['status'] === MailDns::MISSING || $row['status'] === MailDns::PROBLEM) {
                WP_CLI::warning("{$row['record']}: {$row['detail']}");
            }
        }

        if ($this->allUnknown($rows)) {
            WP_CLI::warning(
                'No DNS answers at all — this container probably cannot resolve names. '
                . 'That is not evidence the records are missing; re-run where DNS works.'
            );

            return;
        }

        /*
         * The one combination worth calling out on its own. Credentials that
         * work plus DNS that does not is the state where every send succeeds
         * locally and lands in spam — and §85 turns that from one lost password
         * reset into a campaign to the whole customer list.
         */
        if ($this->transport->isConfigured() && $this->hasGap($rows)) {
            WP_CLI::warning(
                'SMTP is configured but the domain is not fully authenticated. '
                . 'Mail will send and may still be filtered. See docs/DEPLOYMENT.md.'
            );
        }
    }

    /** @param list<array{record: string, host: string, status: string, detail: string}> $rows */
    private function allUnknown(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['status'] !== MailDns::UNKNOWN) {
                return false;
            }
        }

        return $rows !== [];
    }

    /** @param list<array{record: string, host: string, status: string, detail: string}> $rows */
    private function hasGap(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['status'] === MailDns::MISSING || $row['status'] === MailDns::PROBLEM) {
                return true;
            }
        }

        return false;
    }
}
