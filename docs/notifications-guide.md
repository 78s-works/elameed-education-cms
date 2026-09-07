# Notification system — how to use it

There are two kinds of notification, and they behave differently on purpose.

| | Automatic | Custom |
|---|---|---|
| Who writes it | Admin writes the default text; each academy may reword it | The sender writes it, per message |
| What triggers it | An event in the system (a lesson goes live, a receipt is approved…) | A person pressing send |
| Muted students | Skipped — a mute is respected | **Reached anyway** — a teacher writing to their students gets through |
| Where it is managed | Settings → **الإشعارات** (templates) | Settings → **رسائل مخصصة** (composer) |

Both land in the student's single inbox (the bell, and **الإشعارات** in their menu), and both can go out over in-app, SMS and email. Push is not built yet and shows as disabled.

---

## 1. Automatic notifications

### Turning them on and rewording them

**Teacher → Settings → الإشعارات.** Each notification is listed with its channels. For any of them a teacher can:

- switch a channel off for their academy (students then never get that notification on that channel), or
- rewrite the title and body, in Arabic and/or English.

The first edit takes a private copy for that academy — the platform default is untouched, and other academies are unaffected. There is a reset button to go back to the default.

**Admin → Notifications** owns the defaults: the catalog itself, the per-channel templates, the ar/en copy, and the delivery log with failures.

### Writing the text

Put a placeholder in curly braces and it is replaced at send time:

```
الدرس "{lesson.title}" أصبح متاحًا الآن في {tenant_name}.
```

A fallback for a value that might be missing:

```
تم تمديد الإتاحة حتى {until|default:"وقت لاحق"}.
```

Always available: `{tenant_name}`, `{app_name}`, `{app_url}`, `{now}`. Each notification adds its own — the existing text of a notification is the best guide to which ones it has. **Do not** use Laravel's `:name` style; only `{name}` is interpolated.

### What fires, and when

**Lessons & exams (to students)**
- `lessons.lesson.available` — a lesson becomes visible. Once per lesson: making it visible again, or editing it later, does not re-announce.
- `exams.exam.published` — an exam is published.
- `exams.attempt.graded` — a score is ready.
- `lessons.extension.requested` / `exams.extension.requested` — a student asks for more access time or exam time → **to staff**.
- `lessons.extension.approved` / `exams.extension.approved` — granted → to the student.

**Payments**
- `payments.order.completed` — a purchase goes through.
- `payments.order.refunded` — money is returned.
- `payments.receipt.uploaded` — a student uploads a Vodafone Cash / InstaPay receipt → **to staff who review receipts**.
- `payments.receipt.approved` / `payments.receipt.rejected` — the decision → to the student. The rejection carries the reason.

**Account**
- `account.welcome` — after sign-up.
- `account.otp.requested` — the verification code, by SMS. Editing this template changes the wording of the code message.

**Center students**
- `center.attendance.absent` — marked absent. Goes to the student **and** the guardian: a parent with an account gets it in the app, and the `guardian_phone` on the student's profile gets an SMS. Re-saving the same attendance sheet does not re-alert.
- `center.exam_grade.published` — a paper grade is entered.
- `center.activation_code.redeemed` — a code is used.

**To the teacher / academy**
- `billing.subscription.expiring` (7 days ahead) and `billing.subscription.expired` — at most once a day each.
- `billing.plan_limit.reached` — students, lessons, assistants or storage is full. Once a day per limit, even if a blocked import trips it hundreds of times.
- `domains.custom_domain.verified` — a custom domain went live.

**Support & questions**
- `support.ticket.created` → staff. `support.ticket.replied` → the student.
- `qa.answer.posted` — a question was answered.

Staff notifications follow **permissions**, not job titles: whoever holds the matching permission is notified, so an assistant who reviews receipts hears about receipts and a teacher who delegated that away does not.

---

## 2. Custom notifications

**Teacher → Settings → رسائل مخصصة.**

1. **Pick who it goes to** — everyone, one grade, one package, one lesson, one center, hand-picked students, or the assistants. Package and lesson lists follow the academic year selected at the top of the panel.
2. **Pick the channels** — in-app is always available. SMS is greyed out until the academy has entered its own SMS credentials (below). Email is available. Push is not built yet.
3. **Write the message** — Arabic, English, or both. At least one language needs a title *and* a body. A reader whose language is missing gets the other one.
4. **Press "احسب العدد والتكلفة"** — this is the safety step. It reports how many people the message reaches, how many of them have a phone / an email, and what the SMS part will cost. Changing the message or the audience clears the estimate, so a confirmed cost always belongs to the message actually being sent.
5. **Send now, or set a time.** A scheduled message can be canceled from the history list underneath while it is still waiting.

The history list shows every message with its status, audience, channels, reach and cost.

### Who is allowed to send

A teacher always can. An assistant needs the **"إرسال إشعارات مخصصة"** permission (`settings.notifications.send`), which is **off by default** — add it to one of the academy's roles under Team → Roles and assign that role. Without it every custom-notification screen answers 403.

### Admin → all teachers

**Admin → Notifications → مراسلة المعلمين** is the same composer aimed at every academy owner on the platform, over in-app and email only. There is no platform SMS account to bill a blast to — SMS credentials belong to each academy.

### A note on cost

SMS is billed in *segments*, not messages. Arabic text fits 70 characters in one segment (67 each once it splits); plain English fits 160 (153 once split). So the same message costs roughly twice as much in Arabic. The estimate does this per language and adds it up, using the academy's own per-segment price when one is stored, otherwise `SMS_PRICE_PER_SEGMENT_MINOR` from the environment. With no price configured the cost shows as `0.00` — that is a missing price, not a free send.

---

## 3. SMS setup (per academy)

There is no platform-wide SMS account. Each academy enters its own WE Business SMS (Connekio) details under **Settings → الإشعارات → بوابة الرسائل النصية**: sender name, username, account id, password.

Until that is filled in and switched on, **SMS is simply off for that academy** — nothing is attempted and nothing fails; in-app keeps working. The switch refuses to turn on while a credential is missing. The password is write-only: it is stored encrypted and never sent back to the browser.

---

## 4. Running it

Delivery happens on the queue, and scheduled messages need the scheduler. Both must be running:

```bash
php artisan queue:work
```

```bash
php artisan schedule:work
```

Without the queue worker a custom message sits at **"في قائمة الإرسال"** and never goes out. Without the scheduler, scheduled messages never fire and the daily subscription reminders never run.

In local development `SMS_DRIVER=log`, so text messages are written to `storage/logs/laravel.log` as `[SMS]` lines instead of being sent. Set `SMS_DRIVER=connekio` to send for real.

On deploy, after migrating:

```bash
php artisan rbac:sync
```

That publishes the new `settings.notifications.send` permission so it appears in the role picker.

Two commands can also be run by hand:

```bash
php artisan notifications:send-scheduled
```

```bash
php artisan notifications:subscription-reminders
```

---

## 5. If something did not arrive

Work down this list:

1. **Is the queue worker running?** A custom message stuck on "في قائمة الإرسال" means it is not.
2. **Is the channel on for the academy?** SMS with no credentials is off by design. Check Settings → الإشعارات.
3. **Did the academy switch that notification's channel off?** A channel disabled on a template has no fallback — that is what disabling means.
4. **Does the recipient have the thing that channel needs?** No phone means no SMS, no email address means no email. The cost preview reports both counts before you send.
5. **Did the student mute it?** Automatic notifications respect a mute; custom ones ignore it.
6. **Look at the log.** Admin → Notifications → Events lists every dispatch with its per-recipient failures and the exact error.
