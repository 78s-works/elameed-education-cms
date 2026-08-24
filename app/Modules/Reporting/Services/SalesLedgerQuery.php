<?php

namespace App\Modules\Reporting\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Lesson;
use App\Modules\Catalog\Models\Package;
use App\Modules\Centers\Models\ActivationCode;
use App\Modules\Commerce\Enums\EnrollmentSource;
use App\Modules\Commerce\Enums\OrderStatus;
use App\Modules\Commerce\Enums\SalesMethod;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Models\OrderItem;
use App\Modules\Commerce\Models\Refund;
use App\Modules\Tenancy\Services\TenantContext;
use App\Modules\Wallet\Models\PaymentReceipt;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The sales ledger (M17): every transaction of the academy in one list, with
 * filters, totals for the ACTIVE FILTER, and the row detail the table renders.
 *
 * Two things make this more than a `select * from orders`:
 *
 *  1. A sale does not always have an order. A checkout writes `orders` +
 *     `order_items`; an activation code, a staff grant and a center check-in
 *     write only `enrollments`. Both are sales, so the row set is a UNION of the
 *     two legs, paginated and totalled as one list.
 *  2. "Payment method" is not a column. It is derived — see {@see SalesMethod} —
 *     from `payments.gateway` (paymob / fawry), from the absence of a gateway
 *     payment (wallet), from `enrollments.source` (code / manual / center), and
 *     from a zero price (free).
 *
 * Wallet top-ups are NOT sales and never enter these rows or totals: the money
 * is counted when the student spends it on content, and counting the top-up too
 * would count it twice. They have their own view, {@see topups}.
 *
 * Money is integer minor units everywhere; nothing here converts to pounds.
 */
class SalesLedgerQuery
{
    /** Order item types that represent content revenue (everything but a top-up). */
    private const CONTENT_TYPES = [
        OrderItem::TYPE_LESSON,
        OrderItem::TYPE_PACKAGE,
        OrderItem::TYPE_BOOK,
    ];

