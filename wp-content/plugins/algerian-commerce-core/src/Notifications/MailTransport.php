<?php

declare(strict_types=1);

namespace AlgerianCommerce\Notifications;

use AlgerianCommerce\Core\Config;
use AlgerianCommerce\Core\Logger;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Feeds `.env`'s SMTP settings to WordPress's mailer — docs/PLAN.md §29, §30.
 *
 * **This closes a gap rather than adding a feature.** `SMTP_HOST`,
 * `SMTP_USERNAME` and `SMTP_PASSWORD` were documented in `.env.example` and read
 * by `Config` from the beginning — and passed to nothing. They were not even
 * forwarded into the containers by `compose.yaml`, so `getenv()` returned
 * nothing whatever anyone put in the file. That is precisely the fault CLAUDE.md
 * records §61 finding in the whole `AC_RATE_LIMIT_*` group: **a variable this
 * repository documents and wires to nothing is a knob that turns nothing**, and
 * the person who eventually fills it in has no way to tell.
 *
 * WordPress sends through PHP's `mail()` unless something configures PHPMailer,
 * and there is no MTA in these containers — which is why every send in
 * development fails with `sendmail: can't connect to remote host`. That failure
 * is honest and stays honest: with no `SMTP_HOST` this class does nothing at
 * all, rather than pretending.
 *
 * **`wp_mail()` is still the seam, and `EmailChannel` still calls it.** This
 * configures the transport underneath; it does not replace it. WordPress owns
 * the mailer, plugins hook it, and reimplementing SMTP here would mean owning
 * TLS negotiation and header encoding for no gain.
 *
 * **Nothing here is ever logged.** The password reaches PHPMailer and stops;
 * `describe()` reports whether a value is present, never what it is.
 */
final class MailTransport
{
    /** The two ways a server offers TLS, and they are not interchangeable. */
    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    /** STARTTLS on 587 is the common default; implicit TLS is 465. */
    private const DEFAULT_PORT = 587;
    private const DEFAULT_PORT_SSL = 465;

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger
    ) {
    }

    public function register(): void
    {
        /*
         * Late, so a deployment that installs a dedicated SMTP plugin still
         * wins — this is a default for the common case, not a claim on the
         * transport. A shop that already has a working mail plugin should not
         * have it silently overridden by an empty .env.
         */
        add_action('phpmailer_init', [$this, 'configure'], 5);

        add_filter('wp_mail_from', [$this, 'fromAddress'], 5);
        add_filter('wp_mail_from_name', [$this, 'fromName'], 5);
    }

    public function isConfigured(): bool
    {
        return $this->host() !== '';
    }

    /**
     * Point PHPMailer at the configured server.
     *
     * @param PHPMailer $mailer by reference, as WordPress passes it
     */
    public function configure(mixed $mailer): void
    {
        if (!$mailer instanceof PHPMailer || !$this->isConfigured()) {
            return;
        }

        $mailer->isSMTP();
        $mailer->Host = $this->host();
        $mailer->Port = $this->port();

        $username = (string) $this->config->get('SMTP_USERNAME', '');
        $password = (string) $this->config->secret('SMTP_PASSWORD');

        /*
         * Authentication is on only when there is a username. A relay on a
         * private network legitimately takes none, and forcing SMTPAuth with
         * empty credentials fails the handshake rather than sending anonymously.
         */
        if ($username !== '') {
            $mailer->SMTPAuth = true;
            $mailer->Username = $username;
            $mailer->Password = $password;
        }

        $encryption = $this->encryption();

        if ($encryption === 'none') {
            $mailer->SMTPSecure = '';
            $mailer->SMTPAutoTLS = false;
        } else {
            $mailer->SMTPSecure = $encryption;
        }
    }

    public function fromAddress(mixed $default): mixed
    {
        $configured = (string) $this->config->get('AC_MAIL_FROM', '');

        return $configured !== '' && is_email($configured) ? $configured : $default;
    }

    public function fromName(mixed $default): mixed
    {
        // The shop's own name, which §71 keeps in WordPress rather than in a
        // copy — so a client that renames itself renames its mail too.
        $name = (string) get_option('blogname', '');

        return $name !== '' ? $name : $default;
    }

    /**
     * What is configured, for `wp algerian-commerce mail-check` — and never
     * what the password is.
     *
     * @return array<string, string|int|bool>
     */
    public function describe(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'host' => $this->host(),
            'port' => $this->port(),
            'encryption' => $this->encryption(),
            'username' => (string) $this->config->get('SMTP_USERNAME', ''),
            'password_set' => (string) $this->config->secret('SMTP_PASSWORD') !== '',
            'from' => (string) ($this->fromAddress(get_option('admin_email', '')) ?: ''),
        ];
    }

    public function host(): string
    {
        return trim((string) $this->config->get('SMTP_HOST', ''));
    }

    /**
     * Public so the derivation is a unit test rather than something you find
     * out from a mail server that connects and then hangs.
     */
    public function port(): int
    {
        $configured = (int) $this->config->get('SMTP_PORT', '0');

        if ($configured > 0) {
            return $configured;
        }

        // Derived from the encryption rather than fixed, because 465 with
        // STARTTLS and 587 with implicit TLS are both configurations that
        // connect and then hang.
        return $this->encryption() === 'ssl' ? self::DEFAULT_PORT_SSL : self::DEFAULT_PORT;
    }

    /** Public for the same reason as port(). */
    public function encryption(): string
    {
        $value = strtolower(trim((string) $this->config->get('SMTP_ENCRYPTION', 'tls')));

        if (!in_array($value, self::ENCRYPTIONS, true)) {
            $this->logger->warning('Unknown SMTP_ENCRYPTION; falling back to tls', [
                'value' => $value,
                'allowed' => self::ENCRYPTIONS,
            ]);

            return 'tls';
        }

        return $value;
    }
}
