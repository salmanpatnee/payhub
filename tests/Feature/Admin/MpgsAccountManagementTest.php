<?php

namespace Tests\Feature\Admin;

use App\Models\MpgsAccount;
use App\Models\Payment;
use App\Models\User;
use App\Support\CurrencySupportResolver;
use App\Support\ProviderAccountTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MpgsAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
    }

    private function adminUser(): User
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->syncRoles(['admin']);

        return $admin;
    }

    public function test_admin_can_view_mpgs_accounts(): void
    {
        MpgsAccount::factory()->create();

        $this->actingAs($this->adminUser())
            ->get(route('admin.mpgs-accounts.index'))
            ->assertOk();
    }

    public function test_index_exposes_flags_never_secrets(): void
    {
        $account = MpgsAccount::factory()->create();
        $account->webhook_secret = 'notify-secret-value';
        $account->save();

        $this->actingAs($this->adminUser())
            ->get(route('admin.mpgs-accounts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('mpgsAccounts.0.has_webhook_secret', true)
                ->missing('mpgsAccounts.0.integration_password')
                ->missing('mpgsAccounts.0.webhook_secret')
            );
    }

    public function test_admin_can_set_and_clear_the_supports_pkr_flag(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->post(route('admin.mpgs-accounts.store'), [
                'account_name' => 'PKR MPGS Account',
                'merchant_id' => 'PKRMERCHANT',
                'integration_password' => 'plain-password-value',
                'api_version' => 100,
                'supports_pkr' => true,
            ])
            ->assertSessionHasNoErrors();

        $account = MpgsAccount::where('merchant_id', 'PKRMERCHANT')->firstOrFail();
        $this->assertTrue($account->supports_pkr);

        $this->actingAs($admin)
            ->put(route('admin.mpgs-accounts.update', $account), [
                'account_name' => 'PKR MPGS Account',
                'merchant_id' => 'PKRMERCHANT',
                'api_version' => 100,
                'supports_pkr' => false,
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($account->fresh()->supports_pkr);
    }

    public function test_supports_pkr_defaults_to_false(): void
    {
        $this->actingAs($this->adminUser())
            ->post(route('admin.mpgs-accounts.store'), [
                'account_name' => 'No PKR Account',
                'merchant_id' => 'NOPKR',
                'integration_password' => 'plain-password-value',
                'api_version' => 100,
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse(MpgsAccount::where('merchant_id', 'NOPKR')->firstOrFail()->supports_pkr);
    }

    public function test_admin_can_create_mpgs_account_with_encrypted_secrets(): void
    {
        $this->actingAs($this->adminUser())
            ->post(route('admin.mpgs-accounts.store'), [
                'account_name' => 'Test MPGS Account',
                'prefix' => 'MPGS',
                'merchant_id' => 'TESTMERCHANT',
                'integration_password' => 'plain-password-value',
                'webhook_secret' => 'plain-notify-secret',
                'api_version' => 66,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.mpgs-accounts.index'));

        $this->assertDatabaseHas('mpgs_accounts', [
            'account_name' => 'Test MPGS Account',
            'merchant_id' => 'TESTMERCHANT',
            'api_version' => 66,
            'is_active' => true,
        ]);

        $account = MpgsAccount::firstOrFail();
        expect($account->integration_password)->toBe('plain-password-value');
        expect($account->webhook_secret)->toBe('plain-notify-secret');

        // Stored encrypted, never as plain text.
        $raw = \DB::table('mpgs_accounts')->first();
        expect($raw->integration_password)->not->toBe('plain-password-value');
        expect($raw->webhook_secret)->not->toBe('plain-notify-secret');
    }

    public function test_webhook_secret_is_optional_on_create(): void
    {
        $this->actingAs($this->adminUser())
            ->post(route('admin.mpgs-accounts.store'), [
                'account_name' => 'No Secret',
                'merchant_id' => 'TESTMERCHANT',
                'integration_password' => 'plain-password-value',
                'api_version' => 66,
            ])
            ->assertSessionHasNoErrors();

        expect(MpgsAccount::firstOrFail()->webhook_secret)->toBeNull();
    }

    public function test_secrets_are_not_mass_assignable(): void
    {
        $account = new MpgsAccount([
            'account_name' => 'X',
            'integration_password' => 'sneaky',
            'webhook_secret' => 'sneaky',
        ]);

        expect($account->integration_password)->toBeNull();
        expect($account->webhook_secret)->toBeNull();
    }

    public function test_create_requires_integration_password_and_api_version(): void
    {
        $this->actingAs($this->adminUser())
            ->post(route('admin.mpgs-accounts.store'), [
                'account_name' => 'Missing Fields',
                'merchant_id' => 'TESTMERCHANT',
            ])
            ->assertSessionHasErrors(['integration_password', 'api_version']);
    }

    public function test_blank_secrets_on_update_preserve_existing(): void
    {
        $account = MpgsAccount::factory()->create();
        $account->integration_password = 'original-password';
        $account->webhook_secret = 'original-secret';
        $account->save();

        $this->actingAs($this->adminUser())
            ->put(route('admin.mpgs-accounts.update', $account), [
                'account_name' => 'Renamed',
                'merchant_id' => $account->merchant_id,
                'api_version' => $account->api_version,
                'integration_password' => '',
                'webhook_secret' => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.mpgs-accounts.index'));

        $account->refresh();
        expect($account->account_name)->toBe('Renamed');
        expect($account->integration_password)->toBe('original-password');
        expect($account->webhook_secret)->toBe('original-secret');
    }

    public function test_admin_can_replace_secrets_on_update(): void
    {
        $account = MpgsAccount::factory()->create();

        $this->actingAs($this->adminUser())
            ->put(route('admin.mpgs-accounts.update', $account), [
                'account_name' => $account->account_name,
                'merchant_id' => 'NEWMERCHANT',
                'api_version' => 70,
                'integration_password' => 'new-password',
                'webhook_secret' => 'new-secret',
            ])
            ->assertSessionHasNoErrors();

        $account->refresh();
        expect($account->merchant_id)->toBe('NEWMERCHANT');
        expect($account->api_version)->toBe(70);
        expect($account->integration_password)->toBe('new-password');
        expect($account->webhook_secret)->toBe('new-secret');
    }

    public function test_edit_returns_has_flags_never_raw_secrets(): void
    {
        $account = MpgsAccount::factory()->create();
        $account->webhook_secret = 'notify-secret-value';
        $account->save();

        $this->actingAs($this->adminUser())
            ->get(route('admin.mpgs-accounts.edit', $account))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('mpgsAccount.has_integration_password', true)
                ->where('mpgsAccount.has_webhook_secret', true)
                ->missing('mpgsAccount.integration_password')
                ->missing('mpgsAccount.webhook_secret')
                ->where('webhookUrl', url("/webhook/mpgs/{$account->id}"))
            );
    }

    public function test_admin_can_deactivate_and_activate_mpgs_account(): void
    {
        $account = MpgsAccount::factory()->create(['is_active' => true]);

        $this->actingAs($this->adminUser())
            ->patch(route('admin.mpgs-accounts.deactivate', $account))
            ->assertRedirect(route('admin.mpgs-accounts.index'));
        $this->assertDatabaseHas('mpgs_accounts', ['id' => $account->id, 'is_active' => false]);

        $this->actingAs($this->adminUser())
            ->patch(route('admin.mpgs-accounts.activate', $account))
            ->assertRedirect(route('admin.mpgs-accounts.index'));
        $this->assertDatabaseHas('mpgs_accounts', ['id' => $account->id, 'is_active' => true]);
    }

    public function test_admin_can_delete_mpgs_account_with_no_payments(): void
    {
        $account = MpgsAccount::factory()->create();

        $this->actingAs($this->adminUser())
            ->delete(route('admin.mpgs-accounts.destroy', $account))
            ->assertRedirect(route('admin.mpgs-accounts.index'));

        $this->assertDatabaseMissing('mpgs_accounts', ['id' => $account->id]);
    }

    public function test_account_with_payments_cannot_be_deleted(): void
    {
        $payment = Payment::factory()->mpgs()->create();

        $this->actingAs($this->adminUser())
            ->delete(route('admin.mpgs-accounts.destroy', $payment->mpgs_account_id))
            ->assertRedirect(route('admin.mpgs-accounts.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('mpgs_accounts', ['id' => $payment->mpgs_account_id]);
    }

    public function test_test_connection_routes_respond_for_admin(): void
    {
        $account = MpgsAccount::factory()->create();

        $this->actingAs($this->adminUser())
            ->post(route('admin.mpgs-accounts.test-stored-connection', $account))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->adminUser())
            ->post(route('admin.mpgs-accounts.test-connection'), [
                'merchant_id' => 'TESTMERCHANT',
                'integration_password' => 'pw',
                'api_version' => 66,
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_non_admin_cannot_access_mpgs_accounts(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->syncRoles(['agent']);

        $this->actingAs($user)->get(route('admin.mpgs-accounts.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.mpgs-accounts.store'), [])->assertForbidden();
    }

    public function test_payment_factory_mpgs_state_and_provider_helpers(): void
    {
        $account = MpgsAccount::factory()->create(['account_name' => 'Acme MPGS', 'prefix' => 'ACME']);
        $payment = Payment::factory()->mpgs()->create(['mpgs_account_id' => $account->id]);

        expect($payment->provider->value)->toBe('mpgs');
        expect($payment->providerAccountName())->toBe('Acme MPGS');
        expect($payment->formattedReferenceCode())->toStartWith('ACME-');
        expect(ProviderAccountTable::for('mpgs'))->toBe('mpgs_accounts');
        expect(CurrencySupportResolver::supports('mpgs', null, 'gbp'))->toBeTrue();
        expect(CurrencySupportResolver::supports('mpgs', null, 'usd'))->toBeTrue();
    }
}
