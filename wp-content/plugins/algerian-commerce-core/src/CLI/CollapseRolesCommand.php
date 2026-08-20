<?php

declare(strict_types=1);

namespace AlgerianCommerce\CLI;

use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Users\UserRoles;
use WP_CLI;
use WP_User;
use WP_User_Query;

/**
 * wp algerian-commerce collapse-roles
 *
 * Moves every staff account onto the two-tier model: Super Admin and Manager.
 *
 * **A command rather than a migration, deliberately.** `migrations/` alters this
 * plugin's own schema and runs unattended on deploy. This writes nobody's
 * schema — it rewrites `wp_capabilities` usermeta, which is who fifty-eight
 * people are allowed to be — and a change of that kind should be run by someone
 * who reads the output, not applied silently by a file sync. It is also not
 * versioned against `Schema::VERSION`, because nothing about the schema moved.
 *
 * **Dry run is the default.** `--apply` is the only thing that writes. The
 * refusal to act without it is the point: the two-line difference between
 * printing a plan and changing everyone's access should be typed on purpose.
 *
 * **Nothing is removed.** The five retired roles stay defined in
 * `Capabilities::roles()` and stay installed in WordPress, so this command never
 * strands an account on a role that no longer exists — the failure that would
 * leave a live credential authenticating fine and answering 403 everywhere. That
 * also makes `--rollback` real rather than aspirational: the roles it restores
 * to are still there.
 *
 * The prior role is written to `_ac_role_before_collapse` before anything
 * changes, and only when it is not already set, so re-running never overwrites
 * the original with an intermediate one. That key is the whole reversal record:
 * the collapse is lossy — twenty Support Agents and two Managers become
 * indistinguishable Managers — and without it a rollback could not tell them
 * apart.
 *
 * **This is not durable on a development stack, and that is not a bug.**
 * `assignable()` narrows what the *API* grants; `WP_User::set_role()` is
 * WordPress core and answers to nobody. `tests/Api/*` build their fixtures with
 * it, as does the panel's `mint-credential.sh`, so a full test run puts roughly
 * fifty accounts back onto retired roles and this command has to be re-run.
 * Measured: a clean run of `scripts/test.sh` reverted 49 of 54. On a real
 * install, where nothing calls `set_role()` behind the API, the collapse holds.
 *
 * The boundary being the API rather than the database is the same boundary
 * `administrator` and `shop_manager` have always sat outside — `UserRoles` has
 * never been able to stop wp-admin, only this API.
 */
final class CollapseRolesCommand
{
    /** Where the pre-collapse role is kept, so the move can be undone. */
    public const PRIOR_ROLE_META = '_ac_role_before_collapse';

    /**
     * Which tier each recognised role lands in.
     *
     * `administrator` is absent on purpose and is skipped wherever it appears:
     * it holds every `ac_*` capability by grant rather than by role, no tier
     * describes it, and re-roling the site owner's own account is how a
     * simplification becomes an outage.
     *
     * @var array<string, string>
     */
    private const TARGET = [
        Capabilities::SUPER_ADMIN => Capabilities::SUPER_ADMIN,
        Capabilities::ADMIN => Capabilities::SUPER_ADMIN,
        Capabilities::MANAGER => Capabilities::MANAGER,
        Capabilities::PRODUCT_MANAGER => Capabilities::MANAGER,
        Capabilities::ORDER_MANAGER => Capabilities::MANAGER,
        Capabilities::MARKETING_MANAGER => Capabilities::MANAGER,
        Capabilities::SUPPORT_AGENT => Capabilities::MANAGER,
    ];

    public function __construct(private readonly Logger $logger)
    {
    }

