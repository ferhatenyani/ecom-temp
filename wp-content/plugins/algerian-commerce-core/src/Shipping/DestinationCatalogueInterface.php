<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use AlgerianCommerce\API\ApiException;

/**
 * A courier that will tell us where it delivers.
 *
 * Separate from ShippingProviderInterface, and optional, because publishing a
 * destination list is not part of being a courier: `ManualProvider` is a person
 * with a van and has nothing to publish, and requiring it to answer would mean
 * inventing a list for a provider whose coverage is "wherever the driver is
 * willing to go".
 *
 * The sync exists to stop the mistake roadmap §56 documents: the reference
 * implementation hard-codes a 58-case wilaya-name→id `switch` and a set of
 * unsupported wilayas in application code. Both go stale, both are invisible
 * when they do, and both are per-account facts pretending to be constants. Here
 * coverage is a table the courier fills in.
 */
interface DestinationCatalogueInterface
{
    /**
     * Every wilaya, commune and stop desk this account can send to.
     *
     * One call, all three kinds, because they are one snapshot: a commune list
     * that is newer than the wilaya list it references cannot be matched
     * against anything.
     *
     * @return list<ProviderPlace>
     *
     * @throws ApiException when the provider is unreachable or refuses the
     *                      credentials — the sync must fail loudly rather than
     *                      write a partial map that looks like reduced coverage
     */
    public function destinations(): array;
}
