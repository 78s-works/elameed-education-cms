<?php

namespace Tests\Feature\Documents\Concerns;

use App\Models\User;
use App\Modules\Identity\Enums\MembershipStatus;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Identity\Models\TenantUser;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Files\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;

/**
 * Shared scaffolding for the document suites: a tenant, members of each role,
 * and an upload helper that returns the created Document rather than the JSON.
 */
trait MakesDocuments
{
    protected Tenant $tenant;

    /** @var array<string, string> */
    protected array $h = ['X-Tenant' => 'demo'];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['slug' => 'demo', 'name' => 'Demo', 'status' => TenantStatus::Active]);
    }

    protected function member(TenantUserRole $role, array $permissions = [], ?Tenant $tenant = null): User
    {
        $tenant ??= $this->tenant;
        $user = User::factory()->create();

        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role->value,
            'status' => MembershipStatus::Active->value,
            'permissions' => $permissions !== [] ? $permissions : null,
            'joined_at' => now(),
        ]);

        return $user;
    }

    protected function teacher(?Tenant $tenant = null): User
    {
        return $this->member(TenantUserRole::Teacher, tenant: $tenant);
    }

    protected function student(?Tenant $tenant = null): User
    {
        return $this->member(TenantUserRole::Student, tenant: $tenant);
    }

    protected function assistant(array $permissions = []): User
    {
        return $this->member(TenantUserRole::Assistant, $permissions);
    }

    /** Upload through the real endpoint and hand back the stored row. */
    protected function uploadAs(string $purpose, UploadedFile $file): Document
    {
        $uuid = $this->withHeaders($this->h)->post('/api/v1/documents', [
            'purpose' => $purpose,
            'file' => $file,
        ])->assertStatus(201)->json('data.uuid');

        return Document::withoutGlobalScope('tenant')->where('uuid', $uuid)->firstOrFail();
    }
}
