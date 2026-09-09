<?php

namespace App\Modules\Notifications\Services\Broadcasts;

use App\Models\User;
use App\Modules\Notifications\Enums\BroadcastAudience;
use App\Modules\Notifications\Enums\BroadcastStatus;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Models\NotificationBroadcast;
use App\Modules\Notifications\Models\NotificationEvent;
use App\Modules\Notifications\Models\NotificationFailure;
use App\Modules\Notifications\Models\NotificationType;
use App\Modules\Notifications\Services\Engine\ChannelAvailability;
use App\Modules\Notifications\Services\Factories\ChannelFactory;
use App\Modules\Notifications\Support\RunsInTenantContext;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Custom (human-written) notifications: the preview the sender confirms, and the
 * delivery that follows.
 *
 * Automatic notifications go through NotificationEngineService, which renders
 * admin-authored templates. A custom message carries its OWN copy, so this
 * service writes it straight to the channel dispatchers instead — but it still
 * records a `NotificationEvent` (under the `custom.message` type) so custom
 * sends appear in the same audit trail and admin drill-down as everything else.
 *
 * Two deliberate rules, from the spec:
 *   - a custom message from a teacher reaches students who muted notifications
 *     (no NotificationPreference check here), and
 *   - the sender always sees the reach and the SMS cost before confirming.
 */
class BroadcastService
{
    use RunsInTenantContext;

    /** The catalog entry every custom message is filed under. */
    public const TYPE_KEY = 'custom.message';

    public function __construct(
        private readonly AudienceResolver $audienceResolver,
        private readonly SmsCostEstimator $costEstimator,
        private readonly ChannelAvailability $availability,
        private readonly ChannelFactory $channelFactory,
    ) {}

    /**
     * What this send would do: who it reaches, on which channels, and what the
     * SMS part costs. Pure read — nothing is delivered or persisted.
     *
     * @param  array{audience_type:string,audience_ids?:array,channels:array,title_ar?:?string,body_ar?:?string,title_en?:?string,body_en?:?string}  $input
     * @return array<string, mixed>
     */
    public function preview(array $input, ?int $tenantId): array
    {
        $audience = BroadcastAudience::from($input['audience_type']);
        $recipientIds = $this->audienceResolver->resolve($audience, $tenantId, $input['audience_ids'] ?? []);
        $requested = array_values(array_unique($input['channels'] ?? []));

        $usable = $tenantId === null
            ? [NotificationChannel::Database->value, NotificationChannel::Email->value]
            : $this->availability->availableFor($tenantId);

        $active = array_values(array_intersect($requested, $usable));
        $skipped = array_values(array_diff($requested, $usable));

        $recipients = $this->loadRecipients($recipientIds);
        $fallbackLanguage = $this->tenantLanguage($tenantId);

        $smsRecipients = in_array(NotificationChannel::Sms->value, $active, true)
            ? $recipients->filter(static fn (User $u): bool => trim((string) $u->phone) !== '')
            : collect();

        $emailRecipients = in_array(NotificationChannel::Email->value, $active, true)
            ? $recipients->filter(static fn (User $u): bool => trim((string) $u->email) !== '')
            : collect();

        // Cost is summed per language: Arabic copy bills as UCS-2 (70/67 chars a
        // segment), English as GSM-7 (160/153), so one blended number would lie.
        $totalSegments = 0;
        $totalCostMinor = 0;
        $perLanguage = [];

        foreach ($this->groupByLanguage($smsRecipients, $fallbackLanguage) as $language => $group) {
            $text = trim($this->copyFor($input, $language, 'title').' '.$this->copyFor($input, $language, 'body'));
            $estimate = $this->costEstimator->estimate($text, count($group), $tenantId);

            $totalSegments += $estimate['total_segments'];
            $totalCostMinor += $estimate['total_cost_minor'];
            $perLanguage[$language] = $estimate;
        }

        return [
            'audience_type' => $audience->value,
            'recipients' => $recipients->count(),
            'channels' => [
                'requested' => $requested,
                'active' => $active,
                // e.g. sms with no academy credentials, or push before it exists
                'unavailable' => $skipped,
            ],
            'reach' => [
                'in_app' => in_array(NotificationChannel::Database->value, $active, true) ? $recipients->count() : 0,
                'sms' => $smsRecipients->count(),
                'email' => $emailRecipients->count(),
                'missing_phone' => in_array(NotificationChannel::Sms->value, $active, true)
                    ? $recipients->count() - $smsRecipients->count()
                    : 0,
                'missing_email' => in_array(NotificationChannel::Email->value, $active, true)
                    ? $recipients->count() - $emailRecipients->count()
                    : 0,
            ],
            'sms_cost' => [
                'recipients' => $smsRecipients->count(),
                'total_segments' => $totalSegments,
                'price_per_segment_minor' => $this->costEstimator->pricePerSegmentMinor($tenantId),
                'total_cost_minor' => $totalCostMinor,
                'currency' => (string) config('sms.currency', 'EGP'),
                'by_language' => $perLanguage,
            ],
        ];
    }

