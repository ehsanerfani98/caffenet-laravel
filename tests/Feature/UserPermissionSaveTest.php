<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 40 reproduction: the admin edits a user (toggles permission switches
 * in the «ویرایش کاربر» sheet) and presses «ذخیره کاربر». The PATCH body is
 * EXACTLY what public/js/screens/admin.js::openUserForm sends:
 *   { name, role, isBlocked, walletChange: 0, permissions: effective[] }
 * After the save, GET /api/users must reflect the toggled set.
 */
class UserPermissionSaveTest extends TestCase
{
    use RefreshDatabase;

    private ?User $admin = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function admin(): User
    {
        if ($this->admin !== null) {
            return $this->admin;
        }

        $admin = User::create([
            'phone' => '09120000001',
            'name' => 'مدیر',
            'role' => 'admin',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
        $admin->assignRole('admin');
        $this->admin = $admin;

        return $admin;
    }

    private function operator(string $phone = '09120000003'): User
    {
        $op = User::create([
            'phone' => $phone,
            'name' => 'اپراتور تست',
            'role' => 'operator',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
        // Realistic creation path (AuthService/POST /api/users): syncAccess
        // attaches the operator spatie role whose synced set is the expanded
        // role defaults from RoleSeeder.
        $op->syncAccess('operator', null);

        return $op;
    }

    /** The effective set exactly as the sheet shows it (PERM.resolve on the
     * GET /api/users payload). */
    private function effectiveOf(User $u): array
    {
        $res = $this->actingAs($this->admin())->getJson('/api/users')->assertOk();
        $row = collect($res->json())->firstWhere('id', $u->id);
        $this->assertNotNull($row, 'edited user must be in the roster');

        return $row['permissions'];
    }

    public function test_toggling_one_switch_on_persists(): void
    {
        $admin = $this->admin();
        $op = $this->operator();

        $effective = $this->effectiveOf($op);
        $this->assertContains('orders.manage', $effective); // operator default
        $this->assertNotContains('services.view', $effective);

        // The admin turns «خدمات → مشاهده» ON and presses «ذخیره کاربر».
        $next = array_values(array_unique(array_merge($effective, ['services.view'])));
        $this->actingAs($admin)->patchJson('/api/users/'.$op->id, [
            'name' => 'اپراتور تست',
            'role' => 'operator',
            'isBlocked' => false,
            'walletChange' => 0,
            'permissions' => $next,
        ])->assertOk();

        $after = $this->effectiveOf($op);
        $this->assertContains('services.view', $after, 'toggled-ON permission must persist');
        $this->assertContains('orders.manage', $after);
    }

    public function test_toggling_one_switch_off_persists(): void
    {
        $admin = $this->admin();
        $op = $this->operator();

        $effective = $this->effectiveOf($op);
        $this->assertContains('wallet.users', $effective);

        // The admin turns «کیف پول → کاربران» OFF and saves.
        $next = array_values(array_diff($effective, ['wallet.users']));
        $this->assertNotSame($effective, $next);

        $this->actingAs($admin)->patchJson('/api/users/'.$op->id, [
            'name' => 'اپراتور تست',
            'role' => 'operator',
            'isBlocked' => false,
            'walletChange' => 0,
            'permissions' => $next,
        ])->assertOk();

        $after = $this->effectiveOf($op);
        $this->assertNotContains('wallet.users', $after, 'toggled-OFF permission must stay off');
        $this->assertContains('orders.manage', $after);
    }

    public function test_second_edit_round_trip_keeps_previous_override(): void
    {
        $admin = $this->admin();
        $op = $this->operator();

        // First edit: grant services.view.
        $first = array_values(array_unique(array_merge(
            $this->effectiveOf($op), ['services.view']
        )));
        $this->actingAs($admin)->patchJson('/api/users/'.$op->id, [
            'name' => 'اپراتور تست', 'role' => 'operator',
            'isBlocked' => false, 'walletChange' => 0, 'permissions' => $first,
        ])->assertOk();

        // Second edit: the sheet re-derives from the (now expanded) roster row
        // and the admin turns coupons.view ON as well.
        $second = array_values(array_unique(array_merge(
            $this->effectiveOf($op), ['coupons.view']
        )));
        $this->actingAs($admin)->patchJson('/api/users/'.$op->id, [
            'name' => 'اپراتور تست', 'role' => 'operator',
            'isBlocked' => false, 'walletChange' => 0, 'permissions' => $second,
        ])->assertOk();

        $after = $this->effectiveOf($op);
        $this->assertContains('services.view', $after, 'first edit must survive the second save');
        $this->assertContains('coupons.view', $after);
    }

    public function test_wallet_change_permission_save_combination_works(): void
    {
        $admin = $this->admin();
        $op = $this->operator();

        $effective = $this->effectiveOf($op);
        $next = array_values(array_unique(array_merge($effective, ['dashboard.view'])));

        // A wallet adjustment rides along in the same save (the sheet always
        // sends walletChange — 0 when untouched).
        $this->actingAs($admin)->patchJson('/api/users/'.$op->id, [
            'name' => 'اپراتور تغییر یافته',
            'role' => 'operator',
            'isBlocked' => false,
            'walletChange' => 5000,
            'permissions' => $next,
        ])->assertOk();

        $after = $this->effectiveOf($op);
        $this->assertContains('dashboard.view', $after);

        $row = collect($this->actingAs($admin)->getJson('/api/users')->json())
            ->firstWhere('id', $op->id);
        $this->assertSame(5000, $row['walletBalance']);
        $this->assertSame('اپراتور تغییر یافته', $row['name']);
    }

    /** Documented backend semantics: an admin target always keeps the FULL
     * catalog (syncAccess forces null override) — the matrix edits the sheet
     * offers for an admin are silently discarded. The UI fix makes that
     * explicit (read-only matrix); this test locks the server behaviour. */
    public function test_admin_target_always_keeps_full_catalog(): void
    {
        $admin = $this->admin();
        $other = User::create([
            'phone' => '09120000009',
            'name' => 'مدیر دوم',
            'role' => 'admin',
            'wallet_balance' => 0,
            'created_at' => now()->toIso8601String(),
        ]);
        $other->syncAccess('admin', null);

        $effective = $this->effectiveOf($other);
        $this->assertNotEmpty($effective);

        // Pretend every switch was unchecked — the server must still keep
        // the admin on the full catalog.
        $this->actingAs($admin)->patchJson('/api/users/'.$other->id, [
            'name' => 'مدیر دوم',
            'role' => 'admin',
            'isBlocked' => false,
            'walletChange' => 0,
            'permissions' => [],
        ])->assertOk();

        $after = $this->effectiveOf($other);
        $this->assertContains('users.manage', $after);
        $this->assertContains('orders.manage', $after);
    }

    /** Front mirror gap (fixed alongside): the server catalog carries
     * sms.delete but the client matrix mirror did not — a saved set holding
     * sms.delete must survive a later edit round-trip. */
    public function test_sms_delete_permission_survives_round_trip(): void
    {
        $admin = $this->admin();
        $op = $this->operator();

        $first = array_values(array_unique(array_merge(
            $this->effectiveOf($op), ['sms.delete']
        )));
        $this->actingAs($admin)->patchJson('/api/users/'.$op->id, [
            'name' => 'اپراتور تست', 'role' => 'operator',
            'isBlocked' => false, 'walletChange' => 0, 'permissions' => $first,
        ])->assertOk();

        $this->assertContains('sms.delete', $this->effectiveOf($op));

        // A second edit that does NOT touch sms (like the sheet re-deriving
        // from the roster) must not silently strip sms.delete.
        $second = $this->effectiveOf($op);
        $this->actingAs($admin)->patchJson('/api/users/'.$op->id, [
            'name' => 'اپراتور تست', 'role' => 'operator',
            'isBlocked' => false, 'walletChange' => 0, 'permissions' => $second,
        ])->assertOk();

        $this->assertContains('sms.delete', $this->effectiveOf($op));
    }
}
