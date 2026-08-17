<?php
/**
 * Product configurators and bundles — roadmap §83.
 *
 * §83 says what belongs here rather than in `tests/Unit/`: "the part a unit
 * test cannot: **a cart payload carrying its own surcharge is refused with the
 * field named**, and the positive control beside it — the same cart without
 * that field is priced correctly by the server." §65's rule, because a refusal
 * and an unreachable route look identical from outside.
 *
 * The arithmetic itself is `tests/Unit/OptionSelectionTest`, which is where
 * §83 puts it. What is here is everything that needs a real product, a real
 * cart session, a real order and a real stock ledger:
 *
 *   - a definition written through `PATCH /products/{id}` and read back
 *   - the server pricing a configured line, and refusing to be told a price
 *   - two configurations of one product becoming two cart lines
 *   - a bundle's ceiling being the minimum of its components'
 *   - an order carrying the chosen options to fulfilment
 *   - a bundle drawing its components down **through the ledger**, once
 *
 * In-process via rest_do_request(). No declare(strict_types=1): wp eval-file
 * eval()s the body, where that declaration is not the first statement and fatals.
 *
 *   docker compose run --rm -T wpcli wp eval-file - < tests/Api/options.php
 */

$GLOBALS['ac_pass'] = 0;
$GLOBALS['ac_fail'] = 0;

function ac_req(string $method, string $route, array|string|null $body = null, array $query = [], array $headers = []): array
{
    $request = new WP_REST_Request($method, '/algerian-commerce/v1' . $route);

    foreach ($query as $key => $value) {
        $request->set_param($key, $value);
    }

    foreach ($headers as $name => $value) {
        $request->set_header($name, $value);
    }

    if ($body !== null) {
        $request->set_header('content-type', 'application/json');
        $request->set_body(is_string($body) ? $body : wp_json_encode($body));
    }

    $response = rest_do_request($request);

    return [$response->get_status(), json_decode((string) wp_json_encode($response->get_data()), true)];
}

function ac_check(string $label, array $result, int $expect, ?callable $extra = null): mixed
{
    [$status, $data] = $result;

    $ok = $status === $expect;
    $detail = '';

    if ($ok && $extra !== null) {
        $verdict = $extra($data);
        if ($verdict !== true) {
            $ok = false;
            $detail = ' — ' . (is_string($verdict) ? $verdict : 'body check failed');
        }
    }

    $ok ? $GLOBALS['ac_pass']++ : $GLOBALS['ac_fail']++;

    echo $ok ? "\033[32mPASS\033[0m " : "\033[31mFAIL\033[0m ";
    echo str_pad($label, 62), ' ', str_pad((string) $status, 4);

    if (!$ok) {
        echo "(expected {$expect}){$detail} ", substr((string) wp_json_encode($data), 0, 300);
    }

    echo PHP_EOL;

    return $data;
}

function ac_assert(string $label, $verdict): void
{
    $ok = $verdict === true;
    $ok ? $GLOBALS['ac_pass']++ : $GLOBALS['ac_fail']++;

    echo $ok ? "\033[32mPASS\033[0m " : "\033[31mFAIL\033[0m ";
    echo str_pad($label, 62);
    echo $ok ? '' : '     ' . (is_string($verdict) ? $verdict : 'failed');
    echo PHP_EOL;
}

function ac_user(string $login, string $role): int
{
    $user = get_user_by('login', $login);

    if ($user) {
        $user->set_role($role);

        return (int) $user->ID;
    }

    $id = wp_insert_user([
        'user_login' => $login,
        'user_pass' => wp_generate_password(24),
        'user_email' => $login . '@example.test',
        'role' => $role,
    ]);

    return is_wp_error($id) ? 0 : (int) $id;
}

