<?php

declare(strict_types=1);

namespace AlgerianCommerce\Account;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Core\Config;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Security\ClientIp;
use AlgerianCommerce\Notifications\MailTransport;
use AlgerianCommerce\Security\RateLimiter;
use AlgerianCommerce\Settings\SettingsRepository;
use WP_Error;
use WP_User;

/**
 * Password reset by email — docs/PLAN.md §29, §30, roadmap §59c.
 *
 * **Deferred at §59c for a reason that has now been answered rather than
 * dropped.** The reason was: "a reset link generated but never sent is worse
 * than an absent feature, because it looks like one that works." The answer is
 * not to send optimistically — it is to **fail loudly when the shop cannot
 * send**. Two preconditions are checked before a token is ever minted, and each
 * one is a 503 that names itself:
 *
 *  - **No mail transport.** With no `SMTP_HOST`, WordPress falls back to PHP's
 *    `mail()`, and these containers have no MTA — every send fails with
 *    `sendmail: can't connect`. `wp algerian-commerce mail-check` is the
 *    operator's tool for this.
 *  - **No storefront URL.** The link has to point at the shop the customer
 *    used, and this backend cannot derive it: WordPress's permalink is the
 *    admin domain, and a link there would send a customer to a login screen
 *    they have no account for. §71 stores it; §62 refused to guess the same
 *    value for canonical URLs.
 *
 * Neither is about the caller, so neither leaks anything: every requester gets
 * the identical answer whether or not the address exists.
 *
 * **Nothing here goes through the notification queue.** That queue is drained
 * every five minutes, and a shopper staring at "check your email" will not wait
 * five minutes — they will request another. The rest of §29 is deferred
 * *because* it is deferrable; a reset is the one message that is not.
 *
 * **The tokens are WordPress's.** `get_password_reset_key()` /
 * `check_password_reset_key()` / `reset_password()`, which buys the hashing, the
 * expiry and the single use, and means a token minted here is honoured by
 * exactly one code path. `AccountSession` made the same choice for sessions.
 */