    /**
     * Collapse staff roles to Super Admin and Manager.
     *
     * ## OPTIONS
     *
     * [--apply]
     * : Write the changes. Without it the command reports what it would do and
     * changes nothing.
     *
     * [--rollback]
     * : Restore every account to the role recorded before the collapse, and
     * clear the record. Also honours --apply.
     *
     * ## EXAMPLES
     *
     *     wp algerian-commerce collapse-roles
     *     wp algerian-commerce collapse-roles --apply
     *     wp algerian-commerce collapse-roles --rollback --apply
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs = []): void
    {
        $apply = !empty($assocArgs['apply']);
        $rollback = !empty($assocArgs['rollback']);

        $users = $this->staffAccounts();

        if ($users === []) {
            WP_CLI::warning('No staff accounts found. Nothing to do.');

            return;
        }

        $plan = $rollback ? $this->planRollback($users) : $this->planCollapse($users);

        $this->render($plan, $rollback);

        if ($plan['moves'] === []) {
            WP_CLI::success($rollback
                ? 'Nothing to roll back — no account carries a pre-collapse role.'
                : 'Every staff account is already on the two-tier model.');

            return;
        }

        if (!$apply) {
            WP_CLI::log('');
            WP_CLI::warning(sprintf(
                'Dry run — nothing was written. Re-run with --apply to move %d account(s).',
                count($plan['moves'])
            ));

            return;
        }

        $this->execute($plan['moves'], $rollback);
    }

    /**
     * Every account holding a role this API recognises, plus administrators.
     *
     * Retired roles are included by `UserRoles::staff()` because `managed()`
     * still lists all seven — the reason that list did not shrink alongside
     * `assignable()`. An account this query missed would be an account the
     * collapse silently left behind.
     *
     * @return list<WP_User>
     */
    private function staffAccounts(): array
    {
        $query = new WP_User_Query([
            'role__in' => UserRoles::staff(),
            'number' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        /** @var list<WP_User> $results */
        $results = array_values($query->get_results());

        return $results;
    }

    /**
     * @param list<WP_User> $users
     * @return array{moves: list<array{user: WP_User, from: string, to: string}>, skipped: list<array{user: WP_User, why: string}>}
     */
    private function planCollapse(array $users): array
    {
        $moves = [];
        $skipped = [];

        foreach ($users as $user) {
            $from = $this->recognisedRole($user);

            if ($from === '') {
                $skipped[] = ['user' => $user, 'why' => 'no recognised role'];

                continue;
            }

            if ($from === 'administrator') {
                $skipped[] = ['user' => $user, 'why' => 'WordPress administrator — outside the model'];

                continue;
            }

            $to = self::TARGET[$from] ?? null;

            if ($to === null) {
                $skipped[] = ['user' => $user, 'why' => sprintf('unmapped role "%s"', $from)];

                continue;
            }

            if ($to === $from) {
                $skipped[] = ['user' => $user, 'why' => 'already on target tier'];

                continue;
            }

            $moves[] = ['user' => $user, 'from' => $from, 'to' => $to];
        }

        return ['moves' => $moves, 'skipped' => $skipped];
    }

    /**
     * @param list<WP_User> $users
     * @return array{moves: list<array{user: WP_User, from: string, to: string}>, skipped: list<array{user: WP_User, why: string}>}
     */
    private function planRollback(array $users): array
    {
        $moves = [];
        $skipped = [];

        foreach ($users as $user) {
            $prior = (string) get_user_meta((int) $user->ID, self::PRIOR_ROLE_META, true);

            if ($prior === '') {
                $skipped[] = ['user' => $user, 'why' => 'no pre-collapse role recorded'];

                continue;
            }

            $current = $this->recognisedRole($user);

            if ($prior === $current) {
                $skipped[] = ['user' => $user, 'why' => 'already on its pre-collapse role'];

                continue;
            }

            $moves[] = ['user' => $user, 'from' => $current, 'to' => $prior];
        }

        return ['moves' => $moves, 'skipped' => $skipped];
    }

    /**
     * The one role this API recognises for the account.
     *
     * Matches `UserPresenter::role()` deliberately: a recognised role wins over
     * anything else the account carries, and `administrator` is reported only
     * when there is no recognised role to report. A command that picked a
     * different role from the one the API displays would move accounts the
     * operator was not looking at.
     */
    private function recognisedRole(WP_User $user): string
    {
        $roles = array_values(array_map('strval', (array) $user->roles));

        foreach ($roles as $role) {
            if (UserRoles::isManaged($role)) {
                return $role;
            }
        }

        return $roles[0] ?? '';
    }

    /**
     * @param array{moves: list<array{user: WP_User, from: string, to: string}>, skipped: list<array{user: WP_User, why: string}>} $plan
     */
    private function render(array $plan, bool $rollback): void
    {
        WP_CLI::log(sprintf(
            '%s — %d account(s) to move, %d unchanged.',
            $rollback ? 'Rollback' : 'Collapse to Super Admin + Manager',
            count($plan['moves']),
            count($plan['skipped'])
        ));

        if ($plan['moves'] !== []) {
            WP_CLI::log('');

            $rows = [];

            foreach ($plan['moves'] as $move) {
                $rows[] = [
                    'id' => (int) $move['user']->ID,
                    'login' => (string) $move['user']->user_login,
                    'from' => $move['from'],
                    'to' => $move['to'],
                ];
            }

            WP_CLI\Utils\format_items('table', $rows, ['id', 'login', 'from', 'to']);
        }

        $tally = [];

        foreach ($plan['skipped'] as $entry) {
            $tally[$entry['why']] = ($tally[$entry['why']] ?? 0) + 1;
        }

        foreach ($tally as $why => $count) {
            WP_CLI::log(sprintf('  unchanged (%d): %s', $count, $why));
        }
    }

    /**
     * @param list<array{user: WP_User, from: string, to: string}> $moves
     */
    private function execute(array $moves, bool $rollback): void
    {
        $moved = 0;

        foreach ($moves as $move) {
            $id = (int) $move['user']->ID;

            if ($rollback) {
                $move['user']->set_role($move['to']);
                delete_user_meta($id, self::PRIOR_ROLE_META);
            } else {
                /*
                 * Recorded before the role changes, and never overwritten. A
                 * second run must not replace an account's original role with
                 * the tier the first run put it on, or the reversal record
                 * quietly becomes a record of the thing it was meant to undo.
                 */
                if (get_user_meta($id, self::PRIOR_ROLE_META, true) === '') {
                    update_user_meta($id, self::PRIOR_ROLE_META, $move['from']);
                }

                $move['user']->set_role($move['to']);
            }

            ++$moved;
        }

        $this->logger->info($rollback ? 'Staff roles rolled back' : 'Staff roles collapsed', [
            'accounts' => $moved,
            'assignable' => UserRoles::assignable(),
        ]);

        WP_CLI::success(sprintf(
            '%d account(s) %s.',
            $moved,
            $rollback ? 'restored to their pre-collapse role' : 'moved onto the two-tier model'
        ));
    }
}