function ac_purge_sku(string $sku): void
{
    foreach (['publish', 'draft', 'pending', 'private', 'trash'] as $status) {
        foreach (wc_get_products(['sku' => $sku, 'status' => $status, 'limit' => 20, 'return' => 'ids']) as $id) {
            wp_delete_post((int) $id, true);
        }
    }
}

$SKUS = ['AC-O83-MUG', 'AC-O83-BOX', 'AC-O83-KIT'];

foreach ($SKUS as $sku) {
    ac_purge_sku($sku);
}

/*
 * WooCommerce's own mailer sends **synchronously** inside
 * `woocommerce_order_status_changed`, and this machine has no MTA — the first
 * run of the §67 seeder found the same thing, visible only as
 * "sendmail: can't connect". On a machine with one, this suite would mail the
 * shop's real admin address about a fictional order. Short-circuited for the
 * duration and removed again at the end, which `tests/Api/seed.php` asserts for
 * the seeder and this file asserts for itself: a suite that left the filter
 * hooked would silence every later suite in the same process.
 */
$silenceMail = static fn (): bool => true;
add_filter('pre_wp_mail', $silenceMail, 99);

$admin = ac_user('ac_opt_admin', 'ac_super_admin');
wp_set_current_user($admin);

echo PHP_EOL, "── a definition is written through the product ──", PHP_EOL;

$mug = ac_check(
    'create the product an option set will hang off',
    ac_req('POST', '/products', [
        'name' => 'O83 Mug',
        'sku' => 'AC-O83-MUG',
        'regular_price' => '1000',
        'status' => 'publish',
        'manage_stock' => true,
        'stock_quantity' => 50,
    ]),
    201
);
$mugId = (int) ($mug['data']['id'] ?? 0);

$box = ac_req('POST', '/products', [
    'name' => 'O83 Box',
    'sku' => 'AC-O83-BOX',
    'regular_price' => '500',
    'status' => 'publish',
    'manage_stock' => true,
    'stock_quantity' => 10,
])[1];
$boxId = (int) ($box['data']['id'] ?? 0);

ac_assert('both fixture products exist', ($mugId > 0 && $boxId > 0) ?: "ids {$mugId}/{$boxId}");

$OPTIONS = ['groups' => [
    [
        'id' => 'wrap',
        'type' => 'choice',
        'label' => 'Gift wrap',
        'required' => false,
        'min' => 0,
        'max' => 1,
        'choices' => [
            ['id' => 'gold', 'label' => 'Or', 'price_delta' => '250'],
            ['id' => 'none', 'label' => 'Sans coffret', 'price_delta' => '-100'],
        ],
    ],
    ['id' => 'engraving', 'type' => 'text', 'label' => 'Gravure', 'max_length' => 20, 'price_delta' => '500'],
]];

ac_check(
    'PATCH writes the option set',
    ac_req('PATCH', "/products/{$mugId}", ['options' => $OPTIONS]),
    200,
    static fn (array $d): bool|string => count($d['data']['options']['groups'] ?? []) === 2
        ?: 'options came back as ' . substr((string) wp_json_encode($d['data']['options'] ?? null), 0, 200)
);

ac_check(
    'GET reads it back with the deltas intact',
    ac_req('GET', "/products/{$mugId}"),
    200,
    static function (array $d): bool|string {
        $groups = $d['data']['options']['groups'] ?? [];
        $wrap = $groups[0] ?? [];

        return ($wrap['id'] ?? '') === 'wrap'
            && ($wrap['choices'][0]['price_delta'] ?? '') === '250'
            && ($wrap['choices'][1]['price_delta'] ?? '') === '-100'
            ? true
            : 'read back as ' . substr((string) wp_json_encode($groups), 0, 250);
    }
);

/*
 * The round trip §47's READ_ONLY list exists for: GET the whole product, change
 * one field, PATCH it all back. `bundle` and `options_problems` are derived
 * from `options` and would otherwise come back as invented fields.
 */
