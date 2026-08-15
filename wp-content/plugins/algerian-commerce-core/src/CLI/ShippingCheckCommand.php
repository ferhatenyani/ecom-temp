<?php

declare(strict_types=1);

namespace AlgerianCommerce\CLI;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Geography\GeoRepository;
use AlgerianCommerce\Integrations\Yalidine\YalidineProvider;
use AlgerianCommerce\Integrations\Yalidine\YalidineSettings;
use AlgerianCommerce\Shipping\ProviderRegistry;
use WP_CLI;

/**
 * wp algerian-commerce shipping-check
 *
 * Is this store actually able to ship anything?
 *
 * Four things have to line up before a Yalidine parcel can be created, and each
 * fails at a different moment with a different message (roadmap §56):
 * credentials in `.env`, the geography imported, the destination sync run, and
 * an origin wilaya set. Finding that out one 409 at a time — from a shop
 * assistant trying to dispatch a real order — is the experience this command
 * exists to prevent, and it is the "prove your keys work" screen §56 describes,
 * before there is an admin UI to put it in.
 *
 * Read-only, and the only command here that talks to a provider without writing
 * anything: `GET wilayas/` with a page size of one.
 */
final class ShippingCheckCommand
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly GeoRepository $geography,
        private readonly YalidineSettings $yalidine
    ) {
    }

    /**
     * Report each configured courier's readiness.
     *
     * ## OPTIONS
     *
     * [--skip-remote]
     * : Do not call any provider. Checks only what is on this machine.
     *
     * ## EXAMPLES
     *
     *     wp algerian-commerce shipping-check
     *     wp algerian-commerce shipping-check --skip-remote
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs = []): void
    {
        $skipRemote = !empty($assocArgs['skip-remote']);
        $rows = [];
        $blocked = 0;

        $wilayas = $this->geography->countWilayas();
        $communes = $this->geography->countCommunes();

        WP_CLI::log(sprintf('Geography: %d wilayas, %d communes.', $wilayas, $communes));

        if ($communes === 0) {
            $blocked++;
            WP_CLI::warning('No geography imported — run: wp algerian-commerce import-algeria');
        }

        foreach ($this->providers->names() as $name) {
            $provider = $this->providers->get($name);
            $mapped = count($this->geography->destinations($name));
            $notes = [];

            if ($provider instanceof YalidineProvider) {
                // Whatever was wrong with the stored option, in the operator's
                // own words — an unusable setting silently fell back to a
                // default, and this is where that becomes visible.
                $notes = [...$notes, ...$this->yalidine->problems()];

                if ($mapped === 0) {
                    $notes[] = 'no destinations recorded — run: wp algerian-commerce sync-destinations --provider=' . $name;
                }

                if (!$this->yalidine->hasOrigin()) {
                    $notes[] = 'no origin wilaya set (ac_yalidine_settings.origin_wilaya_id)';
                } elseif ($mapped > 0 && !$this->providerHasOrigin($name)) {
                    $notes[] = 'the origin wilaya is not among the courier\'s destinations';
                }
            }

            $credentials = 'n/a';

            if (!$skipRemote && method_exists($provider, 'verifyCredentials')) {
                try {
                    $credentials = $provider->verifyCredentials() ? 'ok' : 'rejected';
                } catch (ApiException $exception) {
                    $credentials = 'unreachable';
                    $notes[] = $exception->getMessage();
                }
            } elseif (method_exists($provider, 'verifyCredentials')) {
                $credentials = 'not checked';
            }

            $notes = array_values(array_filter(array_map('trim', $notes)));

            if ($credentials === 'rejected' || $credentials === 'unreachable' || $notes !== []) {
                $blocked++;
            }

            $rows[] = [
                'provider' => $name,
                'credentials' => $credentials,
                'destinations' => $mapped,
                'notes' => $notes === [] ? 'ready' : implode('; ', $notes),
            ];
        }

        WP_CLI\Utils\format_items('table', $rows, ['provider', 'credentials', 'destinations', 'notes']);

        if ($blocked > 0) {
            // An error, unlike the sync's gap report: a store that cannot ship
            // is a deploy that should stop.
            WP_CLI::error(sprintf('%d problem(s) would stop a parcel being created.', $blocked));

            return;
        }

        WP_CLI::success('Every configured courier is ready.');
    }

    /** Whether the sync recorded the wilaya this store ships from. */
    private function providerHasOrigin(string $provider): bool
    {
        foreach ($this->geography->destinations($provider) as $row) {
            if ((int) $row['wilaya_id'] === $this->yalidine->originWilayaId && (int) $row['commune_id'] === 0) {
                return true;
            }
        }

        return false;
    }
}
