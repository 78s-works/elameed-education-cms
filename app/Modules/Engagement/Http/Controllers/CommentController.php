<?php

namespace App\Modules\Engagement\Http\Controllers;

use App\Models\User;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Commerce\Services\EnrollmentService;
use App\Modules\Engagement\Enums\CommentStatus;
use App\Modules\Engagement\Http\Requests\StoreCommentRequest;
use App\Modules\Engagement\Http\Resources\CommentResource;
use App\Modules\Engagement\Models\Attachment;
use App\Modules\Engagement\Models\Comment;
use App\Modules\Identity\Enums\TenantUserRole;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Lesson Q&A (M09, FR-M09-01/03). Shared by students and staff (teacher/assistant)
 * — the actor's tenant role decides privileges: students need access to the
 * lesson and never see hidden comments; a staff reply marks the question answered.
 */
class CommentController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EnrollmentService $enrollments,
    ) {}

    public function index(Request $request, Lesson $lesson): AnonymousResourceCollection
    {
        $this->ensureAccess($request, $lesson);

        $query = Comment::query()
            ->where('lesson_id', $lesson->getKey())
            ->topLevel()
            ->with(['user', 'attachments', 'replies' => fn ($q) => $q->with('user', 'attachments')->orderBy('id')])
            ->latest('id');

        // Students only see published comments; staff see held ones too.
        if (! $this->isStaff($request)) {
            $query->visible();
        }

        return CommentResource::collection($query->paginate(20));
    }

    public function store(StoreCommentRequest $request, Lesson $lesson): JsonResponse
    {
        $this->ensureAccess($request, $lesson);
        $user = $request->user();

        $comment = new Comment([
            'lesson_id' => $lesson->getKey(),
            'user_id' => $user->getKey(),
            'body' => $request->validated('body'),
            'status' => CommentStatus::New->value,
        ]);
        $comment->save();

        $this->linkAttachments($comment, $request, $user);

        return (new CommentResource($comment->load('user', 'attachments')))->response()->setStatusCode(201);
    }

    /** Reply within a thread. A staff reply resolves the parent question. */
    public function reply(StoreCommentRequest $request, Comment $comment): JsonResponse
    {
        $parent = $comment->parent_id !== null ? $comment->parent : $comment;
        $lesson = $comment->lesson;
        abort_if($lesson === null, 404, 'Lesson not found.');

        $this->ensureAccess($request, $lesson);
        $user = $request->user();

        $reply = new Comment([
            'lesson_id' => $comment->lesson_id,
            'user_id' => $user->getKey(),
            'parent_id' => $parent->getKey(),
            'body' => $request->validated('body'),
            'status' => CommentStatus::New->value,
        ]);
        $reply->save();

        $this->linkAttachments($reply, $request, $user);

        if ($this->isStaff($request) && $parent->status === CommentStatus::New) {
            $parent->update(['status' => CommentStatus::Answered->value]);
        }

        return (new CommentResource($reply->load('user', 'attachments')))->response()->setStatusCode(201);
    }

    /** Staff (teacher/assistant) bypass enrollment; students need lesson access. */
    private function ensureAccess(Request $request, Lesson $lesson): void
    {
        if ($this->isStaff($request)) {
            return;
        }

        $tenantId = $this->context->tenantOrFail()->getKey();

        if (! $this->enrollments->hasLessonAccess($tenantId, $request->user()->getKey(), $lesson)) {
            throw new AccessDeniedHttpException('You do not have access to this lesson.');
        }
    }

    private function isStaff(Request $request): bool
    {
        $tenant = $this->context->tenant();
        $role = $tenant !== null ? $request->user()?->membershipFor($tenant)?->role : null;

        return in_array($role, [TenantUserRole::Teacher, TenantUserRole::Assistant], true);
    }

    /** Link the caller's own, still-unattached uploads to the comment. */
    private function linkAttachments(Comment $comment, StoreCommentRequest $request, User $user): void
    {
        $uuids = (array) ($request->validated('attachment_ids') ?? []);

        if ($uuids === []) {
            return;
        }

        Attachment::query()
            ->whereIn('uuid', $uuids)
            ->where('uploaded_by', $user->getKey())
            ->whereNull('attachable_id')
            ->update([
                'attachable_type' => $comment->getMorphClass(),
                'attachable_id' => $comment->getKey(),
            ]);
    }
}
