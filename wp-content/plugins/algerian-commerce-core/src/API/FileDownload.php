<?php

declare(strict_types=1);

namespace AlgerianCommerce\API;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Serving a file body from a JSON API — roadmap §64's exports.
 *
 * **This is a deliberate exception to the response envelope, and the only
 * one.** CLAUDE.md's API conventions say every response is
 * `{success, data}`, and that rule exists so a client parses one shape for
 * data and one for errors. A CSV export is neither: it is a document the
 * caller saves to disk. Wrapping it in JSON would mean base64 in a string
 * field, which doubles the size, forces the whole file through a JSON encoder,
 * and leaves every client writing the same decode.
 *
 * The exception is narrow, and these are its edges:
 *
 *  - **Only a 2xx body is raw.** Every error — 400, 401, 403, 404, 500 —
 *    still comes back in the envelope, because an error is data about the
 *    request and a client must not have to sniff the content type to find out
 *    whether its export failed.
 *  - **Only routes that opt in**, by returning a response this class built. A
 *    controller cannot bypass the envelope by accident.
 *  - The `Content-Disposition` filename is **ours**, never the caller's, for
 *    the reason §61 gives about stored upload names: a filename that came from
 *    input is a header injection and a path traversal looking for somewhere to
 *    happen.
 *
 * The mechanics are WordPress's own: `rest_pre_serve_request` is the filter for
 * "I have written the body myself", and returning true from it stops the server
 * encoding anything. `API\Cors` already uses the same hook, which is why this
 * registers later — headers first, body second.
 *
 * > **Corrected in the build: the response cannot be marked with
 * > `set_matched_route()`.** It was, and the marker never survived to the
 * > filter: `WP_REST_Server::respond_to_request()` calls
 * > `$response->set_matched_route($route)` *after* the callback returns, so by
 * > the time `rest_pre_serve_request` fires the marker has been overwritten
 * > with the real route and `serve()` declined every single download. WordPress
 * > then JSON-encoded the CSV as a bare string, and **every export answered
 * > `"﻿sku,stock_quantity\r\nAC-TAP-001,12\r\n"`** — one line, quoted,
 * > with the BOM as the six characters `﻿`, every accent as `è` and
 * > every newline as the two characters `\r\n`. Content type `text/csv`,
 * > `Content-Disposition: attachment`, and a file no spreadsheet can open.
 * >
 * > Measured 2026-08-21 on WordPress 7.0.4 while building the admin panel's
 * > export screen. It is marked by **type** now — `rest_ensure_response()`
 * > returns a `WP_REST_Response` subclass unchanged, and nothing in core or in
 * > this plugin rewrites an object's class the way it rewrites its matched
 * > route. A controller still cannot opt in by accident, because the only
 * > constructor is this class's own factory.
 * >
 * > Two assertions in `scripts/test-api.sh` were watching and could not see it,
 * > which is the more useful half of the finding. "The body is a CSV, not
 * > JSON" tested `grep -q '"success"'` — a JSON-encoded *string* has no
 * > `success` key, so it passed. "The CSV names its columns" tested
 * > `head -1 | grep -q 'sku'` — the whole file was one line and `sku` was on
 * > it, so it passed too. Both now assert the shape they meant: the first byte
 * > is the real BOM `EF BB BF` and not a quote, and the second line is a
 * > record.
 */
final class FileDownload
{
    public const PRIORITY = 20;

    public function register(): void
    {
        add_filter('rest_pre_serve_request', [$this, 'serve'], self::PRIORITY, 4);
    }

    /**
     * Build a downloadable response.
     *
     * @param string $filename the name the browser saves it as — never from input
     */
    public static function csv(string $body, string $filename): WP_REST_Response
    {
        $response = new FileDownloadResponse($body, 200);

        $response->set_headers([
            // The charset matters: CsvWriter emits a UTF-8 BOM for Excel, and a
            // response that does not say UTF-8 invites a proxy to guess.
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . self::safeName($filename) . '"',
            'Content-Length' => (string) strlen($body),
            // An export is one shop's private data. Even over TLS, a shared
            // cache holding it is a disclosure waiting for the next request.
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        return $response;
    }

    /**
     * @param mixed $served whether something has already written the body
     * @param mixed $result the response being served
     */
    public function serve(
        mixed $served,
        mixed $result = null,
        ?WP_REST_Request $request = null,
        mixed $server = null
    ): mixed {
        if ($served === true || !$result instanceof FileDownloadResponse) {
            return $served;
        }

        /*
         * An error must never be served raw. A handler that failed replaced the
         * body with our envelope and the status with a 4xx or 5xx; letting that
         * through as `text/csv` would hand a client a "file" containing an error
         * message, which it would cheerfully save as products.csv.
         */
        if ($result->get_status() < 200 || $result->get_status() >= 300) {
            return $served;
        }

        $body = $result->get_data();

        if (!is_string($body)) {
            return $served;
        }

        foreach ($result->get_headers() as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $body;

        // True means "the body is written" — the server encodes nothing more.
        return true;
    }

    /**
     * A filename safe to put inside a header.
     *
     * A CR or LF here would end the header and begin another one, which is
     * response splitting; a quote would end the filename parameter early. Only
     * a conservative set survives, and the fallback is never empty.
     */
    private static function safeName(string $filename): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?? '';
        $clean = trim($clean, '-.');

        return $clean === '' ? 'export.csv' : $clean;
    }
}
