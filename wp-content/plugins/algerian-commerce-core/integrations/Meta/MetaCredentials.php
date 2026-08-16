<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\Meta;

/**
 * Meta's two values, and they are not the same kind of thing — roadmap §62b.
 *
 * **`META_PIXEL_ID` is public.** It ships inside the storefront's JavaScript on
 * every page load; anyone can read it with view-source. `GET /marketing/config`
 * therefore serves it, and that is not a leak.
 *
 * **`META_CAPI_ACCESS_TOKEN` is a credential.** It authorises writing
 * conversions into somebody's ad account, it is long-lived, and Meta does not
 * rotate it for you. `.env` only, reaching this object through the plugin
 * bootstrap exactly as a courier's and a gateway's credentials do, and it
 * appears in no response ever. `Logger`'s substring list already masks any
 * context key containing `token`, which covers `access_token` — `LoggerTest`
 * pins that, so the protection cannot be removed by editing one list.
 *
 * Both are needed to register the provider. A pixel id without a token would
 * put a working browser pixel on the storefront with no server-side half at
 * all, which looks configured and silently halves the match rate.
 */
final class MetaCredentials
{
    public function __construct(
        public readonly string $pixelId,
        public readonly string $accessToken
    ) {
    }

    public function isComplete(): bool
    {
        return $this->hasPixelId() && trim($this->accessToken) !== '';
    }

    /**
     * A pixel id is a numeric string of about 15 digits. Checked, because the
     * commonest configuration mistake is pasting the *dataset name* or a whole
     * URL into the variable, and the resulting 400 from Meta says nothing that
     * points back at `.env`.
     */
    public function hasPixelId(): bool
    {
        return preg_match('/^\d{6,25}$/', trim($this->pixelId)) === 1;
    }
}
