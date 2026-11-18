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
use App\Support\StoreSections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The store's team, managed by its owner: staff accounts, the dashboard
 * pages each one may open, and how the products they hold are selling.
 * Every route here is owner-only (see StoreSections).
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

    public function index(Request $request, TeamReport $report, CatalogBrowser $catalog): Response
    {
        $store = $this->current->require();
        [$months, $month, $available] = self::period($request, $store, $catalog);

        return Inertia::render('store/team/index', [
            'months' => $months,
            'month' => $month,
            'availableMonths' => $available,
            'sections' => StoreSections::GROUPS,
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

        $member = DB::transaction(function () use ($data, $store) {
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
                'permissions' => json_encode(StoreSections::clean($data['permissions'] ?? [])),
            ]);

            return $member;
        });

        Activity::log('team.member_added', $member, ['name' => $member->name, 'email' => $member->email]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.member_created')]);

        return back();
    }

    public function update(Request $request, int $member): RedirectResponse
    {
        $store = $this->current->require();
        $user = $this->member($store, $member);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique(User::class)->ignore($user->id)],
            ...$this->permissionRules(),
        ]);

        $permissions = StoreSections::clean($data['permissions'] ?? []);

        DB::transaction(function () use ($store, $user, $data, $permissions) {
            if ($this->ownsAccount($user)) {
                $user->update(['name' => $data['name'], 'email' => $data['email']]);
            }

            $store->members()->updateExistingPivot($user->id, ['permissions' => json_encode($permissions)]);
        });

        Activity::log('team.member_updated', $user, ['name' => $user->name, 'pages' => count($permissions)]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.permissions_saved')]);

        return back();
    }

    public function password(Request $request, int $member): RedirectResponse
    {
        $user = $this->managed($this->member($this->current->require(), $member));

        $data = $request->validate([
            'password' => [...self::PASSWORD, 'confirmed'],
        ]);

        $user->forceFill(['password' => $data['password']])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();

        Activity::log('team.member_password_reset', $user, ['name' => $user->name]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.password_reset')]);

        return back();
    }

    public function disable(int $member): RedirectResponse
    {
        $user = $this->managed($this->member($this->current->require(), $member));

        $user->forceFill(['status' => User::STATUS_DISABLED, 'disabled_at' => now(), 'disabled_reason' => null])->save();
        // Signed out of every device right away; their products stay theirs.
        DB::table('sessions')->where('user_id', $user->id)->delete();

        Activity::log('team.member_disabled', $user, ['name' => $user->name]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.user_disabled')]);

        return back();
    }

    public function enable(int $member): RedirectResponse
    {
        $user = $this->managed($this->member($this->current->require(), $member));

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
        $user = $this->member($store, $member);

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

        return to_route('store.team.index');
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
     * @return array<string, list<mixed>>
     */
    private function permissionRules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(StoreSections::all())],
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
