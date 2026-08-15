<?php

declare(strict_types=1);

namespace AlgerianCommerce\Http;

use RuntimeException;

/**
 * The request never got an answer.
 *
 * Separate from an HTTP error status on purpose: "the courier said no" and "the
 * courier did not say anything" call for different handling — one is a decision
 * to record, the other is a retry or an outage — and an adapter that cannot
 * tell them apart reports a timeout as a rejected parcel.
 *
 * The message is for the log. Adapters translate it into an ApiException before
 * it can reach a response body, because a transport message can carry the URL
 * and, on some hosts, the proxy credentials in it (docs/SECURITY.md).
 */
final class HttpTransportException extends RuntimeException
{
}
