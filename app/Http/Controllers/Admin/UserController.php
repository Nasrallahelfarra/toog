<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\User;
use App\Support\Activity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'role', 'status']);

        $users = User::query()
            ->with('stores:stores.id,stores.name,stores.status')
            ->when($filters['search'] ?? null, fn (Builder $q, string $s) => $q->where(fn (Builder $q) => $q
                ->where('name', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%")))
            ->when($filters['role'] ?? null, fn (Builder $q, string $role) => $q->where('role', $role))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->orderByRaw("CASE WHEN role = 'admin' THEN 0 ELSE 1 END")
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'role' => $u->role,
                'status' => $u->status,
                'disabled_reason' => $u->disabled_reason,
                'disabled_at' => $u->disabled_at?->toIso8601String(),
                'last_login_at' => $u->last_login_at?->toIso8601String(),
                'created_at' => $u->created_at?->toIso8601String(),
                'stores' => $u->stores->map(fn (Store $s) => ['id' => $s->id, 'name' => $s->name, 'status' => $s->status])->values(),
            ]);

        return Inertia::render('admin/users/index', [
            'users' => $users,
            'filters' => $filters,
            'counts' => [
                'all' => User::query()->count(),
                'admins' => User::query()->where('role', User::ROLE_ADMIN)->count(),
                'owners' => User::query()->where('role', User::ROLE_STORE_OWNER)->count(),
                'disabled' => User::query()->where('status', User::STATUS_DISABLED)->count(),
            ],
            'stores' => Store::query()->orderBy('name')->get(['id', 'name']),
            'me' => $request->user()->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique(User::class)],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_STORE_OWNER])],
            'password' => ['required', 'string', 'min:8', 'max:120'],
            'store_id' => ['nullable', 'integer', Rule::exists(Store::class, 'id')],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role' => $data['role'],
                'password' => $data['password'],
                'locale' => 'ar',
                'email_verified_at' => now(),
            ]);

            if ($data['role'] === User::ROLE_STORE_OWNER && ! empty($data['store_id'])) {
                $user->stores()->attach($data['store_id'], ['role' => 'owner']);
            }

            return $user;
        });

        Activity::log('admin.user_created', $user, ['role' => $user->role, 'store_id' => $data['store_id'] ?? null]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.user_created')]);

        return back();
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique(User::class)->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_STORE_OWNER])],
        ]);

        if ($user->is($request->user()) && $data['role'] !== User::ROLE_ADMIN) {
            throw ValidationException::withMessages(['role' => __('mojo.cannot_change_own_role')]);
        }

        $before = $user->only(['name', 'email', 'phone', 'role']);
        $user->update($data);

        $changes = collect($data)
            ->filter(fn ($value, $key) => $before[$key] !== $value)
            ->map(fn ($value, $key) => ['from' => $before[$key], 'to' => $value])
            ->all();

        Activity::log('admin.user_updated', $user, ['changes' => $changes]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.saved')]);

        return back();
    }

    public function disable(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $this->guardSelf($request, $user, 'cannot_disable_self');

        $user->forceFill([
            'status' => User::STATUS_DISABLED,
            'disabled_at' => now(),
            'disabled_reason' => $data['reason'] ?? null,
        ])->save();

        // Sign the user out of every device right away.
        DB::table('sessions')->where('user_id', $user->id)->delete();

        Activity::log('admin.user_disabled', $user, ['reason' => $data['reason'] ?? null]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.user_disabled')]);

        return back();
    }

    public function enable(User $user): RedirectResponse
    {
        $user->forceFill([
            'status' => User::STATUS_ACTIVE,
            'disabled_at' => null,
            'disabled_reason' => null,
        ])->save();

        Activity::log('admin.user_enabled', $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.user_enabled')]);

        return back();
    }

    public function password(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'max:120', 'confirmed'],
        ]);

        $user->forceFill(['password' => $data['password']])->save();

        if (! $user->is($request->user())) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        Activity::log('admin.user_password_reset', $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.password_reset')]);

        return back();
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->guardSelf($request, $user, 'cannot_delete_self');

        // Deleting an owner would cascade-delete their stores; make the
        // admin reassign or remove those stores first.
        if ($user->ownedStores()->exists()) {
            throw ValidationException::withMessages(['user' => __('mojo.user_owns_stores')]);
        }

        Activity::log('admin.user_deleted', null, ['email' => $user->email, 'name' => $user->name]);
        $user->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('mojo.deleted')]);

        return back();
    }

    private function guardSelf(Request $request, User $user, string $message): void
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => __("mojo.{$message}")]);
        }
    }
}
