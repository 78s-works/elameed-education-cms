<?php

namespace App\Support\Files;

use App\Support\Files\Enums\DocumentPurpose;
use Illuminate\Validation\Rule;

/**
 * Validation rules for an upload, derived from the purpose's config block.
 *
 * Before this, five FormRequests each carried their own hand-written `mimes:`
 * string and their own max size — 20 MB here, 5 MB there — so tightening a limit
 * meant finding every copy. Now a FormRequest says what the file is for and the
 * rules follow.
 */
final class DocumentRules
{
    /**
     * Rules for a required upload of the given purpose.
     *
     * @return array<int, mixed>
     */
    public static function for(DocumentPurpose $purpose, bool $required = true): array
    {
        $rules = [$required ? 'required' : 'nullable', 'file', 'max:'.$purpose->maxKilobytes()];

        if ($purpose->mimes() !== '') {
            $rules[] = 'mimes:'.$purpose->mimes();
        }

        return $rules;
    }

    /** Rule for a `purpose` field the client is allowed to choose from. */
    public static function purposeIn(array $allowed): mixed
    {
        return Rule::in(array_map(fn (DocumentPurpose $p) => $p->value, $allowed));
    }

    /**
     * Rules for a list of previously-uploaded document uuids passed on the
     * request that creates their owner (the two-phase upload's second half).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function documentIds(int $max = 10): array
    {
        return [
            'document_ids' => ['sometimes', 'array', 'max:'.$max],
            'document_ids.*' => ['uuid', 'exists:documents,uuid'],
        ];
    }
}