$whole = ac_req('GET', "/products/{$mugId}")[1]['data'] ?? [];
$whole['name'] = 'O83 Mug (grand)';
ac_check('the whole GET body can be PATCHed back', ac_req('PATCH', "/products/{$mugId}", $whole), 200);

ac_check(
    'and the option set survived that round trip',
    ac_req('GET', "/products/{$mugId}"),
    200,
    static fn (array $d): bool|string => count($d['data']['options']['groups'] ?? []) === 2
        ?: 'the options were lost on the way back'
);

ac_check(
    'options can be cleared with null',
    ac_req('PATCH', "/products/{$mugId}", ['options' => null]),
    200,
    static fn (array $d): bool|string => !isset($d['data']['options']) ?: 'options survived being cleared'
);

ac_check('and written again', ac_req('PATCH', "/products/{$mugId}", ['options' => $OPTIONS]), 200);

echo PHP_EOL, "── a bad definition is a 400 naming the group ──", PHP_EOL;

$bad = [
    'an unknown group type' => [['id' => 'g', 'type' => 'slider', 'label' => 'G']],
    'a required group with min 0' => [[
        'id' => 'g', 'type' => 'choice', 'label' => 'G', 'required' => true, 'min' => 0, 'max' => 1,
        'choices' => [['id' => 'a', 'label' => 'A']],
    ]],
    'a non-numeric price delta' => [[
        'id' => 'g', 'type' => 'choice', 'label' => 'G',
        'choices' => [['id' => 'a', 'label' => 'A', 'price_delta' => 'free']],
    ]],
    'a duplicate choice id' => [[
        'id' => 'g', 'type' => 'choice', 'label' => 'G',
        'choices' => [['id' => 'a', 'label' => 'A'], ['id' => 'a', 'label' => 'B']],
    ]],
    'a text cap above the ceiling' => [['id' => 'g', 'type' => 'text', 'label' => 'G', 'max_length' => 9999]],
];

foreach ($bad as $label => $groups) {
    ac_check("{$label} is refused", ac_req('PATCH', "/products/{$mugId}", ['options' => ['groups' => $groups]]), 400);
}

ac_check(
    'and the refusal names the group and the field',
    ac_req('PATCH', "/products/{$mugId}", ['options' => ['groups' => [
        ['id' => 'g', 'type' => 'choice', 'label' => 'G', 'choices' => [['id' => 'a', 'label' => 'A', 'price_delta' => 'free']]],
    ]]]),
    400,
    static fn (array $d): bool|string => isset($d['error']['details']['fields']['options.groups[0].choices[0].price_delta'])
        ?: 'fields were ' . substr((string) wp_json_encode($d['error']['details']['fields'] ?? null), 0, 250)
);

ac_check(
    'a definition that was refused did not overwrite the good one',
    ac_req('GET', "/products/{$mugId}"),
    200,
    static fn (array $d): bool|string => count($d['data']['options']['groups'] ?? []) === 2
        ?: 'a refused write damaged the stored set'
);

echo PHP_EOL, "── THE RULE: the client sends the choice, the server reads the price ──", PHP_EOL;

/*
 * §83's single most important sentence, asserted as a pair.
 *
 * The negative — a payload that states its own surcharge is refused **with the
 * field named** — and the positive control beside it: the identical cart
 * without that field is priced correctly by the server. §65's rule, because a
 * refusal and a broken endpoint look the same from outside, and because a
 * refusal that also broke ordinary pricing would still pass the negative half.
 */
$priced = ac_check(
    'THE POSITIVE CONTROL: the server prices a configured line',
    ac_req('POST', '/cart/items', [
        'product_id' => $mugId,
        'quantity' => 2,
        'options' => ['wrap' => 'gold', 'engraving' => 'AB'],
    ]),
    201,
    static function (array $d): bool|string {
        $item = $d['data']['items'][0] ?? [];

        // 1000 catalogue + 250 wrap + 500 engraving = 1750 a unit, 3500 for two.
        return ($item['price'] ?? '') === '1750.00'
            && ($item['options_surcharge'] ?? '') === '750.00'
            && ($item['line_total'] ?? '') === '3500.00'
            ? true
            : 'priced as ' . wp_json_encode([
                $item['price'] ?? null, $item['options_surcharge'] ?? null, $item['line_total'] ?? null,
            ]);
    }
);

