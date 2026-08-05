<?php

namespace App\Modules\Wallet\Http\Requests;

use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Wallet\Models\PaymentReceipt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Student submits a manual wallet top-up (VD R9): a Vodafone Cash / InstaPay
 * receipt image. The attachment must have been uploaded by this student in this
 * tenant (via POST /attachments) — so a student can only attach their own image,
 * never reference someone else's or a cross-tenant one.
 */
class SubmitManualTopupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // any active member; the route enforces auth + active
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantOrFail()->getKey();

        return [
            'method' => ['required', Rule::in([
                PaymentReceipt::METHOD_VODAFONE_CASH,
                PaymentReceipt::METHOD_INSTAPAY,
            ])],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'attachment_id' => [
                'required', 'uuid',
                Rule::exists('attachments', 'uuid')
                    ->where('tenant_id', $tenantId)
                    ->where('uploaded_by', $this->user()->getKey()),
            ],
        ];
    }
}
