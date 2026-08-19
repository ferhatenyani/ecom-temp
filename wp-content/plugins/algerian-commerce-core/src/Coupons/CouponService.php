<?php

declare(strict_types=1);

namespace AlgerianCommerce\Coupons;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WC_Coupon;

/**
 * Coupon operations — docs/PLAN.md §21, roadmap step 33.
 *
 * **This step was owed.** §59b's cart shipped `POST /cart/coupons`, so a shopper
 * could apply a discount the API had no way to create — a shop had to open
 * wp-admin, which is the thing a headless build exists to avoid. The rule
 * everywhere else in this plugin applies here too: the discount arithmetic is
 * WooCommerce's, and this class owns authorization, validation and the audit
 * trail.
 *
 * Authorization is `ac_manage_coupons`, which already existed in
 * `Capabilities` and is held by Admin, Manager and Marketing Manager. **No new
 * capability was invented** — §61's media gap set that precedent, and PLAN §3's
 * vocabulary already had an answer here.
 *
 * Asserted in the service as well as on the route, so a WP-CLI command or a
 * future bulk import cannot reach a write without passing the same check
 * (docs/SECURITY.md, "Authorization").
 */
final class CouponService
{
    public function __construct(
        private readonly CouponRepository $repository,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{items: list<WC_Coupon>, total: int}
     */
    public function list(array $criteria): array
    {
        Permissions::assert(Capabilities::MANAGE_COUPONS);

        return $this->repository->paginate($criteria);
    }

    public function get(int $id): WC_Coupon
    {
        Permissions::assert(Capabilities::MANAGE_COUPONS);

        return $this->requireCoupon($id);
    }

    /**
     * Products this shop's coupons may be restricted to.
     *
     * **`ac_manage_coupons`, not `ac_manage_products`** — and asserted here as well
     * as on the route, like every other method on this class, so a CLI command
     * cannot reach it without the same check. The reasoning is in
     * `CouponController::registerRoutes()`: without this, the one role whose job is
     * coupons could not see what a coupon applied to.
     *
     * @param array{page: int, per_page: int, search: string, include: list<int>} $criteria
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function eligibleProducts(array $criteria): array
    {
        Permissions::assert(Capabilities::MANAGE_COUPONS);

        return $this->repository->eligibleProducts($criteria);
    }

    /**
     * @param array{page: int, per_page: int, search: string, include: list<int>} $criteria
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function eligibleCategories(array $criteria): array
    {
        Permissions::assert(Capabilities::MANAGE_COUPONS);

        return $this->repository->eligibleCategories($criteria);
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload): WC_Coupon
    {
        Permissions::assert(Capabilities::MANAGE_COUPONS);

        $input = CouponInput::fromPayload($payload, true);

        $this->guardCode((string) $input->get('code'));
        $this->guardRestrictions($input);

        $coupon = $this->repository->create($input);

        $this->audit->record('coupon.created', 'coupon', $coupon->get_id(), [
            'code' => $coupon->get_code(),
            'discount_type' => $coupon->get_discount_type(),
            'amount' => $coupon->get_amount(),
        ]);

        return $coupon;
    }

    /** @param array<string, mixed> $payload */
    public function update(int $id, array $payload): WC_Coupon
    {
        Permissions::assert(Capabilities::MANAGE_COUPONS);

        $coupon = $this->requireCoupon($id);
        $input = CouponInput::fromPayload($payload, false);

        if ($input->has('code')) {
            $this->guardCode((string) $input->get('code'), $id);
        }

        /*
         * A percentage check that spans the two fields. `CouponInput` catches
         * `{discount_type: percent, amount: 150}` in one payload; this catches
         * `{amount: 150}` sent alone against a coupon that is *already* a
         * percentage, which the pure class cannot see because it does not know
         * what is stored.
         */
        $type = $input->has('discount_type')
            ? (string) $input->get('discount_type')
            : $coupon->get_discount_type();

        if ($type === 'percent' && $input->has('amount') && (float) $input->get('amount') > 100) {
            throw ApiException::invalidRequest('The coupon is invalid.', [
                'fields' => ['amount' => 'A percentage discount cannot exceed 100.'],
            ]);
        }

        $this->guardRestrictions($input);

        $updated = $this->repository->update($coupon, $input);

        $this->audit->record('coupon.updated', 'coupon', $id, [
            'code' => $updated->get_code(),
            'fields' => array_keys($input->all()),
        ]);

        return $updated;
    }

    public function delete(int $id, bool $force): void
    {
        Permissions::assert(Capabilities::MANAGE_COUPONS);

        $coupon = $this->requireCoupon($id);
        $code = $coupon->get_code();
        $used = (int) $coupon->get_usage_count();

        if (!$this->repository->delete($coupon, $force)) {
            throw ApiException::internal('The coupon could not be deleted.');
        }

        $this->audit->record($force ? 'coupon.deleted' : 'coupon.trashed', 'coupon', $id, [
            'code' => $code,
            'permanent' => $force,
            // Worth recording: deleting a coupon that has been redeemed removes
            // the only description of a discount already applied to real orders.
            'usage_count' => $used,
        ]);
    }

    /**
     * A duplicate code is a conflict, not a validation error.
     *
     * **The trash counts**, which §65 learned the hard way on product SKUs: a
     * trashed coupon keeps its code, so a check that ignored the trash would
     * report a code free and then fail inside WooCommerce. Here the failure
     * would be quieter still — WooCommerce would create a second coupon with
     * the same code and `wc_get_coupon_id_by_code()` would return whichever it
     * found first.
     */
    private function guardCode(string $code, int $ignoreId = 0): void
    {
        if ($code !== '' && $this->repository->codeExists($code, $ignoreId)) {
            throw ApiException::conflict('That coupon code is already in use.', ['code' => $code]);
        }
    }

    /**
     * A restriction must name something that exists.
     *
     * **This was the quietest failure in the endpoint.** WooCommerce stores
     * `product_ids` without checking them, so every id this API was handed went
     * straight to the database: `{"product_ids": [999999]}` answered 200, and so
     * did a *customer* id in the same field. The coupon then applied to nothing and
     * looked, in every response and every screen, exactly like a coupon that was
     * working. A discount that silently never fires is worse than one that fails
     * loudly, because nobody goes looking for it.
     *
     * Refused as a field error rather than a conflict, and the message names the
     * ids so the caller can tell which of five they got wrong — `details.fields`
     * is the list a client renders beside its inputs.
     *
     * **Only on the way in.** Reads stay tolerant: a product deleted after the
     * coupon was written leaves a real, stale id that `CouponRestrictions` reports
     * as `missing` rather than hides. Validating a write cannot make a read total,
     * and pretending otherwise would mean a coupon becoming unreadable because
     * something it points at was trashed.
     */
    private function guardRestrictions(CouponInput $input): void
    {
        $errors = [];

        foreach (CouponRestrictions::PRODUCT_FIELDS as $field) {
            if (!$input->has($field)) {
                continue;
            }

            $missing = $this->repository->missingProducts((array) $input->get($field));

            if ($missing !== []) {
                $errors[$field] = 'No product with ' . self::plural($missing) . '.';
            }
        }

        foreach (CouponRestrictions::CATEGORY_FIELDS as $field) {
            if (!$input->has($field)) {
                continue;
            }

            $missing = $this->repository->missingCategories((array) $input->get($field));

            if ($missing !== []) {
                $errors[$field] = 'No product category with ' . self::plural($missing) . '.';
            }
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The coupon is invalid.', ['fields' => $errors]);
        }
    }

    /** @param list<int> $ids */
    private static function plural(array $ids): string
    {
        return count($ids) === 1
            ? 'id ' . $ids[0]
            : 'ids ' . implode(', ', $ids);
    }

    private function requireCoupon(int $id): WC_Coupon
    {
        $coupon = $this->repository->find($id);

        if ($coupon === null) {
            throw ApiException::notFound('No coupon with that id.');
        }

        return $coupon;
    }
}