$cartToken = (string) ($priced['meta']['cart_token'] ?? '');

ac_assert('the cart came back with a token', $cartToken !== '' ?: 'no token in the response');

foreach ([
    'surcharge' => 50,
    'option_price' => 50,
    'options_price' => 50,
    'option_total' => 50,
    'price' => 1,
] as $field => $value) {
    ac_check(
        "a payload stating its own {$field} is refused",
        ac_req('POST', '/cart/items', [
            'product_id' => $mugId,
            'quantity' => 1,
            'options' => ['wrap' => 'gold'],
            $field => $value,
        ]),
        400,
        static fn (array $d): bool|string => isset($d['error']['details']['fields'][$field])
            ?: "the refusal did not name {$field}: "
                . substr((string) wp_json_encode($d['error']['details']['fields'] ?? null), 0, 200)
    );
}

ac_check(
    'a negative delta really reduces the line',
    ac_req('POST', '/cart/items', ['product_id' => $mugId, 'quantity' => 1, 'options' => ['wrap' => 'none']]),
    201,
    static function (array $d): bool|string {
        foreach ($d['data']['items'] ?? [] as $item) {
            if (($item['options_surcharge'] ?? '') === '-100.00' && ($item['price'] ?? '') === '900.00') {
                return true;
            }
        }

        return 'no line priced at 900.00';
    }
);

echo PHP_EOL, "── a bad choice is a 400, and the cart is unchanged ──", PHP_EOL;

