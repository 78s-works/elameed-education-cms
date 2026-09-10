<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `platform:admin` is the only way into /admin on a database seeded without
 * demo data: DatabaseSeeder returns after the catalogs when demo seeding is off
 * and `seedPlatformAdmin()` sits after that return, so a correct production
 * seed produces no platform admin at all.
 */
class PlatformAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_admin_and_prints_a_generated_password(): void
    {
        $this->artisan('platform:admin --phone=01000000000')
            ->expectsOutputToContain('Created platform admin.')
            ->expectsOutputToContain('Generated password')
            ->assertSuccessful();

        $user = User::query()->where('phone', '01000000000')->firstOrFail();

        $this->assertTrue($user->isPlatformAdmin());
        $this->assertNotNull($user->phone_verified_at);
        $this->assertSame('ar', $user->locale);
    }

    public function test_it_stores_the_password_hashed(): void
    {
        $this->artisan('platform:admin --phone=01000000001')->assertSuccessful();

        $user = User::query()->where('phone', '01000000001')->firstOrFail();

        // Hashed, not stored in the clear.
        $this->assertNotSame('', (string) $user->password);
        $this->assertTrue(Hash::needsRehash($user->password) === false);
    }

    public function test_it_uses_a_supplied_password(): void
    {
        $this->artisan('platform:admin --phone=01000000002 --password=correct-horse')
            ->assertSuccessful();

        $user = User::query()->where('phone', '01000000002')->firstOrFail();

        $this->assertTrue(Hash::check('correct-horse', $user->password));
    }

    public function test_it_promotes_an_existing_user_without_touching_the_password(): void
    {
        $user = User::factory()->create([
            'phone' => '01000000003',
            'password' => 'original-secret',
            'is_platform_admin' => false,
        ]);

        $this->artisan('platform:admin --phone=01000000003')
            ->expectsOutputToContain('Promoted existing user')
            ->assertSuccessful();

        $user->refresh();

        $this->assertTrue($user->isPlatformAdmin());
        $this->assertTrue(Hash::check('original-secret', $user->password));
    }

    public function test_it_replaces_an_existing_password_when_asked(): void
    {
        $user = User::factory()->create([
            'phone' => '01000000004',
            'password' => 'original-secret',
            'is_platform_admin' => false,
        ]);

        $this->artisan('platform:admin --phone=01000000004 --password=new-secret')
            ->expectsOutputToContain('its password will be replaced')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('new-secret', $user->refresh()->password));
    }

    public function test_running_it_twice_is_idempotent(): void
    {
        $this->artisan('platform:admin --phone=01000000005')->assertSuccessful();
        $this->artisan('platform:admin --phone=01000000005')->assertSuccessful();

        $this->assertSame(1, User::query()->where('phone', '01000000005')->count());
    }

    public function test_it_requires_an_identifier(): void
    {
        $this->artisan('platform:admin')
            ->expectsOutputToContain('Pass --phone or --email')
            ->assertFailed();

        $this->assertSame(0, User::query()->where('is_platform_admin', true)->count());
    }

    public function test_it_rejects_a_malformed_email(): void
    {
        $this->artisan('platform:admin --email=not-an-email')->assertFailed();

        $this->assertSame(0, User::query()->where('is_platform_admin', true)->count());
    }

    public function test_it_refuses_when_phone_and_email_are_two_different_users(): void
    {
        User::factory()->create(['phone' => '01000000006', 'email' => null]);
        User::factory()->create(['phone' => null, 'email' => 'someone@raqeem-tech.com']);

        $this->artisan('platform:admin --phone=01000000006 --email=someone@raqeem-tech.com')
            ->expectsOutputToContain('two different users')
            ->assertFailed();

        $this->assertSame(0, User::query()->where('is_platform_admin', true)->count());
    }
}
