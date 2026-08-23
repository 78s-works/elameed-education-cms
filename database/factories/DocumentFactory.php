<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\Files\Enums\DocumentKind;
use App\Support\Files\Enums\DocumentPurpose;
use App\Support\Files\Enums\DocumentStatus;
use App\Support\Files\Enums\DocumentVisibility;
use App\Support\Files\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        $purpose = DocumentPurpose::PaymentReceipt;
        $visibility = $purpose->visibility();

        return [
            'owner_id' => User::factory(),
            'purpose' => $purpose,
            'kind' => DocumentKind::Image,
            'visibility' => $visibility,
            'status' => DocumentStatus::Ready,
            'disk' => $visibility->disk(),
            'storage_key' => 'tenants/0/'.$purpose->value.'/'.Str::ulid().'.png',
            'original_name' => 'receipt.png',
            'mime' => 'image/png',
            'extension' => 'png',
            'size_bytes' => 2048,
            'checksum' => hash('sha256', 'seed'),
        ];
    }

    public function purpose(DocumentPurpose $purpose): static
    {
        return $this->state(fn () => [
            'purpose' => $purpose,
            'visibility' => $purpose->visibility(),
            'disk' => $purpose->visibility()->disk(),
            'storage_key' => 'tenants/0/'.$purpose->value.'/'.Str::ulid().'.png',
        ]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn () => ['owner_id' => $user->getKey()]);
    }

    /** Attach to a Pattern B owner (comment, ticket, attempt, lesson, tenant). */
    public function attachedTo(Model $owner): static
    {
        return $this->state(fn () => [
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => $owner->getKey(),
        ]);
    }

    /**
     * Put real bytes behind the row. Tests that only check authorization or
     * linking do not need this; anything that downloads the file does.
     */
    public function withBlob(string $contents = 'test file'): static
    {
        return $this->afterCreating(function (Document $document) use ($contents): void {
            Storage::disk($document->disk)->put($document->storage_key, $contents);
        });
    }

    public function visibility(DocumentVisibility $visibility): static
    {
        return $this->state(fn () => [
            'visibility' => $visibility,
            'disk' => $visibility->disk(),
        ]);
    }
}