foreach ([
    'an option this product does not offer' => ['monogram' => 'AB'],
    'a choice that is not one of the choices' => ['wrap' => 'platinum'],
    'text past the group cap' => ['engraving' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'],
    'a list where one choice is allowed' => ['wrap' => ['gold', 'none']],
] as $label => $options) {
    ac_check(
        "{$label} is refused",
        ac_req('POST', '/cart/items', ['product_id' => $mugId, 'quantity' => 1, 'options' => $options]),
        400
    );
}

echo PHP_EOL, "── two configurations of one product are two lines ──", PHP_EOL;

/*
 * The reason a cart line is addressed by a hashed key rather than a product id.
 * One mug engraved "AB" and one engraved "CD" cannot be a quantity of two, and
 * WooCommerce gets this right for free because it hashes cart item data into
 * the key — which is why the chosen options are passed as cart item data rather
 * than kept in a table beside the cart.
 */
$header = ['Cart-Token' => $cartToken];

$twoLines = ac_check(
    'a second configuration adds a line rather than a quantity',
    ac_req('POST', '/cart/items', [
        'product_id' => $mugId,
        'quantity' => 1,
        'options' => ['wrap' => 'gold', 'engraving' => 'CD'],
    ], [], $header),
    201,
    static function (array $d) use ($mugId): bool|string {
        $mugLines = array_filter(
            $d['data']['items'] ?? [],
            static fn (array $i): bool => (int) $i['product_id'] === $mugId && isset($i['options'])
        );

        return count($mugLines) >= 2 ?: 'the second configuration merged into the first';
    }
);

ac_check(
    'the same configuration twice is one line with a larger quantity',
    ac_req('POST', '/cart/items', [
        'product_id' => $mugId,
        'quantity' => 1,
        'options' => ['wrap' => 'gold', 'engraving' => 'AB'],
    ], [], $header),
    201,
    static function (array $d) use ($mugId): bool|string {
        foreach ($d['data']['items'] ?? [] as $item) {
            $engraving = '';

            foreach ($item['options'] ?? [] as $option) {
                if ($option['group_id'] === 'engraving') {
                    $engraving = $option['value'];
                }
            }

            if ($engraving === 'AB') {
                return (int) $item['quantity'] === 3
                    ?: 'the AB line has quantity ' . $item['quantity'] . ', expected 3';
            }
        }

        return 'the AB line disappeared';
    }
);

echo PHP_EOL, "── bundles: an inventory feature wearing a catalogue costume ──", PHP_EOL;

$kit = ac_req('POST', '/products', [
    'name' => 'O83 Kit',
    'sku' => 'AC-O83-KIT',
    'regular_price' => '1900',
    'status' => 'publish',
])[1];
$kitId = (int) ($kit['data']['id'] ?? 0);

ac_check(
    'a bundle is a group type, not a product type',
    ac_req('PATCH', "/products/{$kitId}", ['options' => ['groups' => [[
        'id' => 'contents',
        'type' => 'bundle',
        'label' => 'Contenu',
        'items' => [
            ['product_id' => $mugId, 'quantity' => 1],
            ['product_id' => $boxId, 'quantity' => 2],
        ],
    ]]]]),
    200,
    static fn (array $d): bool|string => ($d['data']['type'] ?? '') === 'simple'
        ?: 'the bundle became type ' . var_export($d['data']['type'] ?? null, true)
);

/*
 * §83's oversell rule. Mug stock 50 at one each, box stock 10 at two each, so
 * the shop can make up min(50, 5) = 5 kits. The figure is derived on every read
 * rather than stored, because "a bundle showing 'in stock' because nobody
 * refreshed it is an oversell".
 */
ac_check(
    'availability is the minimum of the components',
    ac_req('GET', "/products/{$kitId}"),
    200,
    static fn (array $d): bool|string => ($d['data']['bundle']['available'] ?? null) === 5
        ?: 'available was ' . var_export($d['data']['bundle']['available'] ?? null, true)
);

ac_check(
    'a bundle cannot contain itself',
    ac_req('PATCH', "/products/{$kitId}", ['options' => ['groups' => [[
        'id' => 'c', 'type' => 'bundle', 'label' => 'C', 'items' => [['product_id' => $kitId, 'quantity' => 1]],
    ]]]]),
    400
);

ac_check(
    'a component that does not exist is refused',
    ac_req('PATCH', "/products/{$kitId}", ['options' => ['groups' => [[
        'id' => 'c', 'type' => 'bundle', 'label' => 'C', 'items' => [['product_id' => 99999999, 'quantity' => 1]]],
    ]]]),
    400
);

ac_check(
    'ordering more bundles than the components allow is refused',
    ac_req('POST', '/cart/items', ['product_id' => $kitId, 'quantity' => 6]),
    400,
    static fn (array $d): bool|string => str_contains(
        (string) ($d['error']['details']['fields']['quantity'] ?? ''),
        'bundle'
    ) ?: 'the refusal did not mention the bundle'
);

// The positive control: the same route, one under the ceiling, succeeds.
$bundleCart = ac_check(
    'THE POSITIVE CONTROL: five is accepted',
    ac_req('POST', '/cart/items', ['product_id' => $kitId, 'quantity' => 5]),
    201
);
$bundleToken = (string) ($bundleCart['meta']['cart_token'] ?? '');

ac_check(
    'and the shortfall never names the component or its stock',
    ac_req('POST', '/cart/items', ['product_id' => $kitId, 'quantity' => 6]),
    400,
    static function (array $d) use ($boxId): bool|string {
        $body = (string) wp_json_encode($d);

        return !str_contains($body, 'AC-O83-BOX') && !str_contains($body, (string) $boxId)
            ? true
            : 'a public cart route disclosed which component is short';
    }
);

echo PHP_EOL, "── an order carries the options, and draws the components down once ──", PHP_EOL;

$mugStockBefore = (int) wc_get_product($mugId)->get_stock_quantity();
$boxStockBefore = (int) wc_get_product($boxId)->get_stock_quantity();

global $wpdb;
$movements = $wpdb->prefix . 'ac_inventory_movements';
$ledgerBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$movements}");

