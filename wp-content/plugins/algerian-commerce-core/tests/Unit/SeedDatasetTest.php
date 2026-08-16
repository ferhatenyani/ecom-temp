<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Seed\SeedDataset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Roadmap §67, docs/PLAN.md §46.
 *
 * `GeoDatasetTest` is the model. The rules worth testing here are the ones the
 * write validation cannot reach, because they are about the fixtures as a set
 * rather than about any one payload: a product in a category nobody defined, an
 * order naming a SKU nobody sells, a variation whose attribute its parent does
 * not offer — and §46's "never use real customer data", which has no other home.
 *
 * The last one is the reason this file exists at all. Every other rule fails
 * loudly when the seeder runs; a fixture carrying a colleague's real email
 * address seeds perfectly and mails them the first time somebody drains the
 * notification queue.
 */
final class SeedDatasetTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private static function product(array $overrides = []): array
    {
        return [
            'sku' => 'AC-1',
            'name' => 'A rug',
            'type' => 'simple',
            'categories' => ['tapis'],
            'regular_price' => '1000',
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private static function catalogue(array $overrides = []): array
    {
        return [
            'categories' => [['slug' => 'tapis', 'name' => 'Tapis']],
            'products' => [self::product()],
            ...$overrides,
        ];
    }

    // ------------------------------------------------------------- catalogue

    public function testAcceptsAMinimalCatalogue(): void
    {
        $result = SeedDataset::catalogue(self::catalogue());

        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['categories']);
        self::assertCount(1, $result['products']);
        self::assertSame('AC-1', $result['products'][0]['sku']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function badCatalogueProvider(): array
    {
        return [
            'a string' => ['nope'],
            'null' => [null],
            'no products key' => [['categories' => []]],
            'no categories key' => [['products' => []]],
            'products is a map' => [['categories' => [], 'products' => ['a' => []]]],
        ];
    }

    #[DataProvider('badCatalogueProvider')]
    public function testRejectsABrokenCatalogueShape(mixed $decoded): void
    {
        $result = SeedDataset::catalogue($decoded);

        self::assertNotSame([], $result['errors']);
        self::assertSame([], $result['products']);
    }

    public function testRejectsACategorySlugThatIsNotASlug(): void
    {
        $result = SeedDataset::catalogue([
            'categories' => [['slug' => 'Tapis & Textiles', 'name' => 'Tapis']],
            'products' => [],
        ]);

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('slug', $result['errors'][0]);
    }

    public function testRejectsADuplicateCategory(): void
    {
        $result = SeedDataset::catalogue([
            'categories' => [
                ['slug' => 'tapis', 'name' => 'Tapis'],
                ['slug' => 'tapis', 'name' => 'Rugs'],
            ],
            'products' => [],
        ]);

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('more than once', $result['errors'][0]);
        self::assertCount(1, $result['categories']);
    }

    public function testRejectsAProductInAnUndefinedCategory(): void
    {
        $result = SeedDataset::catalogue(self::catalogue([
            'products' => [self::product(['categories' => ['poterie']])],
        ]));

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('not in this file', $result['errors'][0]);
        self::assertSame([], $result['products']);
    }

    public function testRejectsAProductWithNoCategory(): void
    {
        $result = SeedDataset::catalogue(self::catalogue([
            'products' => [self::product(['categories' => []])],
        ]));

        self::assertCount(1, $result['errors']);
    }

    public function testRejectsAProductWithNoSku(): void
    {
        $product = self::product();
        unset($product['sku']);

        $result = SeedDataset::catalogue(self::catalogue(['products' => [$product]]));

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('idempotency key', $result['errors'][0]);
    }

    /** Re-running the seeder keys on the SKU, so two rows sharing one fight. */
    public function testRejectsADuplicateSku(): void
    {
        $result = SeedDataset::catalogue(self::catalogue([
            'products' => [self::product(), self::product(['name' => 'Another rug'])],
        ]));

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('more than once', $result['errors'][0]);
    }

    /** WooCommerce keeps products and variations in one SKU namespace. */
    public function testAVariationMayNotReuseAProductSku(): void
    {
        $result = SeedDataset::catalogue(self::catalogue([
            'products' => [
                self::product(),
                self::product([
                    'sku' => 'AC-2',
                    'type' => 'variable',
                    'attributes' => [['name' => 'Taille', 'options' => ['S'], 'variation' => true]],
                    'variations' => [['sku' => 'AC-1', 'attributes' => ['taille' => 'S']]],
                ]),
            ],
        ]));

        self::assertNotSame([], $result['errors']);
        self::assertStringContainsString('more than once', implode(' ', $result['errors']));
    }

    public function testRejectsAnUnknownProductKey(): void
    {
        // The point of the rule: `stock_quantiy` seeds silently and the shop
        // sells something it does not have.
        $result = SeedDataset::catalogue(self::catalogue([
            'products' => [self::product(['stock_quantiy' => 5])],
        ]));

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('stock_quantiy', $result['errors'][0]);
    }

    public function testRejectsASimpleProductWithNoPrice(): void
    {
        $product = self::product();
        unset($product['regular_price']);

        $result = SeedDataset::catalogue(self::catalogue(['products' => [$product]]));

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('regular_price', $result['errors'][0]);
    }

    public function testAVariableProductNeedsNoPriceOfItsOwn(): void
    {
        $result = SeedDataset::catalogue(self::catalogue([
            'products' => [[
                'sku' => 'AC-V',
                'name' => 'Burnous',
                'type' => 'variable',
                'categories' => ['tapis'],
                'attributes' => [['name' => 'Taille', 'options' => ['S', 'M'], 'variation' => true]],
                'variations' => [
                    ['sku' => 'AC-V-S', 'attributes' => ['taille' => 'S'], 'regular_price' => '100'],
                    ['sku' => 'AC-V-M', 'attributes' => ['taille' => 'M'], 'regular_price' => '100'],
                ],
            ]],
        ]));

        self::assertSame([], $result['errors']);
        self::assertCount(2, $result['products'][0]['variations']);
    }

    // ------------------------------------------------------------ variations

    /** @param array<string, mixed> $variation */
    private static function variableCatalogue(array $variation, array $options = ['S', 'M']): array
    {
        return self::catalogue([
            'products' => [[
                'sku' => 'AC-V',
                'name' => 'Burnous',
                'type' => 'variable',
                'categories' => ['tapis'],
                'attributes' => [['name' => 'Taille', 'options' => $options, 'variation' => true]],
                'variations' => [$variation],
            ]],
        ]);
    }

    public function testRejectsAVariationOnAnAttributeTheParentDoesNotVaryOn(): void
    {
        $result = SeedDataset::catalogue(self::variableCatalogue([
            'sku' => 'AC-V-1',
            'attributes' => ['couleur' => 'Rouge'],
        ]));

        self::assertNotSame([], $result['errors']);
        self::assertStringContainsString('does not vary on', $result['errors'][0]);
    }

    public function testRejectsAVariationOptionTheParentDoesNotOffer(): void
    {
        $result = SeedDataset::catalogue(self::variableCatalogue([
            'sku' => 'AC-V-1',
            'attributes' => ['taille' => 'XXL'],
        ]));

        self::assertNotSame([], $result['errors']);
        self::assertStringContainsString('not one of the parent', $result['errors'][0]);
    }

    /**
     * The trap this check exists for. `VariationService` keys the parent's
     * attributes with strtolower(), so "Taille" and "taille" are the same
     * attribute — a fixture writing either must pass.
     */
    public function testTheAttributeKeyIsMatchedCaseInsensitively(): void
    {
        $result = SeedDataset::catalogue(self::variableCatalogue([
            'sku' => 'AC-V-1',
            'attributes' => ['Taille' => 'S'],
        ]));

        self::assertSame([], $result['errors']);
        self::assertSame(['taille' => 'S'], $result['products'][0]['variations'][0]['attributes']);
    }

    public function testRejectsAVariationThatLeavesAnAttributeUnset(): void
    {
        $result = SeedDataset::catalogue(self::catalogue([
            'products' => [[
                'sku' => 'AC-V',
                'name' => 'Burnous',
                'type' => 'variable',
                'categories' => ['tapis'],
                'attributes' => [
                    ['name' => 'Taille', 'options' => ['S'], 'variation' => true],
                    ['name' => 'Couleur', 'options' => ['Rouge'], 'variation' => true],
                ],
                'variations' => [['sku' => 'AC-V-1', 'attributes' => ['taille' => 'S']]],
            ]],
        ]));

        self::assertNotSame([], $result['errors']);
        self::assertStringContainsString('does not set: couleur', $result['errors'][0]);
    }

    public function testRejectsTwoVariationsClaimingTheSameCombination(): void
    {
        $result = SeedDataset::catalogue(self::catalogue([
            'products' => [[
                'sku' => 'AC-V',
                'name' => 'Burnous',
                'type' => 'variable',
                'categories' => ['tapis'],
                'attributes' => [['name' => 'Taille', 'options' => ['S'], 'variation' => true]],
                'variations' => [
                    ['sku' => 'AC-V-1', 'attributes' => ['taille' => 'S']],
                    ['sku' => 'AC-V-2', 'attributes' => ['taille' => 'S']],
                ],
            ]],
        ]));

        self::assertNotSame([], $result['errors']);
        self::assertStringContainsString('repeats a combination', $result['errors'][0]);
    }

    public function testRejectsAVariableProductWithNoVariations(): void
    {
        $result = SeedDataset::catalogue(self::catalogue([
            'products' => [self::product([
                'sku' => 'AC-V',
                'type' => 'variable',
                'attributes' => [['name' => 'Taille', 'options' => ['S'], 'variation' => true]],
            ])],
        ]));

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('nothing would be buyable', $result['errors'][0]);
    }

    public function testRejectsAVariableProductWithNoVariationAttribute(): void
    {
        $result = SeedDataset::catalogue(self::catalogue([
            'products' => [self::product([
                'sku' => 'AC-V',
                'type' => 'variable',
                'attributes' => [['name' => 'Matière', 'options' => ['Laine']]],
                'variations' => [['sku' => 'AC-V-1', 'attributes' => ['matière' => 'Laine']]],
            ])],
        ]));

        self::assertNotSame([], $result['errors']);
        self::assertStringContainsString('variation', $result['errors'][0]);
    }

    public function testRejectsASimpleProductCarryingVariations(): void
    {
        $result = SeedDataset::catalogue(self::catalogue([
            'products' => [self::product(['variations' => [['sku' => 'AC-1-S']]])],
        ]));

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('simple and cannot have variations', $result['errors'][0]);
    }

    /** A non-variation attribute is an ordinary display attribute. */
    public function testASimpleProductMayCarryADisplayAttribute(): void
    {
        $result = SeedDataset::catalogue(self::catalogue([
            'products' => [self::product(['attributes' => [['name' => 'Matière', 'options' => ['Laine']]]])],
        ]));

        self::assertSame([], $result['errors']);
    }

    // ------------------------------------------------------------- customers

    /** @param array<string, mixed> $overrides */
    private static function customer(array $overrides = []): array
    {
        return [
            'email' => 'amina@example.test',
            'first_name' => 'Amina',
            'last_name' => 'Belkacem',
            ...$overrides,
        ];
    }

    public function testAcceptsACustomer(): void
    {
        $result = SeedDataset::customers(['customers' => [self::customer()]]);

        self::assertSame([], $result['errors']);
        self::assertSame('amina@example.test', $result['rows'][0]['email']);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function emailProvider(): array
    {
        return [
            'a reserved TLD' => ['someone@example.test', true],
            'dotted subdomain of a reserved TLD' => ['someone@shop.example.test', true],
            '.invalid' => ['someone@nowhere.invalid', true],
            '.localhost' => ['someone@dev.localhost', true],
            'example.com itself' => ['someone@example.com', true],
            'a subdomain of example.com' => ['someone@mail.example.com', true],
            'a real domain' => ['someone@gmail.com', false],
            // The reason the dot is not optional: this is a domain somebody
            // could actually register, and it ends with "example.com".
            'a lookalike domain' => ['someone@badexample.com', false],
            'a company domain' => ['ferhat@algeriancommerce.dz', false],
        ];
    }

    #[DataProvider('emailProvider')]
    public function testTheTestDomainRuleIsExact(string $email, bool $allowed): void
    {
        self::assertSame($allowed, SeedDataset::isTestAddress($email));
    }

    /**
     * docs/PLAN.md §46, and the only rule here with real-world consequences:
     * the seeder queues a notification per order, and a drain would mail
     * whoever these addresses belong to.
     */
    public function testRejectsACustomerOnARealDomain(): void
    {
        $result = SeedDataset::customers([
            'customers' => [self::customer(['email' => 'someone@gmail.com'])],
        ]);

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('§46', $result['errors'][0]);
        self::assertSame([], $result['rows']);
    }

    public function testRejectsADuplicateCustomer(): void
    {
        $result = SeedDataset::customers([
            'customers' => [self::customer(), self::customer(['first_name' => 'Amine'])],
        ]);

        self::assertCount(1, $result['errors']);
        self::assertCount(1, $result['rows']);
    }

    public function testEmailIsLowercasedBecauseItIsTheIdempotencyKey(): void
    {
        $result = SeedDataset::customers([
            'customers' => [self::customer(['email' => 'Amina@Example.Test'])],
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame('amina@example.test', $result['rows'][0]['email']);
    }

    public function testRejectsACustomerWithNoName(): void
    {
        $result = SeedDataset::customers([
            'customers' => [self::customer(['last_name' => '  '])],
        ]);

        self::assertCount(1, $result['errors']);
    }

    public function testRejectsAnUnknownAddressField(): void
    {
        $result = SeedDataset::customers([
            'customers' => [self::customer(['billing' => ['wilaya' => 'Alger']])],
        ]);

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('wilaya', $result['errors'][0]);
    }

    /** WooCommerce stores no shipping email, and AddressInput refuses one. */
    public function testACustomerBillingBlockMayNotCarryAnEmail(): void
    {
        $result = SeedDataset::customers([
            'customers' => [self::customer(['billing' => ['email' => 'x@example.test']])],
        ]);

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('email', $result['errors'][0]);
    }

    // --------------------------------------------------------------- coupons

    public function testAcceptsACoupon(): void
    {
        $result = SeedDataset::coupons(
            ['coupons' => [['code' => 'BIENVENUE10', 'discount_type' => 'percent', 'amount' => '10']]],
            []
        );

        self::assertSame([], $result['errors']);
        // WooCommerce lowercases a code on save, so the seeder looks it up that way.
        self::assertSame('bienvenue10', $result['rows'][0]['code']);
    }

    public function testTwoCouponsDifferingOnlyInCaseAreOneCoupon(): void
    {
        $result = SeedDataset::coupons([
            'coupons' => [
                ['code' => 'PROMO', 'discount_type' => 'percent', 'amount' => '10'],
                ['code' => 'promo', 'discount_type' => 'percent', 'amount' => '20'],
            ],
        ], []);

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('case-insensitive', $result['errors'][0]);
    }

    public function testRejectsAnUnknownDiscountType(): void
    {
        $result = SeedDataset::coupons(
            ['coupons' => [['code' => 'X', 'discount_type' => 'percentage', 'amount' => '10']]],
            []
        );

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('percentage', $result['errors'][0]);
    }

    public function testRejectsACouponRestrictedToAnUndefinedCategory(): void
    {
        $result = SeedDataset::coupons([
            'coupons' => [[
                'code' => 'TAPIS15', 'discount_type' => 'percent', 'amount' => '15',
                'product_categories' => ['poterie'],
            ]],
        ], ['tapis' => true]);

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('catalogue.json', $result['errors'][0]);
    }

    public function testRejectsMaximumDiscountByName(): void
    {
        // PLAN §21 asks for a discount cap; WooCommerce has no such field, and
        // roadmap step 33 refuses it rather than silently storing something
        // else. The fixtures must not be able to smuggle it back in.
        $result = SeedDataset::coupons([
            'coupons' => [[
                'code' => 'X', 'discount_type' => 'percent', 'amount' => '15',
                'maximum_discount' => '500',
            ]],
        ], []);

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('maximum_discount', $result['errors'][0]);
    }

    // ---------------------------------------------------------------- orders

    /** @param array<string, mixed> $overrides */
    private static function order(array $overrides = []): array
    {
        return [
            'ref' => 'order-001',
            'customer' => 'amina@example.test',
            'status' => 'processing',
            'items' => [['sku' => 'AC-1', 'quantity' => 2]],
            ...$overrides,
        ];
    }

    private const SKUS = ['ac-1' => true];
    private const EMAILS = ['amina@example.test' => true];

    public function testAcceptsAnOrder(): void
    {
        $result = SeedDataset::orders(['orders' => [self::order()]], self::SKUS, self::EMAILS);

        self::assertSame([], $result['errors']);
        self::assertSame('order-001', $result['rows'][0]['ref']);
        self::assertSame(2, $result['rows'][0]['items'][0]['quantity']);
    }

    public function testRejectsAnOrderForAnUnknownSku(): void
    {
        $result = SeedDataset::orders(
            ['orders' => [self::order(['items' => [['sku' => 'AC-9', 'quantity' => 1]]])]],
            self::SKUS,
            self::EMAILS
        );

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('catalogue.json does not define', $result['errors'][0]);
    }

    public function testRejectsAnOrderForAnUnknownCustomer(): void
    {
        $result = SeedDataset::orders(
            ['orders' => [self::order(['customer' => 'nobody@example.test'])]],
            self::SKUS,
            self::EMAILS
        );

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('not in customers.json', $result['errors'][0]);
    }

    public function testRejectsADuplicateRef(): void
    {
        $result = SeedDataset::orders(
            ['orders' => [self::order(), self::order(['status' => 'pending'])]],
            self::SKUS,
            self::EMAILS
        );

        self::assertCount(1, $result['errors']);
        self::assertCount(1, $result['rows']);
    }

    /**
     * `cancelled` and `refunded` are not creatable: an order born cancelled
     * records the calling-off of something that was never placed (OrderStatus).
     */
    public function testRejectsAnOrderCreatedAsCancelled(): void
    {
        $result = SeedDataset::orders(
            ['orders' => [self::order(['status' => 'cancelled'])]],
            self::SKUS,
            self::EMAILS
        );

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('cannot be created as', $result['errors'][0]);
    }

    /** Which is what final_status is for — a second, legal move. */
    public function testAcceptsACancellationReachedByATransition(): void
    {
        $result = SeedDataset::orders(
            ['orders' => [self::order(['status' => 'processing', 'final_status' => 'cancelled'])]],
            self::SKUS,
            self::EMAILS
        );

        self::assertSame([], $result['errors']);
        self::assertSame('cancelled', $result['rows'][0]['final_status']);
    }

    public function testRejectsAnIllegalFinalStatus(): void
    {
        // pending → refunded claims money changed hands on an order that was
        // never paid.
        $result = SeedDataset::orders(
            ['orders' => [self::order(['status' => 'pending', 'final_status' => 'refunded'])]],
            self::SKUS,
            self::EMAILS
        );

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('cannot move pending → refunded', $result['errors'][0]);
    }

    public function testRejectsAFinalStatusThatChangesNothing(): void
    {
        $result = SeedDataset::orders(
            ['orders' => [self::order(['status' => 'processing', 'final_status' => 'processing'])]],
            self::SKUS,
            self::EMAILS
        );

        self::assertCount(1, $result['errors']);
    }

    public function testRejectsAZeroQuantity(): void
    {
        $result = SeedDataset::orders(
            ['orders' => [self::order(['items' => [['sku' => 'AC-1', 'quantity' => 0]]])]],
            self::SKUS,
            self::EMAILS
        );

        self::assertCount(1, $result['errors']);
    }

    public function testRejectsAnOrderWithNoItems(): void
    {
        $result = SeedDataset::orders(
            ['orders' => [self::order(['items' => []])]],
            self::SKUS,
            self::EMAILS
        );

        self::assertCount(1, $result['errors']);
    }

    /**
     * A guest order is reachable by nobody through /account/orders (§59c) —
     * customer_id 0 can never match a session — so its billing email is the
     * only handle anyone has on it.
     */
    public function testRejectsAGuestOrderWithNoEmail(): void
    {
        $result = SeedDataset::orders(
            ['orders' => [self::order(['customer' => null, 'billing' => ['city' => 'Alger']])]],
            self::SKUS,
            self::EMAILS
        );

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('guest order', $result['errors'][0]);
    }

    public function testAcceptsAGuestOrderWithAnEmail(): void
    {
        $result = SeedDataset::orders([
            'orders' => [self::order([
                'customer' => null,
                'billing' => ['email' => 'rachid@example.test', 'city' => 'Alger'],
            ])],
        ], self::SKUS, self::EMAILS);

        self::assertSame([], $result['errors']);
        self::assertNull($result['rows'][0]['customer']);
    }

    public function testAGuestOrderMayNotUseARealEmailEither(): void
    {
        $result = SeedDataset::orders([
            'orders' => [self::order([
                'customer' => null,
                'billing' => ['email' => 'rachid@gmail.com'],
            ])],
        ], self::SKUS, self::EMAILS);

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('§46', $result['errors'][0]);
    }

    public function testRejectsAnUnknownOrderKey(): void
    {
        $result = SeedDataset::orders(
            ['orders' => [self::order(['total' => '5000'])]],
            self::SKUS,
            self::EMAILS
        );

        self::assertCount(1, $result['errors']);
        self::assertStringContainsString('total', $result['errors'][0]);
    }

    public function testEveryProblemIsReportedNotJustTheFirst(): void
    {
        // The seeder writes nothing when any file is bad, so the operator has
        // to see the whole list rather than fix them one run at a time.
        $result = SeedDataset::orders([
            'orders' => [
                self::order(['ref' => 'a', 'customer' => 'nobody@example.test']),
                self::order(['ref' => 'b', 'status' => 'refunded']),
                self::order(['ref' => 'c', 'items' => []]),
            ],
        ], self::SKUS, self::EMAILS);

        self::assertCount(3, $result['errors']);
    }
}
