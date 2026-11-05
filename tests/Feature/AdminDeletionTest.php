<?php

namespace Tests\Feature;

use App\Actions\Stores\CreateStore;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function storeFor(User $owner, string $name = 'Lama'): Store
    {
        $store = app(CreateStore::class)->handle($owner, ['name' => $name]);
        $store->products()->create(['name' => 'Abaya', 'external_id' => uniqid()]);

        return $store;
    }

    public function test_admin_deletes_a_store_with_its_data_and_files_after_typing_its_name(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $store = $this->storeFor(User::factory()->create());
        $kept = $this->storeFor(User::factory()->create(), 'Other');
        UploadedFile::fake()->image('logo.png')->storeAs("logos/{$store->id}", 'logo.png', 'public');
        UploadedFile::fake()->image('logo.png')->storeAs("logos/{$kept->id}", 'logo.png', 'public');

        $this->actingAs($admin)->delete("/admin/stores/{$store->id}", ['confirm' => 'Wrong'])
            ->assertSessionHasErrors('confirm');
        $this->assertModelExists($store);

        $this->delete("/admin/stores/{$store->id}", ['confirm' => ' Lama '])->assertRedirect('/admin/stores');

        $this->assertModelMissing($store);
        $this->assertSame(0, Product::query()->where('store_id', $store->id)->count());
        $this->assertSame(1, $kept->products()->count());
        Storage::disk('public')->assertMissing("logos/{$store->id}/logo.png");
        Storage::disk('public')->assertExists("logos/{$kept->id}/logo.png");
        $this->assertModelExists($store->owner);
        $this->assertTrue(ActivityLog::query()->where('action', 'admin.store_deleted')->exists());
    }

    public function test_deleting_an_owner_needs_explicit_confirmation_and_then_removes_their_stores(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $store = $this->storeFor($owner);

        $this->actingAs($admin)->delete("/admin/users/{$owner->id}")->assertSessionHasErrors('user');
        $this->assertModelExists($owner);

        $this->delete("/admin/users/{$owner->id}", ['delete_stores' => true])->assertRedirect();

        $this->assertModelMissing($owner);
        $this->assertModelMissing($store);
    }

    public function test_store_owners_cannot_delete_stores_and_admins_cannot_delete_themselves(): void
    {
        $owner = User::factory()->create();
        $store = $this->storeFor($owner);
        $this->actingAs($owner)->delete("/admin/stores/{$store->id}", ['confirm' => 'Lama'])->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->delete("/admin/users/{$admin->id}", ['delete_stores' => true])->assertSessionHasErrors('user');
        $this->assertModelExists($admin);
    }

    public function test_admin_edits_the_owner_email_and_password_from_the_store_page_data(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create(['email' => 'old@example.com']);
        $store = $this->storeFor($owner);

        $this->actingAs($admin)->get("/admin/stores/{$store->id}?tab=settings")
            ->assertInertia(fn ($page) => $page->where('store.owner.email', 'old@example.com')->where('store.owner.role', User::ROLE_STORE_OWNER));

        $this->put("/admin/users/{$owner->id}", ['name' => 'Lama', 'email' => 'new@example.com', 'phone' => '', 'role' => User::ROLE_STORE_OWNER])
            ->assertSessionHasNoErrors();
        $this->put("/admin/users/{$owner->id}/password", ['password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])
            ->assertSessionHasNoErrors();

        $owner->refresh();
        $this->assertSame('new@example.com', $owner->email);
        $this->assertTrue(password_verify('new-secret-123', $owner->password));
    }
}