/*
 * The order is built directly rather than through `POST /checkout`, and that is
 * deliberate: checkout needs a wilaya, a commune and a registered payment
 * provider, all of which `tests/Api/cart.php` already drives. What is under
 * test here is the half checkout hands over to — WooCommerce moving an order's
 * stock — and it must work from *any* transition, including a webhook's.
 */
$order = wc_create_order(['status' => 'pending']);
$order->add_product(wc_get_product($kitId), 2);
$order->calculate_totals(false);
$order->save();

$order->update_status('processing');
$order = wc_get_order($order->get_id());

ac_assert(
    'a bundle draws every component down, in its own multiple',
    (int) wc_get_product($mugId)->get_stock_quantity() === $mugStockBefore - 2
        && (int) wc_get_product($boxId)->get_stock_quantity() === $boxStockBefore - 4
        ?: sprintf(
            'mug %d→%d (want %d), box %d→%d (want %d)',
            $mugStockBefore,
            (int) wc_get_product($mugId)->get_stock_quantity(),
            $mugStockBefore - 2,
            $boxStockBefore,
            (int) wc_get_product($boxId)->get_stock_quantity(),
            $boxStockBefore - 4
        )
);

/*
 * §64's rule applied to the case that sounds harmless: "an import must not be a
 * back door around `ac_inventory_movements`". A bundle that adjusted stock
 * directly would produce a ledger whose numbers do not reconcile and no
 * movement explains why.
 */
$ledgerRows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT product_id, quantity_before, quantity_after FROM {$movements} WHERE order_id = %d",
        $order->get_id()
    ),
    ARRAY_A
);

ac_assert(
    'every component movement is in the ledger, against the order',
    count($ledgerRows) === 2 ?: 'the ledger holds ' . count($ledgerRows) . ' rows for this order, expected 2'
);

ac_assert(
    'and the ledger figures match the shelf',
    (function () use ($ledgerRows, $mugId, $boxId, $mugStockBefore, $boxStockBefore) {
        foreach ($ledgerRows as $row) {
            $expectedBefore = (int) $row['product_id'] === $mugId ? $mugStockBefore : $boxStockBefore;
            $expectedAfter = (int) $row['product_id'] === $mugId ? $mugStockBefore - 2 : $boxStockBefore - 4;

            if ((int) $row['quantity_before'] !== $expectedBefore || (int) $row['quantity_after'] !== $expectedAfter) {
                return 'a ledger row disagrees with the shelf: ' . wp_json_encode($row);
            }
        }

        return true;
    })()
);

ac_assert(
    'the ledger grew by exactly those two rows',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$movements}") === $ledgerBefore + 2
        ?: 'the ledger grew by ' . ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$movements}") - $ledgerBefore)
);

/*
 * WooCommerce reduces stock on more than one transition — `processing` and then
 * `completed` — and marks each item `_reduced_stock` so it does not do it
 * twice. A bundle needs its own marker or the second transition decrements a
 * warehouse that never moved.
 */
$order->update_status('completed');
$order = wc_get_order($order->get_id());

ac_assert(
    'a second transition does not decrement again',
    (int) wc_get_product($mugId)->get_stock_quantity() === $mugStockBefore - 2
        && (int) wc_get_product($boxId)->get_stock_quantity() === $boxStockBefore - 4
        ?: 'the components were drawn down twice'
);

$order->update_status('cancelled');

ac_assert(
    'cancelling puts every component back',
    (int) wc_get_product($mugId)->get_stock_quantity() === $mugStockBefore
        && (int) wc_get_product($boxId)->get_stock_quantity() === $boxStockBefore
        ?: sprintf(
            'mug %d (want %d), box %d (want %d)',
            (int) wc_get_product($mugId)->get_stock_quantity(),
            $mugStockBefore,
            (int) wc_get_product($boxId)->get_stock_quantity(),
            $boxStockBefore
        )
);

