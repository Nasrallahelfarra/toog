<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\CatalogBrowser;
use App\Services\Team\ProductAssignments;
use App\Services\Team\TeamReport;
use App\Support\Activity;
use App\Support\CurrentStore;
use App\Support\RevenueVisibility;
use App\Support\StoreAccess;
use App\Support\StoreSections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The store's team: staff accounts and the dashboard pages each one may
 * open ("team"), and how the products they hold are selling ("team_report").
 * The owner holds both; a member given "team" manages the others on the
 * owner's behalf, but never their own account, and hands out no page they
 * do not hold themselves. The team-wide two-factor rule stays the owner's.
 */
class TeamController extends Controller
{
    /**
     * A staff password the owner sets: eight characters or more, and
     * digits alone are fine. Deliberately lighter than the platform's
     * rule for owners and admins, at the store owner's request.
     */
    private const PASSWORD = ['required', 'string', 'min:8', 'max:120'];

    public function __construct(private readonly CurrentStore $current) {}

    /**
     * The staff: who they are, what they may open, and their accounts.
     */
    public function index(Request $request, TeamReport $report): Response
    {
        $store = $this->current->require();

        return Inertia::render('store/team/index', [
            'members' => $report->roster($store),
            'sections' => $this->grantableGroups(),
            'me' => $request->user()->id,
            'requireTwoFactor' => $store->requiresStaffTwoFactor(),
            'canSetPolicy' => $this->access()->isOwner(),
            'canReport' => $this->access()->can('team_report'),
        ]);
    }

    /**
     * How each member's products sell, compared side by side.
     */
    public function report(Request $request, TeamReport $report, CatalogBrowser $catalog): Response
    {
        $store = $this->current->require();
        [$months, $month, $available] = self::period($request, $store, $catalog);

        return Inertia::render('store/team/report', [
            'months' => $months,
            'month' => $month,
            'availableMonths' => $available,
            'sections' => $this->grantableGroups(),
            'me' => $request->user()->id,
            'canManage' => $this->access()->can('team'),
            'report' => fn () => RevenueVisibility::apply($store, $report->comparison($store, $months, $month)),
        ]);
    }

