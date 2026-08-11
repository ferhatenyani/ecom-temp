<?php

declare(strict_types=1);

namespace AlgerianCommerce\Geography;

use AlgerianCommerce\API\ApiException;

/**
 * Read access to the Algerian geography — roadmap §51, docs/PLAN.md §10.
 *
 * **No Permissions::assert() here, and that is deliberate.** Every other
 * service in this plugin guards itself because it reads or writes shop data.
 * This one serves the list of Algerian wilayas and communes: public
 * administrative divisions, printed on number plates and identity documents,
 * and the same list every delivery site in the country shows on its address
 * form. There is nothing to protect.
 *
 * Requiring a capability would mean the storefront proxies every keystroke of a
 * commune autocomplete through its own server to fetch data that is on
 * Wikipedia — cost and latency bought with no security.
 *
 * Writes are not exposed at all. The dataset changes by importing a file
 * through WP-CLI (`wp algerian-commerce import-algeria`), which is a
 * deployment step, not a request.
 */
final class GeoService
{
    public function __construct(private readonly GeoRepository $repository)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function wilayas(array $filters): array
    {
        return $this->repository->wilayas($filters);
    }

    /** @return array<string, mixed> */
    public function wilaya(int $id): array
    {
        $wilaya = $this->repository->findWilaya($id);

        if ($wilaya === null) {
            throw ApiException::notFound('No wilaya with that code.');
        }

        return $wilaya;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function communesOf(int $wilayaId, array $filters): array
    {
        // Resolved first, so an unknown wilaya is a 404 rather than an empty
        // list — "this wilaya has no communes loaded" and "this wilaya does
        // not exist" are different answers and a client has to tell them apart.
        $this->wilaya($wilayaId);

        return $this->repository->communes(['wilaya_id' => $wilayaId] + $filters);
    }

    /** @return array<string, mixed> */
    public function commune(int $id): array
    {
        $commune = $this->repository->findCommune($id);

        if ($commune === null) {
            throw ApiException::notFound('No commune with that id.');
        }

        return $commune;
    }

    /**
     * How much of the dataset is loaded.
     *
     * Surfaced because an install that never ran the import looks identical to
     * one whose communes genuinely have not been sourced yet, and the
     * difference decides whether an address form can be trusted.
     *
     * @return array{wilayas: int, communes: int, provider_destinations: int}
     */
    public function coverage(): array
    {
        return [
            'wilayas' => $this->repository->countWilayas(),
            'communes' => $this->repository->countCommunes(),
            'provider_destinations' => $this->repository->countDestinations(),
        ];
    }
}