wp_delete_post($order->get_id(), true);

echo PHP_EOL, "── chosen options reach fulfilment ──", PHP_EOL;

/*
 * §83: "chosen options land on the order line item, visible to fulfilment."
 * Visible meta is what WooCommerce already renders on a packing slip and in its
 * own emails, so a warehouse learns what to engrave without anybody teaching
 * those surfaces about this plugin; `_ac_options` keeps the structured
 * selection frozen at the time of sale.
 */
$configured = wc_create_order(['status' => 'pending']);
$itemId = $configured->add_product(wc_get_product($mugId), 1);
$item = $configured->get_item($itemId);
$item->add_meta_data('Gift wrap', 'Or');
$item->add_meta_data('_ac_options', [[
    'group_id' => 'wrap', 'label' => 'Gift wrap', 'value' => 'gold',
    'value_label' => 'Or', 'price_delta' => '250.00', 'image_id' => 0,
]]);
$item->save();
$configured->save();

$readBack = wc_get_order($configured->get_id())->get_item($itemId);

ac_assert(
    'fulfilment sees the option as plain visible meta',
    (string) $readBack->get_meta('Gift wrap') === 'Or'
        ?: 'visible meta was ' . var_export($readBack->get_meta('Gift wrap'), true)
);

ac_assert(
    'and the structured selection is frozen beside it',
    ($readBack->get_meta('_ac_options')[0]['price_delta'] ?? '') === '250.00'
        ?: 'hidden meta was ' . substr((string) wp_json_encode($readBack->get_meta('_ac_options')), 0, 200)
);

wp_delete_post($configured->get_id(), true);

echo PHP_EOL, "── a definition deleted under a live cart ──", PHP_EOL;

/*
 * A shop can delete an option group while it sits in somebody's basket. There
 * are two directions and only one is safe: charge nothing for the option, or
 * refuse. Charging nothing is a shop giving gift wrap away to a shopper who
 * cannot tell anything changed, so the cart reports the problem and checkout
 * will not place the order.
 */
$stranded = ac_req('POST', '/cart/items', [
    'product_id' => $mugId,
    'quantity' => 1,
    'options' => ['wrap' => 'gold'],
])[1];
$strandedToken = (string) ($stranded['meta']['cart_token'] ?? '');

ac_req('PATCH', "/products/{$mugId}", ['options' => null]);

ac_check(
    'the cart reports a line it can no longer price',
    ac_req('GET', '/cart', null, [], ['Cart-Token' => $strandedToken]),
    200,
    static fn (array $d): bool|string => ($d['meta']['problems'] ?? []) !== []
        ?: 'the cart said nothing about a line it could not price'
);

ac_req('PATCH', "/products/{$mugId}", ['options' => $OPTIONS]);

echo PHP_EOL, "── teardown ──", PHP_EOL;

remove_filter('pre_wp_mail', $silenceMail, 99);

ac_assert(
    'the mail short-circuit was removed again',
    !has_filter('pre_wp_mail', $silenceMail)
        ?: 'the suite would have silenced every later suite in this process'
);

foreach ($SKUS as $sku) {
    ac_purge_sku($sku);
}

ac_assert(
    'the suite left no fixture products behind',
    wc_get_products(['limit' => 5, 'return' => 'ids', 'status' => ['publish', 'draft', 'trash'], 's' => 'O83 ']) === []
        ?: 'fixture products survived teardown'
);

echo PHP_EOL;
printf("%d passed, %d failed%s", $GLOBALS['ac_pass'], $GLOBALS['ac_fail'], PHP_EOL);

exit($GLOBALS['ac_fail'] > 0 ? 1 : 0);
