<?php

declare(strict_types=1);

namespace AlgerianCommerce\API;

use WP_REST_Response;

/**
 * A response whose body is a file and must not be encoded.
 *
 * The marker `API\FileDownload` uses to recognise its own responses inside
 * `rest_pre_serve_request`. It is a **type** rather than a field on the object
 * because every field WordPress offers gets rewritten on the way out:
 * `WP_REST_Server::respond_to_request()` calls `set_matched_route()` after the
 * callback returns, which is what silently disabled the whole mechanism — see
 * `FileDownload`'s docblock for the measurement. A class, by contrast, survives
 * `rest_ensure_response()` untouched, and neither core nor this plugin rewrites
 * one.
 *
 * There is nothing to override. The type *is* the statement, and keeping it
 * empty is deliberate: a subclass that also changed behaviour would be a second
 * thing to reason about at the one point in the API where the envelope does not
 * apply.
 *
 * Construct it through `FileDownload::csv()` and nowhere else — that factory is
 * what sets the headers a download needs, and a controller that built one of
 * these by hand would return a body with no content type, no filename and no
 * `no-store`.
 */
final class FileDownloadResponse extends WP_REST_Response
{
}
