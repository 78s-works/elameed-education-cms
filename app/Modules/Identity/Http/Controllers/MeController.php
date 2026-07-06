<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Resources\MeResource;
use Illuminate\Http\Request;

/**
 * GET /me — current user + tenant memberships + current-tenant role.
 */
class MeController
{
    public function __invoke(Request $request): MeResource
    {
        $user = $request->user()->load('memberships.tenant');

        return new MeResource($user);
    }
}
