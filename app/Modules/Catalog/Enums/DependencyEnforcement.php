<?php

namespace App\Modules\Catalog\Enums;

/**
 * How strictly a content dependency is enforced:
 *   mandatory — the dependent section stays LOCKED until the trigger is met
 *   optional  — advisory only (surfaced to the client, never blocks access)
 */
enum DependencyEnforcement: string
{
    case Mandatory = 'mandatory';
    case Optional = 'optional';
}
