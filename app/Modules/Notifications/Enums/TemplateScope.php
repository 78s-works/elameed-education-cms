<?php

namespace App\Modules\Notifications\Enums;

/**
 * Ownership scope of a template row (doc 10 §3). `system` = platform-authored,
 * `tenant_id = NULL`, forced active. `tenant` = a teacher's copy-on-write
 * override, `tenant_id = <teacher>`, defaults inactive.
 */
enum TemplateScope: string
{
    case System = 'system';
    case Tenant = 'tenant';
}