    /** Enrollment sources that are a sale WITHOUT an order behind them. */
    private const GRANT_SOURCES = [
        EnrollmentSource::Code->value,
        EnrollmentSource::Manual->value,
        EnrollmentSource::Center->value,
    ];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * One page of ledger rows, newest first, plus the totals for the same filter.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: LengthAwarePaginator, totals: array<string, mixed>}
     */
    public function page(array $filters, int $perPage = 25, int $page = 1): array
    {
        $base = $this->filtered($filters);

        $total = (int) (clone $base)->count();

        $raw = (clone $base)
            ->orderByDesc('occurred_at')
            ->orderByDesc('row_id')
            ->forPage($page, $perPage)
            ->get();

        $rows = new LengthAwarePaginator(
            $this->hydrate($raw),
            $total,
            $perPage,
            $page,
        );

        return ['rows' => $rows, 'totals' => $this->totals($filters)];
    }

    /**
     * Every row for the filter, unpaginated, as a lazy generator — the export
     * path. Chunked so a year of sales never sits in memory at once.
     *
     * @param  array<string, mixed>  $filters
     * @return \Generator<int, array<string, mixed>>
     */
    public function stream(array $filters, int $chunk = 500): \Generator
    {
        $base = $this->filtered($filters)
            ->orderByDesc('occurred_at')
            ->orderByDesc('row_id');

        $page = 1;

        do {
            $raw = (clone $base)->forPage($page, $chunk)->get();

            foreach ($this->hydrate($raw) as $row) {
                yield $row;
            }

            $page++;
        } while ($raw->count() === $chunk);
    }

    /**
     * Totals for the ACTIVE FILTER (never the whole dataset).
     *
     *  collected − refunded = net sales. `collected` counts what was actually
     *  taken (paid + refunded rows, because a refunded row WAS collected first),
     *  and `refunded` is SUM(refunds.amount_minor) so a partial refund subtracts
     *  only its own amount. Pending and Failed are reported separately and stay
     *  out of the sales figure entirely.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function totals(array $filters): array
    {
        $sub = $this->filtered($filters);

        $agg = DB::query()->fromSub($sub, 'sales')->selectRaw(
            'count(*) as transactions'
            .", sum(case when status in ('paid','refunded') then net_minor else 0 end) as collected_minor"
            .', sum(refunded_minor) as refunded_minor'
            .", sum(case when status = 'pending' then net_minor else 0 end) as pending_minor"
            .", sum(case when status = 'pending' then 1 else 0 end) as pending_count"
            .", sum(case when status = 'failed' then net_minor else 0 end) as failed_minor"
            .", sum(case when status = 'failed' then 1 else 0 end) as failed_count"
            .", sum(case when status = 'refunded' then 1 else 0 end) as refunded_count"
        )->first();

        $collected = (int) ($agg->collected_minor ?? 0);
        $refunded = (int) ($agg->refunded_minor ?? 0);

        $byMethod = DB::query()->fromSub($this->filtered($filters), 'sales')
            ->selectRaw(
                'method'
                .", sum(case when status in ('paid','refunded') then net_minor else 0 end) - sum(refunded_minor) as net_minor"
                .', count(*) as transactions'
            )
            ->groupBy('method')
            ->get()
            ->map(fn ($row): array => [
                'method' => (string) $row->method,
                'net_minor' => (int) $row->net_minor,
                'transactions' => (int) $row->transactions,
            ])
            ->sortByDesc('net_minor')
            ->values()
            ->all();

        return [
            'currency' => 'EGP',
            'transactions' => (int) ($agg->transactions ?? 0),
            'collected_minor' => $collected,
            'refunded_minor' => $refunded,
            // The headline figure: paid minus refunded.
            'net_sales_minor' => $collected - $refunded,
            'pending_minor' => (int) ($agg->pending_minor ?? 0),
            'pending_count' => (int) ($agg->pending_count ?? 0),
            'failed_minor' => (int) ($agg->failed_minor ?? 0),
            'failed_count' => (int) ($agg->failed_count ?? 0),
            'refunded_count' => (int) ($agg->refunded_count ?? 0),
            'by_method' => $byMethod,
        ];
    }

    /**
     * Wallet top-ups for the same date window — shown apart from sales and NEVER
     * added to the sales figure (the money is counted when it is spent). Three
     * ways a wallet grows: a top-up checkout, a wallet activation code, and an
     * approved manual receipt (Vodafone Cash / InstaPay).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function topups(array $filters): array
    {
        $from = $filters['date_from'] ?? null;
        $to = $filters['date_to'] ?? null;
        $window = function ($query, string $column) use ($from, $to) {
            if ($from !== null) {
                $query->where($column, '>=', $from);
            }
            if ($to !== null) {
                $query->where($column, '<=', $to);
            }

            return $query;
        };

        $checkout = OrderItem::query()
            ->where('item_type', OrderItem::TYPE_WALLET_TOPUP)
            ->whereIn('order_id', Order::query()->where('status', OrderStatus::Paid->value)->select('id'));
        $window($checkout, 'created_at');

        $codes = ActivationCode::query()
            ->where('type', 'wallet')
            ->where('status', 'redeemed');
        $window($codes, 'redeemed_at');

        $receipts = PaymentReceipt::query()->where('status', 'approved');
        $window($receipts, 'reviewed_at');

        $gateway = [
            'source' => 'gateway',
            'amount_minor' => (int) (clone $checkout)->sum('price_minor'),
            'count' => (clone $checkout)->count(),
        ];
        $code = [
            'source' => 'code',
            'amount_minor' => (int) (clone $codes)->sum('amount_minor'),
            'count' => (clone $codes)->count(),
        ];
        $receipt = [
            'source' => 'manual_receipt',
            // The reviewer may have corrected the submitted amount at approval.
            'amount_minor' => (int) (clone $receipts)->sum(
                DB::raw('coalesce(corrected_amount_minor, amount_minor)')
            ),
            'count' => (clone $receipts)->count(),
        ];

        return [
            'currency' => 'EGP',
            'by_source' => [$gateway, $code, $receipt],
            'total_minor' => $gateway['amount_minor'] + $code['amount_minor'] + $receipt['amount_minor'],
            'count' => $gateway['count'] + $code['count'] + $receipt['count'],
            // Said out loud for the client: this figure is deliberately NOT part
            // of net_sales_minor.
            'counted_in_sales' => false,
        ];
    }

    /**
     * Filter options the page's dropdowns need: the teacher's sellable items,
     * plus the method / status vocabularies.
     *
     * @return array<string, mixed>
     */
    public function filterOptions(): array
    {
        $lessons = Lesson::query()
            ->orderBy('title')
            ->get(['id', 'title', 'price_minor'])
            ->map(fn (Lesson $l): array => [
                'item_type' => OrderItem::TYPE_LESSON,
                'item_id' => (int) $l->getKey(),
                'title' => (string) $l->title,
                'price_minor' => (int) $l->price_minor,
            ]);

        // A package's display label lives in `name` (lessons use `title`).
        $packages = Package::query()
            ->orderBy('name')
            ->get(['id', 'name', 'price_minor'])
            ->map(fn (Package $p): array => [
                'item_type' => OrderItem::TYPE_PACKAGE,
                'item_id' => (int) $p->getKey(),
                'title' => (string) $p->name,
                'price_minor' => (int) $p->price_minor,
            ]);

        return [
            'items' => $packages->concat($lessons)->values()->all(),
            'methods' => array_map(
                fn (SalesMethod $m): array => ['value' => $m->value, 'label' => $m->label()],
                SalesMethod::cases(),
            ),
            'statuses' => array_map(fn (OrderStatus $s): string => $s->value, OrderStatus::cases()),
        ];
    }

    // ── Row set ──────────────────────────────────────────────────────────────

    /**
     * The UNION of both legs, wrapped so the shared filters (date, status,
     * method, student, search) apply once to the merged list.
     *
     * @param  array<string, mixed>  $filters
     */
    private function filtered(array $filters): QueryBuilder
    {
        $query = DB::query()->fromSub($this->union($filters), 'sales');

        if (($from = $filters['date_from'] ?? null) !== null) {
            $query->where('occurred_at', '>=', $from);
        }
        if (($to = $filters['date_to'] ?? null) !== null) {
            $query->where('occurred_at', '<=', $to);
        }
        if (($studentId = $filters['student_id'] ?? null) !== null) {
            $query->where('user_id', (int) $studentId);
        }
        if (($statuses = $filters['status'] ?? null)) {
            $query->whereIn('status', (array) $statuses);
        }
        if (($methods = $filters['method'] ?? null)) {
            $query->whereIn('method', (array) $methods);
        }
        if (($term = trim((string) ($filters['q'] ?? ''))) !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $studentIds = User::query()
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('phone', 'like', $like))
                ->limit(200)
                ->pluck('id');

            $query->where(function ($q) use ($like, $studentIds): void {
                $q->where('reference', 'like', $like);
                if ($studentIds->isNotEmpty()) {
                    $q->orWhereIn('user_id', $studentIds->all());
                }
            });
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function union(array $filters): QueryBuilder
    {
        $orders = $this->ordersLeg($filters);

        // The grant leg only ever produces PAID rows (no money state to be in) —
        // when the filter asks for pending/failed/refunded only, it contributes
        // nothing and is skipped.
        $statuses = (array) ($filters['status'] ?? []);
        $wantsPaid = $statuses === [] || in_array(OrderStatus::Paid->value, $statuses, true);

        if ($wantsPaid) {
            $orders->unionAll($this->lessonGrantsLeg($filters));
            $orders->unionAll($this->packageGrantsLeg($filters));
        }

        return $orders;
    }

    /**
     * Leg 1 — checkout orders. `net_minor` is the content total minus the order's
     * coupon discount, so a mixed order (content + top-up, rare) contributes only
     * its content, and a pure top-up order contributes no row at all.
     *
     * @param  array<string, mixed>  $filters
     */
    private function ordersLeg(array $filters): QueryBuilder
    {
        $content = DB::table('order_items')
            ->select('order_id')
            ->selectRaw('sum(price_minor) as gross_minor')
            ->whereIn('item_type', self::CONTENT_TYPES)
            ->where('tenant_id', $this->tenantId())
            ->groupBy('order_id');

        // The latest payment attempt on the order decides the gateway, whatever
        // its state: a failed card attempt is still a card attempt.
        $latestPayment = DB::table('payments')
            ->select('order_id')
            ->selectRaw('max(id) as payment_id')
            ->where('tenant_id', $this->tenantId())
            ->groupBy('order_id');

        $refunds = DB::table('refunds')
            ->select('order_id')
            ->selectRaw('sum(amount_minor) as refunded_minor')
            ->where('tenant_id', $this->tenantId())
            ->groupBy('order_id');

        $net = 'greatest(cast(0 as signed), cast(content.gross_minor as signed) - cast(orders.discount_minor as signed))';

        $query = DB::table('orders')
            ->joinSub($content, 'content', 'content.order_id', '=', 'orders.id')
            ->leftJoinSub($latestPayment, 'lp', 'lp.order_id', '=', 'orders.id')
            ->leftJoin('payments as pay', 'pay.id', '=', 'lp.payment_id')
            ->leftJoinSub($refunds, 'refunded', 'refunded.order_id', '=', 'orders.id')
            ->where('orders.tenant_id', $this->tenantId())
            ->selectRaw("'order' as kind")
            ->selectRaw('orders.id as row_id')
            ->selectRaw('orders.uuid as reference')
            ->selectRaw('orders.created_at as occurred_at')
            ->selectRaw('orders.user_id as user_id')
            ->selectRaw('orders.status as status')
            ->selectRaw(
                'case'
                ." when {$net} = 0 then ?"
                .' when pay.gateway = ? then ?'
                .' when pay.gateway = ? then ?'
                .' else ? end as method',
                [
                    SalesMethod::Free->value,
                    'paymob', SalesMethod::CardPaymob->value,
                    'fawry', SalesMethod::Fawry->value,
                    SalesMethod::Wallet->value,
                ],
            )
            ->selectRaw('cast(content.gross_minor as signed) as gross_minor')
            ->selectRaw('cast(orders.discount_minor as signed) as discount_minor')
            ->selectRaw("{$net} as net_minor")
            ->selectRaw('cast(coalesce(refunded.refunded_minor, 0) as signed) as refunded_minor')
            ->selectRaw('cast(null as char(16)) as item_type')
            ->selectRaw('cast(null as signed) as item_id');

        $this->applyItemFilter($query, $filters, function ($q, string $itemType, int $itemId): void {
            $q->whereExists(function ($sub) use ($itemType, $itemId): void {
                $sub->from('order_items')
                    ->whereColumn('order_items.order_id', 'orders.id')
                    ->where('order_items.item_type', $itemType)
                    ->where('order_items.item_id', $itemId);
            });
        });

        return $query;
    }

    /**
     * Leg 2 — a single lesson granted without a checkout (code / staff / center).
     * There is no captured price on an enrollment, so the sale is valued at the
     * lesson's CURRENT price.
     *
     * @param  array<string, mixed>  $filters
     */
    private function lessonGrantsLeg(array $filters): QueryBuilder
    {
        $query = DB::table('enrollments')
            ->join('lessons', 'lessons.id', '=', 'enrollments.lesson_id')
            ->where('enrollments.tenant_id', $this->tenantId())
            ->whereIn('enrollments.source', self::GRANT_SOURCES)
            ->whereNull('enrollments.package_id')
            ->whereNotNull('enrollments.lesson_id')
            ->selectRaw("'grant' as kind")
            ->selectRaw('enrollments.id as row_id')
            ->selectRaw('cast(null as char(36)) as reference')
            ->selectRaw('enrollments.created_at as occurred_at')
            ->selectRaw('enrollments.user_id as user_id')
            ->selectRaw('? as status', [OrderStatus::Paid->value])
            ->selectRaw(
                'case when lessons.price_minor = 0 then ? else enrollments.source end as method',
                [SalesMethod::Free->value],
            )
            ->selectRaw('cast(lessons.price_minor as signed) as gross_minor')
            ->selectRaw('cast(0 as signed) as discount_minor')
            ->selectRaw('cast(lessons.price_minor as signed) as net_minor')
            ->selectRaw('cast(0 as signed) as refunded_minor')
            ->selectRaw('? as item_type', [OrderItem::TYPE_LESSON])
            ->selectRaw('cast(enrollments.lesson_id as signed) as item_id');

        $this->applyItemFilter($query, $filters, function ($q, string $itemType, int $itemId): void {
            $itemType === OrderItem::TYPE_LESSON
                ? $q->where('enrollments.lesson_id', $itemId)
                : $q->whereRaw('1 = 0'); // a package filter can't match a direct lesson grant
        });

        return $query;
    }

    /**
     * Leg 3 — a PACKAGE granted without a checkout. The grant fans out into one
     * enrollment per descendant lesson, which would read as N sales; they are
     * folded back into one row per (student, package, source), valued at the
     * package's current price and dated by the first grant of the batch.
     *
     * @param  array<string, mixed>  $filters
     */
    private function packageGrantsLeg(array $filters): QueryBuilder
    {
        $query = DB::table('enrollments')
            ->join('packages', 'packages.id', '=', 'enrollments.package_id')
            ->where('enrollments.tenant_id', $this->tenantId())
            ->whereIn('enrollments.source', self::GRANT_SOURCES)
            ->whereNotNull('enrollments.package_id')
            ->groupBy('enrollments.user_id', 'enrollments.package_id', 'enrollments.source', 'packages.price_minor')
            ->selectRaw("'grant' as kind")
            ->selectRaw('min(enrollments.id) as row_id')
            ->selectRaw('cast(null as char(36)) as reference')
            ->selectRaw('min(enrollments.created_at) as occurred_at')
            ->selectRaw('enrollments.user_id as user_id')
            ->selectRaw('? as status', [OrderStatus::Paid->value])
            ->selectRaw(
                'case when packages.price_minor = 0 then ? else enrollments.source end as method',
                [SalesMethod::Free->value],
            )
            ->selectRaw('cast(packages.price_minor as signed) as gross_minor')
            ->selectRaw('cast(0 as signed) as discount_minor')
            ->selectRaw('cast(packages.price_minor as signed) as net_minor')
            ->selectRaw('cast(0 as signed) as refunded_minor')
            ->selectRaw('? as item_type', [OrderItem::TYPE_PACKAGE])
            ->selectRaw('cast(enrollments.package_id as signed) as item_id');

        $this->applyItemFilter($query, $filters, function ($q, string $itemType, int $itemId): void {
            $itemType === OrderItem::TYPE_PACKAGE
                ? $q->where('enrollments.package_id', $itemId)
                : $q->where('enrollments.lesson_id', $itemId);
        });

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  callable(QueryBuilder, string, int): void  $apply
     */
    private function applyItemFilter(QueryBuilder $query, array $filters, callable $apply): void
    {
        $itemId = $filters['item_id'] ?? null;
        if ($itemId === null) {
            return;
        }

        $apply($query, (string) ($filters['item_type'] ?? OrderItem::TYPE_LESSON), (int) $itemId);
    }

    // ── Row detail ───────────────────────────────────────────────────────────

    /**
     * Turns the flat union rows of one page into the table's shape: buyer,
     * item lines, coupon, reference. Batched — three lookups for the page, not
     * three per row.
     *
     * @param  Collection<int, object>  $raw
     * @return list<array<string, mixed>>
     */
    private function hydrate(Collection $raw): array
    {
        if ($raw->isEmpty()) {
            return [];
        }

        $orderIds = $raw->where('kind', 'order')->pluck('row_id')->all();
        $grantRows = $raw->where('kind', 'grant');

        $students = User::query()
            ->whereIn('id', $raw->pluck('user_id')->unique()->all())
            ->get(['id', 'uuid', 'name', 'phone'])
            ->keyBy('id');

        $items = $orderIds === [] ? collect() : OrderItem::query()
            ->whereIn('order_id', $orderIds)
            ->whereIn('item_type', self::CONTENT_TYPES)
            ->get(['order_id', 'item_type', 'item_id', 'title', 'price_minor'])
            ->groupBy('order_id');

        $coupons = $orderIds === [] ? collect() : Order::query()
            ->whereIn('id', $orderIds)
            ->whereNotNull('coupon_id')
            ->with('coupon:id,code')
            ->get(['id', 'coupon_id'])
            ->keyBy('id');

        $refunds = $orderIds === [] ? collect() : Refund::query()
            ->whereIn('order_id', $orderIds)
            ->orderBy('id')
            ->get(['order_id', 'uuid', 'amount_minor', 'destination', 'reason', 'created_at'])
            ->groupBy('order_id');

        $grantTitles = $this->grantTitles($grantRows);
        $grantCodes = $this->grantCodes($grantRows);

        return $raw->map(function (object $row) use ($students, $items, $coupons, $refunds, $grantTitles, $grantCodes): array {
            $student = $students->get((int) $row->user_id);
            $isOrder = $row->kind === 'order';
            $key = $row->item_type.':'.$row->item_id;

            $lines = $isOrder
                ? ($items->get((int) $row->row_id) ?? collect())->map(fn ($i): array => [
                    'item_type' => (string) $i->item_type,
                    'item_id' => $i->item_id === null ? null : (int) $i->item_id,
                    'title' => $i->title,
                    'price_minor' => (int) $i->price_minor,
                ])->values()->all()
                : [[
                    'item_type' => (string) $row->item_type,
                    'item_id' => (int) $row->item_id,
                    'title' => $grantTitles[$key] ?? null,
                    'price_minor' => (int) $row->gross_minor,
                ]];

            $orderRefunds = $isOrder ? ($refunds->get((int) $row->row_id) ?? collect()) : collect();

            return [
                'kind' => (string) $row->kind,
                'id' => (int) $row->row_id,
                // What the teacher can quote back: the order number for a
                // checkout, the redeemed code for a code grant, else the grant id.
                'reference' => $isOrder
                    ? (string) $row->reference
                    : ($grantCodes[$key.':'.$row->user_id] ?? 'GRANT-'.$row->row_id),
                'occurred_at' => (string) $row->occurred_at,
                'status' => (string) $row->status,
                'method' => (string) $row->method,
                'student' => $student === null ? null : [
                    'uuid' => $student->uuid,
                    'name' => $student->name,
                    'phone' => $student->phone,
                ],
                'items' => $lines,
                'currency' => 'EGP',
                'gross_minor' => (int) $row->gross_minor,
                'discount_minor' => (int) $row->discount_minor,
                'net_minor' => (int) $row->net_minor,
                'refunded_minor' => (int) $row->refunded_minor,
                'coupon_code' => $coupons->get((int) $row->row_id)?->coupon?->code,
                'refundable_minor' => $isOrder
                    ? max(0, (int) $row->net_minor - (int) $row->refunded_minor)
                    : 0,
                'refunds' => $orderRefunds->map(fn ($r): array => [
                    'uuid' => $r->uuid,
                    'amount_minor' => (int) $r->amount_minor,
                    'destination' => $r->destination,
                    'reason' => $r->reason,
                    'created_at' => (string) $r->created_at,
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * Titles for the grant rows on this page, keyed `type:id`.
     *
     * @param  Collection<int, object>  $grants
     * @return array<string, string>
     */
    private function grantTitles(Collection $grants): array
    {
        if ($grants->isEmpty()) {
            return [];
        }

        $byType = $grants->groupBy('item_type');
        $titles = [];

        $lessonIds = ($byType[OrderItem::TYPE_LESSON] ?? collect())->pluck('item_id')->unique()->all();
        foreach (Lesson::query()->whereIn('id', $lessonIds)->pluck('title', 'id') as $id => $title) {
            $titles[OrderItem::TYPE_LESSON.':'.$id] = (string) $title;
        }

        $packageIds = ($byType[OrderItem::TYPE_PACKAGE] ?? collect())->pluck('item_id')->unique()->all();
        foreach (Package::query()->whereIn('id', $packageIds)->pluck('name', 'id') as $id => $title) {
            $titles[OrderItem::TYPE_PACKAGE.':'.$id] = (string) $title;
        }

        return $titles;
    }

    /**
     * The activation code behind each code-sourced grant on this page, keyed
     * `type:id:user`. A content code names its target, so the redeemed code can
     * be matched back to the grant it produced.
     *
     * @param  Collection<int, object>  $grants
     * @return array<string, string>
     */
    private function grantCodes(Collection $grants): array
    {
        $codeGrants = $grants->where('method', EnrollmentSource::Code->value);
        if ($codeGrants->isEmpty()) {
            return [];
        }

        $codes = ActivationCode::query()
            ->where('status', 'redeemed')
            ->whereIn('redeemed_by', $codeGrants->pluck('user_id')->unique()->all())
            ->whereNotNull('target_id')
            ->orderByDesc('redeemed_at')
            ->get(['code', 'target_type', 'target_id', 'redeemed_by']);

        $map = [];
        foreach ($codes as $code) {
            $key = $code->target_type.':'.$code->target_id.':'.$code->redeemed_by;
            $map[$key] ??= (string) $code->code;
        }

        return $map;
    }

    private function tenantId(): int
    {
        return (int) $this->context->tenantOrFail()->getKey();
    }
}
