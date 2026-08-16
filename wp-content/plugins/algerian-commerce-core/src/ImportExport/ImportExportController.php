<?php

declare(strict_types=1);

namespace AlgerianCommerce\ImportExport;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\API\FileDownload;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Import and export endpoints — roadmap §64, docs/PLAN.md §33.
 *
 * **A CSV arrives as the request body, not as a multipart upload**, and that is
 * a deliberate difference from `POST /media`. Three reasons, in order of
 * weight:
 *
 *  - **Nothing is ever written where a web server can serve it.** A multipart
 *    upload ends in `move_uploaded_file()` into `wp-content/uploads`;
 *    §61 spends four independent checks and a re-encode making that safe. A CSV
 *    body is a string that goes to `CsvReader`, and for products a temp file
 *    outside the document root that is unlinked in a `finally`. The dangerous
 *    step is simply absent.
 *  - **The privileged caller is a server, not a browser.** Admin traffic is
 *    Next.js server-side (docs/ARCHITECTURE.md §8), and it re-posts whatever
 *    the browser handed it — so multipart buys nothing on the wire and costs a
 *    parse.
 *  - **It is testable.** `rest_do_request()` cannot perform a real multipart
 *    upload, because `move_uploaded_file()` refuses anything that did not
 *    arrive over a genuine POST — which is why the media suite can only prove
 *    refusals in-process and needs `scripts/test-api.sh` for the rest. A body
 *    is a body in both.
 *
 * Exports answer with a file rather than the envelope, which `API\FileDownload`
 * explains and bounds. Errors from an export are still the envelope.
 */
final class ImportExportController extends AbstractController
{
    /** What a caller may send as an import body. */
    private const ACCEPTED_TYPES = ['text/csv', 'application/csv', 'text/plain', 'application/octet-stream'];

    public function __construct(
        Logger $logger,
        private readonly ImportService $import,
        private readonly ExportService $export
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        register_rest_route($this->restNamespace(), '/import/products', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'importProducts']),
            'permission_callback' => Permissions::callback(Capabilities::MANAGE_PRODUCTS),
            'args' => $this->importArgs() + [
                /*
                 * Ours, not WooCommerce's `update_existing`, which is a mode
                 * switch whose name reads as a modifier — see
                 * `ImportService::productsImport()` for the measured table.
                 * Neither setting both creates and updates, so the API says
                 * which of the two jobs it is doing.
                 */
                'mode' => [
                    'type' => 'string',
                    'default' => ImportService::MODE_CREATE,
                    'enum' => ImportService::MODES,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description' => 'create: add new products, skip SKUs that exist. '
                        . 'update: change products that exist, skip SKUs that do not.',
                ],
            ],
        ]);

        register_rest_route($this->restNamespace(), '/import/inventory', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'importInventory']),
            'permission_callback' => Permissions::callback(Capabilities::MANAGE_INVENTORY),
            'args' => $this->importArgs(),
        ]);

        foreach ([
            'products' => Capabilities::MANAGE_PRODUCTS,
            'inventory' => Capabilities::MANAGE_INVENTORY,
            'orders' => Capabilities::MANAGE_ORDERS,
            'customers' => Capabilities::MANAGE_CUSTOMERS,
        ] as $subject => $capability) {
            register_rest_route($this->restNamespace(), "/export/{$subject}", [
                'methods' => 'GET',
                'callback' => $this->handle(fn (WP_REST_Request $request): WP_REST_Response
                    => $this->exportFile($subject, $request)),
                'permission_callback' => Permissions::callback($capability),
                'args' => $this->exportArgs($subject),
            ]);
        }
    }

    /**
     * **`dry_run` defaults to true, and that default is the safety property.**
     * A client that forgets the flag gets a preview, never a write. The other
     * way round, one malformed integration overwrites a catalogue on its first
     * request — and §64's whole pipeline exists to stop exactly that.
     *
     * @return array<string, array<string, mixed>>
     */
    private function importArgs(): array
    {
        return [
            'dry_run' => [
                'type' => 'boolean',
                'default' => true,
                'validate_callback' => 'rest_validate_request_arg',
                'description' => 'Validate and report without writing anything. Defaults to true.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function exportArgs(string $subject): array
    {
        $args = [
            'limit' => [
                'type' => 'integer',
                'default' => ExportService::MAX_ROWS,
                'minimum' => 1,
                'maximum' => ExportService::MAX_ROWS,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'absint',
            ],
        ];

        if ($subject !== 'orders') {
            return $args;
        }

        return $args + [
            'status' => [
                'type' => 'string',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'date_from' => $this->dateArg(),
            'date_to' => $this->dateArg(),
        ];
    }

    /** @return array<string, mixed> */
    private function dateArg(): array
    {
        return [
            'type' => 'string',
            'pattern' => '^\d{4}-\d{2}-\d{2}$',
            'validate_callback' => 'rest_validate_request_arg',
            'sanitize_callback' => 'sanitize_text_field',
            'description' => 'Y-m-d. Both ends cover the whole day.',
        ];
    }

    public function importProducts(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->import->productsImport($this->body($request), [
            'dry_run' => (bool) $request->get_param('dry_run'),
            'mode' => (string) $request->get_param('mode'),
        ]));
    }

    public function importInventory(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->import->inventory($this->body($request), [
            'dry_run' => (bool) $request->get_param('dry_run'),
        ]));
    }

    private function exportFile(string $subject, WP_REST_Request $request): WP_REST_Response
    {
        $params = [
            'limit' => (int) $request->get_param('limit'),
            'status' => (string) $request->get_param('status'),
            'date_from' => (string) $request->get_param('date_from'),
            'date_to' => (string) $request->get_param('date_to'),
        ];

        $result = match ($subject) {
            'products' => $this->export->products($params),
            'inventory' => $this->export->inventory($params),
            'orders' => $this->export->orders($params),
            'customers' => $this->export->customersExport($params),
        };

        $this->logger->info('Export served', [
            'subject' => $subject,
            'rows' => $result['rows'],
        ]);

        return FileDownload::csv($result['body'], $result['filename']);
    }

    /**
     * The CSV itself.
     *
     * The content type is checked but held loosely: `text/csv` is correct,
     * `text/plain` is what half the HTTP clients in the world send for a `.csv`,
     * and `application/octet-stream` is what the other half send. A JSON body is
     * refused outright and by name, because posting `{"file": "..."}` is the
     * mistake an integrator actually makes and "the file is empty" would send
     * them looking in the wrong place.
     */
    private function body(WP_REST_Request $request): string
    {
        $type = strtolower(trim(explode(';', (string) $request->get_header('content-type'))[0]));

        if ($type === 'application/json') {
            throw ApiException::invalidRequest('Send the CSV as the request body.', [
                'fields' => [
                    'body' => 'Content-Type must be text/csv, and the body the file itself — not JSON.',
                ],
            ]);
        }

        if ($type !== '' && !in_array($type, self::ACCEPTED_TYPES, true)) {
            throw ApiException::invalidRequest('That content type cannot be imported.', [
                'fields' => ['body' => 'Expected one of: ' . implode(', ', self::ACCEPTED_TYPES) . '.'],
            ]);
        }

        return (string) $request->get_body();
    }
}