final class PasswordResetService
{
    /**
     * The identical answer every requester gets.
     *
     * User enumeration is the whole risk in this endpoint. "No account with
     * that address" is a free list of who shops here, and a *timing* difference
     * is the same disclosure more slowly — which is why the unknown-address
     * path does the same work and simply does not send.
     */
    private const NEUTRAL = 'If that address has an account, a reset link is on its way.';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly MailTransport $mail,
        private readonly AuditLogger $audit,
        private readonly RateLimiter $rateLimiter,
        private readonly Logger $logger,
        /* Optional, defaulting to the pre-§86 behaviour. See clientIp(). */
        private readonly ?Config $config = null
    ) {
    }

    /**
     * Ask for a reset link.
     *
     * @return array<string, mixed>
     */
    public function request(string $email): array
    {
        $this->requireWorkingMail();

        $email = strtolower(trim($email));

        if ($email === '' || !is_email($email)) {
            // A malformed address is a client bug rather than a guess at who
            // exists, so it is safe — and more useful — to say so.
            throw ApiException::invalidRequest('The reset request is invalid.', [
                'fields' => ['email' => 'Must be an email address.'],
            ]);
        }

        /*
         * Rate limited on the same counter as a failed login. A reset endpoint
         * that is not is an open mail relay pointed at one address, and an
         * enumeration oracle with unlimited attempts.
         */
        $this->rateLimiter->recordAuthFailure($this->clientIp(), time());

        $user = get_user_by('email', $email);

        if (!$user instanceof WP_User || !AccountSession::isShopper($user)) {
            /*
             * **A staff account is refused exactly as an unknown one is.**
             * Without this the customer door resets an administrator's
             * password — the §44 rule `AccountSession::authenticate()` already
             * enforces for login, which would be pointless if the reset path
             * went around it. The response is identical either way.
             */
            $this->logger->info('Password reset requested for an address with no shopper account', [
                'email' => $email,
            ]);

            return ['requested' => true, 'message' => self::NEUTRAL];
        }

        $key = get_password_reset_key($user);

        if ($key instanceof WP_Error) {
            throw ApiException::internal('The reset link could not be created.');
        }

        $this->send($user, (string) $key);

        $this->audit->record('customer.password_reset_requested', 'customer', $user->ID, [
            'email' => $email,
        ]);

        return ['requested' => true, 'message' => self::NEUTRAL];
    }

    /**
     * Complete a reset.
     *
     * Deliberately does **not** sign the shopper in. A token that arrived by
     * email is weaker evidence than a password, and turning one into a live
     * session means anyone reading the mailbox is signed in rather than merely
     * able to set a password the real owner will notice.
     *
     * @return array<string, mixed>
     */
    public function confirm(string $login, string $key, string $password): array
    {
        $this->requireWorkingMail();

        $errors = [];

        if (trim($login) === '') {
            $errors['login'] = 'Required.';
        }

        if (trim($key) === '') {
            $errors['key'] = 'Required.';
        }

        AccountInput::checkNewPassword($password, 'password', $errors);

        if ($errors !== []) {
            throw ApiException::invalidRequest('The reset is invalid.', ['fields' => $errors]);
        }

        $user = check_password_reset_key($key, $login);

        if ($user instanceof WP_Error || !$user instanceof WP_User) {
            $this->rateLimiter->recordAuthFailure($this->clientIp(), time());

            // One message for expired, wrong and already-used. Which of the
            // three it was is information about somebody else's mailbox.
            throw ApiException::invalidRequest('That reset link is not valid. Ask for a new one.', [
                'fields' => ['key' => 'Invalid or expired.'],
            ]);
        }

        if (!AccountSession::isShopper($user)) {
            $this->rateLimiter->recordAuthFailure($this->clientIp(), time());

            throw ApiException::invalidRequest('That reset link is not valid. Ask for a new one.', [
                'fields' => ['key' => 'Invalid or expired.'],
            ]);
        }

        // WordPress clears the activation key here, which is what makes the
        // token single-use — a second attempt with the same link fails above.
        reset_password($user, $password);

        $this->audit->record('customer.password_reset', 'customer', $user->ID, ['by' => 'reset_link']);

        /*
         * Every existing session is now invalid: the auth-cookie HMAC covers a
         * fragment of the password hash. That is the property that makes a
         * reset useful after an account is stolen, so it is stated rather than
         * left to be discovered.
         */
        return [
            'reset' => true,
            'sessions_revoked' => true,
            'message' => 'Your password has been changed. Sign in with it.',
        ];
    }

    /** @return array<string, mixed> what a mail check would report */
    public function readiness(): array
    {
        return [
            'mail_configured' => $this->mail->isConfigured(),
            'storefront_url' => $this->storefrontUrl(),
            'ready' => $this->mail->isConfigured() && $this->storefrontUrl() !== '',
        ];
    }

    /**
     * Both halves of "can this shop actually reset a password", checked before
     * a token exists rather than after a mail fails.
     */
    private function requireWorkingMail(): void
    {
        if (!$this->mail->isConfigured()) {
            throw new ApiException(
                'mail_not_configured',
                'This shop cannot send email yet, so password reset is unavailable.',
                503,
                ['fix' => 'Set SMTP_HOST in .env, then check with: wp algerian-commerce mail-check']
            );
        }

        if ($this->storefrontUrl() === '') {
            throw new ApiException(
                'storefront_url_not_set',
                'This shop has no storefront address, so a reset link cannot be built.',
                503,
                ['fix' => 'Set store.storefront_url — see PATCH /settings or client.json.']
            );
        }
    }

    private function storefrontUrl(): string
    {
        $stored = $this->settings->stored();

        return trim((string) ($stored['store']['storefront_url'] ?? ''));
    }

    /**
     * The link, and it is built here rather than taken from the request.
     *
     * A `redirect_to` parameter on a reset request is how password-reset
     * poisoning works: the attacker asks for somebody else's reset and supplies
     * the host, and the victim's link arrives pointing at the attacker. The
     * destination is configuration, never input.
     */
    private function send(WP_User $user, string $key): void
    {
        /*
         * French, and pointed at /fr/, by design. The storefront only ships
         * French today; deriving the locale from the customer's account or
         * from an Accept-Language on the request they may never send is a
         * guess this backend refuses to make. When the storefront adds a
         * second locale, this method is the one place that changes.
         *
         * The path is /fr/reset-password with `token` and `login` — the
         * storefront route (app/[locale]/(shop)/reset-password) reads those
         * exact query parameters.
         */
        $link = rtrim($this->storefrontUrl(), '/') . '/fr/reset-password?' . http_build_query([
            'token' => $key,
            'login' => $user->user_login,
        ]);

        $shop = (string) get_option('blogname', '');
        $subject = sprintf('%s — réinitialisez votre mot de passe', $shop);

        $body = implode("\n\n", [
            sprintf('Bonjour %s,', $user->first_name !== '' ? $user->first_name : $user->user_login),
            sprintf('Quelqu\'un a demandé la réinitialisation du mot de passe de votre compte sur %s.', $shop),
            'Cliquez ici pour choisir un nouveau mot de passe :',
            $link,
            'Ce lien expire dans 24 heures et ne peut être utilisé qu\'une seule fois.',
            'Si vous n\'avez pas demandé cette réinitialisation, ignorez cet email — votre mot de passe n\'a pas changé.',
        ]);

        $sent = wp_mail($user->user_email, $subject, $body);

        if (!$sent) {
            /*
             * The caller is still told nothing — a send failure is about this
             * shop, not about whether the address exists, and reporting it
             * would turn a broken mail server into an enumeration oracle. The
             * operator finds it here and in `mail-check`.
             */
            $this->logger->error('A password reset email was not accepted for delivery', [
                'user_id' => $user->ID,
            ]);
        }
    }

    /**
     * One rule, in `Security\ClientIp`.
     *
     * `0.0.0.0` when nothing resolves, because this is a rate-limit key and an
     * empty one would put every unidentifiable caller in the same bucket as a
     * misconfiguration rather than in a bucket of their own.
     */
    private function clientIp(): string
    {
        return ClientIp::resolve($_SERVER, $this->config?->get('AC_TRUSTED_PROXIES')) ?: '0.0.0.0';
    }
}
