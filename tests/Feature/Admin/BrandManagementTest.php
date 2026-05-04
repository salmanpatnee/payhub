<?php

namespace Tests\Feature\Admin;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BrandManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'user',  'guard_name' => 'web']);
    }

    private function adminUser(): User
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->syncRoles(['admin']);
        return $admin;
    }

    // BRAND-03: Admin can list all brands
    public function test_admin_can_view_brand_list(): void
    {
        Brand::factory()->create(['name' => 'Acme Corp']);

        $this->actingAs($this->adminUser())
            ->get(route('admin.brands.index'))
            ->assertOk();
    }

    // BRAND-01: Admin can create a brand with name, logo, primary/secondary colors
    public function test_admin_can_create_brand_without_logo(): void
    {
        $this->actingAs($this->adminUser())
            ->post(route('admin.brands.store'), [
                'name'            => 'Test Brand',
                'primary_color'   => '#ff0000',
                'secondary_color' => '#00ff00',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.brands.index'));

        $this->assertDatabaseHas('brands', ['name' => 'Test Brand', 'slug' => 'test-brand']);
    }

    // BRAND-01: Admin can create a brand with logo upload
    public function test_admin_can_create_brand_with_logo(): void
    {
        Storage::fake('public');

        $this->actingAs($this->adminUser())
            ->post(route('admin.brands.store'), [
                'name'            => 'Logo Brand',
                'primary_color'   => '#ff0000',
                'secondary_color' => '#0000ff',
                'logo'            => UploadedFile::fake()->image('logo.png', 100, 100)->size(50),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.brands.index'));

        $brand = Brand::where('name', 'Logo Brand')->first();
        $this->assertNotNull($brand->logo_path);
        Storage::disk('public')->assertExists($brand->logo_path);
    }

    // BRAND-01: Slug is auto-generated from name (not user-inputted)
    public function test_brand_slug_is_auto_generated_from_name(): void
    {
        $this->actingAs($this->adminUser())
            ->post(route('admin.brands.store'), [
                'name'            => 'Acme Corp',
                'primary_color'   => '#000000',
                'secondary_color' => '#ffffff',
            ]);

        $this->assertDatabaseHas('brands', ['name' => 'Acme Corp', 'slug' => 'acme-corp']);
    }

    // BRAND-02: Admin can edit brand details
    public function test_admin_can_update_brand(): void
    {
        $brand = Brand::factory()->create(['name' => 'Old Name', 'logo_path' => null]);

        $this->actingAs($this->adminUser())
            ->put(route('admin.brands.update', $brand), [
                'name'            => 'New Name',
                'primary_color'   => '#aabbcc',
                'secondary_color' => '#112233',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.brands.index'));

        $this->assertDatabaseHas('brands', ['id' => $brand->id, 'name' => 'New Name']);
    }

    // BRAND-02: On edit with new logo, old logo file is deleted from storage
    public function test_old_logo_deleted_when_new_logo_uploaded(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('brands/old-logo.png', 'content');

        $brand = Brand::factory()->create(['logo_path' => 'brands/old-logo.png']);

        $this->actingAs($this->adminUser())
            ->put(route('admin.brands.update', $brand), [
                'name'            => $brand->name,
                'primary_color'   => $brand->primary_color,
                'secondary_color' => $brand->secondary_color,
                'logo'            => UploadedFile::fake()->image('new-logo.png', 100, 100)->size(50),
            ])
            ->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing('brands/old-logo.png');
        $this->assertNotNull(Brand::find($brand->id)->logo_path);
    }

    // BRAND-02: On edit without new logo, existing logo_path is preserved
    public function test_logo_preserved_when_no_new_logo_submitted(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('brands/keep-logo.png', 'content');

        $brand = Brand::factory()->create(['logo_path' => 'brands/keep-logo.png']);

        $this->actingAs($this->adminUser())
            ->put(route('admin.brands.update', $brand), [
                'name'            => 'Updated Name',
                'primary_color'   => $brand->primary_color,
                'secondary_color' => $brand->secondary_color,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('brands', ['id' => $brand->id, 'logo_path' => 'brands/keep-logo.png']);
    }

    // Access control: non-admin cannot access brand routes
    public function test_non_admin_cannot_access_brand_list(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->syncRoles(['user']);

        $this->actingAs($user)
            ->get(route('admin.brands.index'))
            ->assertForbidden();
    }

    // Validation: primary_color must be a valid 6-char hex string
    public function test_primary_color_must_be_valid_hex(): void
    {
        $this->actingAs($this->adminUser())
            ->post(route('admin.brands.store'), [
                'name'            => 'Bad Color Brand',
                'primary_color'   => 'red',
                'secondary_color' => '#ffffff',
            ])
            ->assertSessionHasErrors(['primary_color']);
    }
}
