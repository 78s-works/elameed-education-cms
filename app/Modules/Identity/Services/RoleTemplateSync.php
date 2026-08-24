<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Enums\Permission as PermissionEnum;
use App\Modules\Identity\Enums\PermissionGroup;
use App\Modules\Identity\Enums\RoleTemplateKey;
use App\Modules\Identity\Models\RoleTemplate;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * Defines and refreshes the platform role templates (M20).
 *
 * The teacher template is DERIVED, never authored: it is always the full
 * catalog, so a permission added to the code is covered the moment it is seeded.
 * The other three system templates are deliberately empty — a student holding a
 * role with no permissions is exactly what proves authority flows through roles
 * and nothing else.
 *
 * Free templates are starting points only. Once copied into a tenant the copy is
 * the teacher's; changing a set here never rewrites a copy that already exists.
 */
class RoleTemplateSync
{
    public function sync(): void
    {
        $permissionIds = Permission::query()->pluck('id', 'name');

        DB::transaction(function () use ($permissionIds): void {
            foreach (RoleTemplateKey::cases() as $index => $key) {
                $template = RoleTemplate::updateOrCreate(
                    ['key' => $key->value],
                    [
                        'name' => $key->label(),
                        'description' => $key->description(),
                        'is_system' => $key->isSystem(),
                        'sort_order' => $index,
                    ],
                );

                $ids = collect($this->permissionsFor($key))
                    ->map(fn (string $name): ?int => $permissionIds[$name] ?? null)
                    ->filter()
                    ->all();

                $template->permissions()->sync($ids);
            }
        });
    }

    /**
     * The permission keys a template bundles.
     *
     * @return list<string>
     */
    public function permissionsFor(RoleTemplateKey $key): array
    {
        return match ($key) {
            // Everything, always. Re-derived on every sync.
            RoleTemplateKey::Teacher => PermissionEnum::values(),

            // Membership baselines: no permissions by design.
            RoleTemplateKey::Assistant,
            RoleTemplateKey::Student,
            RoleTemplateKey::ParentGuardian => [],

            RoleTemplateKey::StudentsManager => array_merge(
                $this->group(PermissionGroup::Students),
                $this->group(PermissionGroup::Centers),
            ),

            RoleTemplateKey::Finance => array_merge(
                $this->group(PermissionGroup::Finance),
                [
                    PermissionEnum::StudentsView->value,
                    PermissionEnum::StudentsWalletView->value,
                    PermissionEnum::ReportsView->value,
                ],
            ),

            RoleTemplateKey::HomeworkGrader => [
                PermissionEnum::ContentView->value,
                PermissionEnum::ExamsView->value,
                PermissionEnum::ExamSubmissionsView->value,
                PermissionEnum::ExamGrade->value,
                PermissionEnum::ExamPassOverride->value,
                PermissionEnum::ExamExtensionsReview->value,
                PermissionEnum::StudentsView->value,
            ],

            RoleTemplateKey::SupportAgent => array_merge(
                $this->group(PermissionGroup::Support),
                [
                    PermissionEnum::StudentsView->value,
                    PermissionEnum::StudentsActivityView->value,
                ],
            ),

            RoleTemplateKey::ContentEditor => array_merge(
                $this->group(PermissionGroup::Content),
                $this->group(PermissionGroup::Exams),
            ),
        };
    }

    /** @return list<string> */
    private function group(PermissionGroup $group): array
    {
        return array_values(array_map(
            static fn (PermissionEnum $p): string => $p->value,
            array_filter(
                PermissionEnum::cases(),
                static fn (PermissionEnum $p): bool => $p->group() === $group,
            ),
        ));
    }
}
