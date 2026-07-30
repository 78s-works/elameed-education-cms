<?php

namespace App\Modules\Catalog\Http\Controllers\Teacher;

use App\Modules\Catalog\Http\Requests\UnitDependencyRequest;
use App\Modules\Catalog\Http\Resources\UnitDependencyResource;
use App\Modules\Catalog\Models\LessonSection;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Models\UnitDependency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * /teacher/units/{unit}/dependencies — configurable, non-sequential unit
 * prerequisites (extends R5.3). A unit can be gated behind another unit's exam or
 * a specific section anywhere in the academy. Only mandatory rows block; a unit
 * with no rows falls back to the previous-unit-exam default.
 */
class UnitDependencyController
{
    public function index(Unit $unit): AnonymousResourceCollection
    {
        return UnitDependencyResource::collection(
            UnitDependency::where('unit_id', $unit->id)->orderBy('id')->get()
        );
    }

    public function store(UnitDependencyRequest $request, Unit $unit): JsonResponse
    {
        $data = $request->validated();
        $dependsUnit = isset($data['depends_on_unit_id']) ? (int) $data['depends_on_unit_id'] : null;
        $dependsSection = isset($data['depends_on_section_id']) ? (int) $data['depends_on_section_id'] : null;

        if ($dependsUnit !== null) {
            abort_if($dependsUnit === $unit->id, 422, 'A unit cannot depend on itself.');
            abort_unless(Unit::whereKey($dependsUnit)->exists(), 422, 'The prerequisite unit is not in this academy.');
        }

        if ($dependsSection !== null) {
            abort_unless(LessonSection::whereKey($dependsSection)->exists(), 422, 'The prerequisite section is not in this academy.');
        }

        $exists = UnitDependency::where('unit_id', $unit->id)
            ->where('depends_on_unit_id', $dependsUnit)
            ->where('depends_on_section_id', $dependsSection)
            ->exists();
        abort_if($exists, 422, 'This unit dependency already exists.');

        $dependency = new UnitDependency([
            'unit_id' => $unit->id,
            'depends_on_unit_id' => $dependsUnit,
            'depends_on_section_id' => $dependsSection,
            'trigger' => $data['trigger'],
            'enforcement' => $data['enforcement'],
        ]);
        $dependency->tenant_id = $unit->tenant_id;
        $dependency->save();

        return (new UnitDependencyResource($dependency))->response()->setStatusCode(201);
    }

    public function destroy(Unit $unit, UnitDependency $dependency): Response
    {
        abort_unless($dependency->unit_id === $unit->id, 404);

        $dependency->delete();

        return response()->noContent();
    }
}