    /**
     * The comparison as an A4 page the browser saves to PDF (which keeps
     * Arabic shaping right). It carries what the owner was looking at: the
     * same period, and only the members their filters left on the page.
     */
    public function export(Request $request, TeamReport $report, CatalogBrowser $catalog): Response
    {
        $store = $this->current->require();
        [$months, $month] = self::period($request, $store, $catalog);
        $data = $report->comparison($store, $months, $month);
        $total = count($data['members']);

        if ($request->filled('members')) {
            $keep = array_map('intval', explode(',', $request->string('members')->toString()));
            $data['members'] = array_values(array_filter($data['members'], fn (array $row) => in_array($row['id'], $keep, true)));
        }

        Activity::log('team.report_exported', $store, ['format' => 'pdf', 'months' => $months, 'month' => $month, 'members' => count($data['members'])]);

        return Inertia::render('store/team/print', [
            'report' => RevenueVisibility::apply($store, $data),
            'teamSize' => $total,
            'month' => $month,
            'sort' => in_array($request->query('sort'), ['units', 'products', 'avg_per_product', 'avg_per_month', 'orders', 'revenue'], true) ? $request->query('sort') : 'units',
            'store' => ['name' => $store->name, 'domain' => $store->domain],
            'generatedAt' => now()->toIso8601String(),
            'generatedBy' => $request->user()->name,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $this->current->require();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // A new account only: an owner never gets to set the password
            // of an account that already exists.
            'email' => ['required', 'email', 'max:190', Rule::unique(User::class)],
            'password' => self::PASSWORD,
            ...$this->permissionRules(),
        ]);
        $pages = StoreSections::clean($data['permissions'] ?? []);

        $member = DB::transaction(function () use ($data, $store, $pages) {
            $member = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'locale' => app()->getLocale(),
            ]);
            // The owner vouches for the address; there is no inbox step.
            $member->forceFill(['email_verified_at' => now()])->save();

            $store->members()->attach($member->id, [
                'role' => Store::ROLE_STAFF,
                'permissions' => json_encode($pages),
            ]);

            return $member;
        });

        Activity::log('team.member_added', $member, ['name' => $member->name, 'email' => $member->email]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.member_created')]);

        return back();
    }

    /**
     * The member's name and sign-in email. The pages they may open are
     * changed on their own, in permissions().
     */
    public function update(Request $request, int $member): RedirectResponse
    {
        $user = $this->managed($this->other($this->current->require(), $member));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique(User::class)->ignore($user->id)],
        ]);

        $user->update($data);

        Activity::log('team.member_updated', $user, ['name' => $user->name]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.member_updated', ['name' => $user->name])]);

        return back();
    }

    /**
     * Change the dashboard pages a member may open, for any member of the
     * team at any time. Access is read on every request, so it applies on
     * their next click even while they are signed in. The Zid connection
     * and the team itself are never on the list (see StoreSections).
     */
    public function permissions(Request $request, int $member): RedirectResponse
    {
        $store = $this->current->require();
        $user = $this->other($store, $member);

        $data = $request->validate($this->permissionRules());

        // Only the pages this person may hand out change. The rest stay as
        // they were: pages switched off for now, and, for a member managing
        // the team, pages they do not hold themselves.
        $editable = $this->grantable();
        $before = StoreSections::stored($user->pivot->permissions);
        $after = StoreSections::clean([...array_diff($before, $editable), ...$data['permissions']]);

        if ($after !== $before) {
            $store->members()->updateExistingPivot($user->id, ['permissions' => json_encode($after)]);

            Activity::log('team.member_permissions_changed', $user, [
                'name' => $user->name,
                'pages' => count($after),
                'added' => array_values(array_diff($after, $before)),
                'removed' => array_values(array_diff($before, $after)),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.permissions_saved', ['name' => $user->name])]);

        return back();
    }

    public function password(Request $request, int $member): RedirectResponse
    {
        $user = $this->managed($this->other($this->current->require(), $member));

        $data = $request->validate([
            'password' => [...self::PASSWORD, 'confirmed'],
        ]);

        $user->forceFill(['password' => $data['password']])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        Activity::log('team.member_password_reset', $user, ['name' => $user->name]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.password_reset')]);

        return back();
    }

    /**
     * For a member who lost the phone with their authenticator app: the
     * old codes stop working, they are signed out everywhere, and they set
     * it up again from their security page after signing in by password.
     */
    public function resetTwoFactor(int $member): RedirectResponse
    {
        $user = $this->managed($this->other($this->current->require(), $member));

        if (! $user->two_factor_secret) {
            throw ValidationException::withMessages(['member' => __('mojo.two_factor_not_enabled')]);
        }

        $user->clearTwoFactor();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        Activity::log('team.member_two_factor_reset', $user, ['name' => $user->name]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.two_factor_reset', ['name' => $user->name])]);

        return back();
    }

    /**
     * Turn the team-wide two-factor requirement on or off. Staff without it
     * are held at their security page on their next request.
     */
    public function security(Request $request): RedirectResponse
    {
        $store = $this->current->require();
        $data = $request->validate(['require_two_factor' => ['required', 'boolean']]);
        $required = (bool) $data['require_two_factor'];

        $store->update(['settings' => [...($store->settings ?? []), 'require_staff_two_factor' => $required]]);

        Activity::log('team.two_factor_policy', $store, ['required' => $required]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __($required ? 'mojo.two_factor_policy_on' : 'mojo.two_factor_policy_off')]);

        return back();
    }

    public function disable(int $member): RedirectResponse
    {
        $user = $this->managed($this->other($this->current->require(), $member));

        $user->forceFill(['status' => User::STATUS_DISABLED, 'disabled_at' => now(), 'disabled_reason' => null])->save();
        // Signed out of every device right away; their products stay theirs.
        DB::table('sessions')->where('user_id', $user->id)->delete();

        Activity::log('team.member_disabled', $user, ['name' => $user->name]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.user_disabled')]);

        return back();
    }

    public function enable(int $member): RedirectResponse
    {
        $user = $this->managed($this->other($this->current->require(), $member));

        $user->forceFill(['status' => User::STATUS_ACTIVE, 'disabled_at' => null, 'disabled_reason' => null])->save();

        Activity::log('team.member_enabled', $user, ['name' => $user->name]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.user_enabled')]);

        return back();
    }

    /**
     * Remove a member from the team. Their products go to another member
     * or back to nobody, as the owner chose.
     */
    public function destroy(Request $request, int $member, ProductAssignments $assignments): RedirectResponse
    {
        $store = $this->current->require();
        $user = $this->other($store, $member);

        $data = $request->validate([
            'move_to' => ['nullable', 'integer', Rule::notIn([$user->id])],
        ]);

        $heir = empty($data['move_to']) ? null : $this->member($store, (int) $data['move_to']);
        $name = $user->name;

        DB::transaction(function () use ($store, $user, $heir, $assignments) {
            $assignments->handOver($store, $user, $heir);
            $store->members()->detach($user->id);

            // The account existed for this store only, so it goes with it
            // and the email is free again.
            if ($this->ownsAccount($user, afterLeaving: true)) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->delete();
            }
        });

        Activity::log('team.member_removed', null, ['name' => $name, 'moved_to' => $heir?->name]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.member_removed', ['name' => $name])]);

        // Removed from the report: stay on it. Anywhere else (the staff
        // page, or the member's own page, now gone): the staff page.
        return str_contains(url()->previous(), '/store/team/report')
            ? back()
            : to_route('store.team.index');
    }

    /**
     * The 3/6/12-month range or one chosen month, as the catalog reads it.
     *
     * @return array{0: int, 1: string|null, 2: list<string>}
     */
    public static function period(Request $request, Store $store, CatalogBrowser $catalog): array
    {
        $months = in_array($request->integer('months'), CatalogBrowser::RANGES, true) ? $request->integer('months') : 12;
        $available = $catalog->availableMonths($store);
        $month = in_array($request->string('month')->toString(), $available, true) ? $request->string('month')->toString() : null;

        return [$months, $month, $available];
    }

    /**
     * A staff member of this store; never the owner, never another store's.
     */
    public static function member(Store $store, int $id): User
    {
        return $store->staff()->whereKey($id)->firstOrFail();
    }

    /**
     * A member other than the one acting: nobody changes their own access,
     * password or account from the team page.
     */
    private function other(Store $store, int $id): User
    {
        abort_if($id === (int) auth()->id(), 403, __('mojo.team_not_self'));

        return self::member($store, $id);
    }

    private function access(): StoreAccess
    {
        return app(StoreAccess::class);
    }

    /**
     * The pages this person may hand out: every page in use for the owner,
     * and for a member managing the team, only the ones they hold.
     *
     * @return list<string>
     */
    private function grantable(): array
    {
        return $this->access()->sections();
    }

    /**
     * @return array<string, list<string>>
     */
    private function grantableGroups(): array
    {
        $mine = $this->grantable();

        return array_filter(array_map(fn (array $keys) => array_values(array_intersect($keys, $mine)), StoreSections::enabledGroups()));
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function permissionRules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in($this->grantable())],
        ];
    }

    /**
     * The owner may change the sign-in details of an account only when it
     * belongs to this store alone: staff here, and nothing anywhere else.
     */
    private function ownsAccount(User $user, bool $afterLeaving = false): bool
    {
        return ! $user->isAdmin()
            && $user->ownedStores()->doesntExist()
            && $user->stores()->count() === ($afterLeaving ? 0 : 1);
    }

    private function managed(User $user): User
    {
        if (! $this->ownsAccount($user)) {
            throw ValidationException::withMessages(['member' => __('mojo.member_not_managed')]);
        }

        return $user;
    }
}
