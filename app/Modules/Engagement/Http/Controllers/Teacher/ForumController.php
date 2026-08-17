<?php

namespace App\Modules\Engagement\Http\Controllers\Teacher;

use App\Modules\Engagement\Enums\CommentStatus;
use App\Modules\Engagement\Http\Requests\ModerateCommentRequest;
use App\Modules\Engagement\Http\Resources\CommentResource;
use App\Modules\Engagement\Models\Comment;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Per-teacher forum (M09, FR-M09-02): every lesson question across the academy's
 * courses in one place, plus moderation (FR-M09-03/04). Tenant-scoping is via
 * BelongsToTenant (list) and {comment:uuid} binding (moderation → cross-tenant 404).
 */
class ForumController
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->query('status');
        $request->validate(['status' => ['sometimes', Rule::enum(CommentStatus::class)]]);

        $query = Comment::query()
            ->topLevel()
            ->with(['user', 'lesson:id,title', 'attachments', 'replies' => fn ($q) => $q->with('user', 'attachments')->orderBy('id')])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('id');

        return CommentResource::collection($query->paginate(20));
    }

    public function update(ModerateCommentRequest $request, Comment $comment): CommentResource
    {
        $data = $request->validated();

        if (array_key_exists('status', $data)) {
            $comment->status = $data['status'];
        }

        if (array_key_exists('is_hidden', $data)) {
            $comment->is_hidden = (bool) $data['is_hidden'];
        }

        $comment->save();

        app(AuditLogger::class)->log('comment.moderated', [
            'comment_id' => $comment->getKey(),
            'status' => $comment->status->value,
            'is_hidden' => $comment->is_hidden,
        ], $this->context->tenantOrFail()->getKey(), 'comment', $comment->getKey());

        return new CommentResource($comment->load('user', 'attachments', 'replies'));
    }

    public function destroy(Comment $comment): Response
    {
        $comment->delete(); // soft delete (cascades to replies via parent_id on hard delete only)

        app(AuditLogger::class)->log('comment.deleted', [
            'comment_id' => $comment->getKey(),
        ], $this->context->tenantOrFail()->getKey(), 'comment', $comment->getKey());

        return response()->noContent();
    }
}