    /**
     * Persist the broadcast with the estimate the sender just confirmed. An
     * immediate send is stored `scheduled` with a null clock and delivered right
     * away by the caller, so both paths share one row and one audit trail.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, ?int $tenantId, ?int $authorId): NotificationBroadcast
    {
        $preview = $this->preview($input, $tenantId);

        return NotificationBroadcast::create([
            'tenant_id' => $tenantId,
            'created_by' => $authorId,
            'audience_type' => $input['audience_type'],
            'audience_ids' => $input['audience_ids'] ?? null,
            'channels' => $preview['channels']['active'],
            'title_ar' => $input['title_ar'] ?? null,
            'body_ar' => $input['body_ar'] ?? null,
            'title_en' => $input['title_en'] ?? null,
            'body_en' => $input['body_en'] ?? null,
            'status' => BroadcastStatus::Scheduled->value,
            'scheduled_at' => $input['scheduled_at'] ?? null,
            'recipient_count' => $preview['recipients'],
            'sms_recipient_count' => $preview['sms_cost']['recipients'],
            'sms_segments' => $preview['sms_cost']['total_segments'],
            'sms_cost_minor' => $preview['sms_cost']['total_cost_minor'],
            'currency' => $preview['sms_cost']['currency'],
        ]);
    }

    /**
     * Deliver a broadcast. Claims the row first (`scheduled` → `sending`) so two
     * workers picking up the same due broadcast cannot both send it.
     *
     * @return array<string, mixed> per-channel counters
     */
    public function send(NotificationBroadcast $broadcast): array
    {
        $claimed = NotificationBroadcast::query()
            ->whereKey($broadcast->getKey())
            ->where('status', BroadcastStatus::Scheduled->value)
            ->update(['status' => BroadcastStatus::Sending->value, 'updated_at' => now()]);

        if ($claimed === 0) {
            return ['skipped' => true, 'reason' => 'not-claimable'];
        }

        $broadcast->refresh();

        try {
            $stats = $this->deliver($broadcast);

            $broadcast->update([
                'status' => BroadcastStatus::Sent->value,
                'sent_at' => now(),
                'stats' => $stats,
            ]);

            return $stats;
        } catch (Throwable $e) {
            Log::error('[notifications] broadcast delivery failed', [
                'broadcast' => $broadcast->uuid,
                'error' => $e->getMessage(),
            ]);

            $broadcast->update([
                'status' => BroadcastStatus::Failed->value,
                'failure_reason' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * A delivery always belongs to an ACADEMY, even when the message does not.
     *
     * A teacher reads their inbox inside their own academy — `new_notifications`
     * is tenant-owned and RLS-forced — so a platform broadcast (tenant_id NULL)
     * cannot write rows of its own. It is fanned out instead: recipients are
     * grouped by the academy they belong to, and each group is delivered under
     * that academy, with its own audit event.
     *
     * @return array<string, mixed>
     */
    private function deliver(NotificationBroadcast $broadcast): array
    {
        $recipientIds = $this->audienceResolver->resolve(
            $broadcast->audience_type,
            $broadcast->tenant_id,
            $broadcast->audience_ids ?? [],
        );

        $stats = ['event_ids' => [], 'channels' => [], 'totals' => ['attempted' => 0, 'sent' => 0, 'failed' => 0]];

        foreach ($this->deliveryGroups($broadcast, $recipientIds) as $tenantId => $ids) {
            $group = $this->inTenantContext(
                $tenantId,
                fn (): array => $this->deliverTo($broadcast, (int) $tenantId, $ids),
            );

            if ($group['event_id'] !== null) {
                $stats['event_ids'][] = $group['event_id'];
            }

            foreach ($group['channels'] as $channel => $counters) {
                foreach (['attempted', 'sent', 'failed'] as $key) {
                    $stats['channels'][$channel][$key] = ($stats['channels'][$channel][$key] ?? 0) + $counters[$key];
                    $stats['totals'][$key] += $counters[$key];
                }
            }
        }

        // Kept for the single-academy case, where callers (and the tests) read one
        // event id rather than a list.
        $stats['event_id'] = $stats['event_ids'][0] ?? null;

        return $stats;
    }

    /**
     * Recipients bucketed by the academy their delivery belongs to.
     *
     * @param  list<int>  $recipientIds
     * @return array<int, list<int>>
     */
    private function deliveryGroups(NotificationBroadcast $broadcast, array $recipientIds): array
    {
        if ($recipientIds === []) {
            return [];
        }

        if ($broadcast->tenant_id !== null) {
            return [$broadcast->tenant_id => $recipientIds];
        }

        $groups = [];

        // One row per (teacher, academy): someone who owns two academies hears it
        // in each, which is where each of their inboxes lives.
        $rows = DB::table('tenant_user')
            ->whereIn('user_id', $recipientIds)
            ->where('status', 'active')
            ->get(['tenant_id', 'user_id']);

        foreach ($rows as $row) {
            $groups[(int) $row->tenant_id][] = (int) $row->user_id;
        }

        return $groups;
    }

    /**
     * Deliver one academy's share of a broadcast.
     *
     * @param  list<int>  $recipientIds
     * @return array{event_id: int|null, channels: array<string, array{attempted:int,sent:int,failed:int}>}
     */
    private function deliverTo(NotificationBroadcast $broadcast, int $tenantId, array $recipientIds): array
    {
        $recipients = $this->loadRecipients($recipientIds);

        $event = $this->recordEvent($broadcast, $tenantId);
        $stats = ['event_id' => $event?->getKey(), 'channels' => []];

        if ($event === null || $recipients->isEmpty()) {
            return $stats;
        }

        $fallbackLanguage = $this->tenantLanguage($tenantId);
        $copy = [
            'title_ar' => $broadcast->title_ar,
            'body_ar' => $broadcast->body_ar,
            'title_en' => $broadcast->title_en,
            'body_en' => $broadcast->body_en,
        ];

        foreach ($broadcast->channels as $channelValue) {
            $channel = NotificationChannel::tryFrom($channelValue);

            if ($channel === null) {
                continue;
            }

            if (! $this->availability->isAvailable($tenantId, $channel)) {
                continue;
            }

            $dispatcher = $this->channelFactory->make($channel);

            if ($dispatcher === null) {
                continue;
            }

            $counters = ['attempted' => 0, 'sent' => 0, 'failed' => 0];

            foreach ($this->groupByLanguage($recipients, $fallbackLanguage) as $language => $group) {
                $title = $this->copyFor($copy, $language, 'title');
                $body = $this->copyFor($copy, $language, 'body');

                if (trim($title.$body) === '') {
                    continue; // nothing written in this language and no fallback
                }

                foreach ($group as $user) {
                    // NOTE: no NotificationPreference check — a custom message
                    // from the academy reaches muted students by design.
                    $counters['attempted']++;
                    $result = $dispatcher->send($event, $user, $title, $body, [
                        'language' => $language,
                        'tenant_name' => $event->tenant?->name,
                    ]);

                    if ($result->success) {
                        $counters['sent']++;

                        continue;
                    }

                    $counters['failed']++;
                    NotificationFailure::create([
                        'notification_event_id' => $event->getKey(),
                        'user_id' => $user->getKey(),
                        'channel' => $channel->value,
                        'error_message' => $result->error ?? 'Unknown error',
                    ]);
                }
            }

            $stats['channels'][$channel->value] = $counters;
        }

        return $stats;
    }

    /** The audit row one academy's share of a custom send hangs off. */
    private function recordEvent(NotificationBroadcast $broadcast, int $tenantId): ?NotificationEvent
    {
        $type = NotificationType::query()->where('key', self::TYPE_KEY)->first();

        if ($type === null) {
            Log::warning('[notifications] custom.message type missing — run NotificationCatalogSeeder');

            return null;
        }

        $event = new NotificationEvent([
            'notification_type_id' => $type->getKey(),
            // The academy the DELIVERY belongs to — for a platform broadcast this
            // is the recipient's academy, not the (tenant-less) message itself.
            'tenant_id' => $tenantId,
            'entity_type' => NotificationBroadcast::class,
            'entity_id' => $broadcast->getKey(),
            'payload' => [
                'audience_type' => $broadcast->audience_type->value,
                'audience_ids' => $broadcast->audience_ids,
                'channels' => $broadcast->channels,
            ],
            'triggered_by' => $broadcast->created_by,
        ]);
        $event->save();

        return $event->load('tenant');
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    private function loadRecipients(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return User::query()->whereIn('id', $ids)->get();
    }

    /**
     * @param  Collection<int, User>  $recipients
     * @return array<string, list<User>>
     */
    private function groupByLanguage(Collection $recipients, string $fallback): array
    {
        $grouped = [];

        foreach ($recipients as $user) {
            $language = trim((string) ($user->locale ?? '')) ?: $fallback;
            $grouped[$language][] = $user;
        }

        return $grouped;
    }

    /**
     * The copy for one language, falling back to the other one the sender did
     * write — a message written in Arabic only still reaches an English reader.
     *
     * @param  array<string, mixed>  $copy
     */
    private function copyFor(array $copy, string $language, string $field): string
    {
        $preferred = $language === 'en' ? 'en' : 'ar';
        $other = $preferred === 'en' ? 'ar' : 'en';

        $value = trim((string) ($copy[$field.'_'.$preferred] ?? ''));

        return $value !== '' ? $value : trim((string) ($copy[$field.'_'.$other] ?? ''));
    }

    private function tenantLanguage(?int $tenantId): string
    {
        $default = (string) config('tenancy.default_locale', 'ar');

        if ($tenantId === null) {
            return $default;
        }

        $tenant = Tenant::query()->with('teacherProfile')->find($tenantId);

        return $tenant?->teacherProfile?->primary_locale ?: $default;
    }
}
