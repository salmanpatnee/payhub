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
        $this->markTestIncomplete('Wave 0 stub — implement after Wave 1 backend.');
    }

    // BRAND-01: Admin can create a brand with name, logo, primary/secondary colors
    public function test_admin_can_create_brand_without_logo(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implement after Wave 1 backend.');
    }

    // BRAND-01: Admin can create a brand with logo upload
    public function test_admin_can_create_brand_with_logo(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implement after Wave 1 backend.');
    }

    // BRAND-01: Slug is auto-generated from name (not user-inputted)
    public function test_brand_slug_is_auto_generated_from_name(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implement after Wave 1 backend.');
    }

    // BRAND-02: Admin can edit brand details
    public function test_admin_can_update_brand(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implement after Wave 1 backend.');
    }

    // BRAND-02: On edit with new logo, old logo file is deleted from storage
    public function test_old_logo_deleted_when_new_logo_uploaded(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implement after Wave 1 backend.');
    }

    // BRAND-02: On edit without new logo, existing logo_path is preserved
    public function test_logo_preserved_when_no_new_logo_submitted(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implement after Wave 1 backend.');
    }

    // Access control: non-admin cannot access brand routes
    public function test_non_admin_cannot_access_brand_list(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implement after Wave 1 backend.');
    }

    // Validation: primary_color must be a valid 6-char hex string
    public function test_primary_color_must_be_valid_hex(): void
    {
        $this->markTestIncomplete('Wave 0 stub — implement after Wave 1 backend.');
    }
}
