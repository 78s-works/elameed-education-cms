<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Identity\Support\UserLookup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Creates or promotes a platform admin (الإدارة الرئيسية) — the GLOBAL role
 * carried by `users.is_platform_admin`, not a tenant membership.
 *
 * Exists because a clean production seed cannot produce one. `DatabaseSeeder`
 * returns after the catalogs when demo seeding is off (EDU-BE-034), and
 * `seedPlatformAdmin()` sits after that return — so a production database that
 * correctly holds no demo data also holds no way into /admin. The other routes
 * in are raw SQL, or `tinker --execute`, which the Plesk for Windows PHP
 * wrapper mangles.
 *
 * Idempotent: run against an existing phone or email and the account is
 * promoted in place, its password untouched unless --password says otherwise.
 */
class CreatePlatformAdminCommand extends Command
{
    protected $signature = 'platform:admin
        {--phone= : Phone number — the primary identifier (FR-M11-01). Required unless --email is given.}
        {--email= : Email address. Optional; either identifier can sign in.}
        {--name= : Display name. Only used when creating.}
        {--password= : Password. Omit on create and one is generated and printed ONCE.}
        {--locale=ar : UI locale for the account.}';

    protected $description = 'Create a platform admin, or promote an existing user to one';

    public function handle(): int
    {
        $phone = $this->trimmed('phone');
        $email = $this->trimmed('email');

        if ($phone === null && $email === null) {
            $this->error('Pass --phone or --email (or both).');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['phone' => $phone, 'email' => $email],
            [
                'phone' => ['nullable', 'string', 'max:255'],
                'email' => ['nullable', 'email', 'max:255'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        // `phone` and `email` are separately unique, so the two options can name
        // two different people. Refuse rather than guess which one was meant.
        $byPhone = $phone !== null ? UserLookup::find($phone) : null;
        $byEmail = $email !== null ? UserLookup::find($email) : null;

        if ($byPhone !== null && $byEmail !== null && ! $byPhone->is($byEmail)) {
            $this->error('--phone and --email belong to two different users; pass only one.');

            return self::FAILURE;
        }

        $user = $byPhone ?? $byEmail;
        $creating = $user === null;
        $password = $this->trimmed('password');
        $generated = null;

        if ($creating) {
            if ($password === null) {
                // Never leave a privileged account on a guessable password, and
                // never require one to be typed into shell history.
                $generated = Str::password(20);
                $password = $generated;
            }

            $user = new User;
            $user->name = $this->trimmed('name') ?? 'إدارة منصة العميد';
        } elseif ($password !== null) {
            $this->warn('Existing user found — its password will be replaced.');
        }

        // `password` is cast to `hashed`, so the plain value is hashed on save.
        $attributes = [
            'is_platform_admin' => true,
            'locale' => (string) $this->option('locale'),
        ];

        if ($phone !== null) {
            $attributes['phone'] = $phone;
            $attributes['phone_verified_at'] = $user->phone_verified_at ?? now();
        }

        if ($email !== null) {
            $attributes['email'] = $email;
            $attributes['email_verified_at'] = $user->email_verified_at ?? now();
        }

        if ($password !== null) {
            $attributes['password'] = $password;
        }

        $user->forceFill($attributes)->save();

        $this->info($creating ? 'Created platform admin.' : 'Promoted existing user to platform admin.');
        $this->table(
            ['name', 'phone', 'email', 'locale', 'platform admin'],
            [[$user->name, $user->phone ?? '—', $user->email ?? '—', $user->locale, $user->isPlatformAdmin() ? 'yes' : 'no']]
        );

        if ($generated !== null) {
            $this->newLine();
            $this->warn('Generated password (shown once — store it now):');
            $this->line($generated);
        }

        return self::SUCCESS;
    }

    private function trimmed(string $option): ?string
    {
        $value = trim((string) $this->option($option));

        return $value === '' ? null : $value;
    }
}
