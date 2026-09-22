# Runbook — turning SMS on (ZADX)

Ref: EDU-OPS-004. Owner: whoever holds the production `.env`.

SMS is off unless two separate things are true. Both fail silently, and they fail
differently, so check them in this order.

1. **The deployment sends at all** — `SMS_DRIVER` in the production `.env`.
   Unset, it falls back to `log` and every message is written to
   `storage/logs/laravel.log` as an `[SMS]` line. The app looks healthy. No phone
   rings. An unrecognised value falls back the same way, so a typo is
   indistinguishable from a missing line.
2. **The academy has credentials** — each academy is its own ZADX app with its
   own key pair, stored encrypted on its `notification_channel_settings` row. An
   academy that has not filled this in simply has SMS off: nothing is attempted,
   nothing fails, and in-app notifications keep working.

---

## Turning it on

### Step 1 — the deployment

```
SMS_DRIVER=zadx
```

```bash
php artisan config:clear && php artisan config:cache && php artisan queue:restart
```

`queue:restart` is not optional. `SendOtpJob` runs on the `otp` queue
(`docs/queues.md`); a long-lived worker keeps the old cached driver in memory
until it is cycled, so the env change appears to do nothing.

No gateway credentials go in `.env`. Only `SMS_ZADX_BASE_URL`, as a fallback for
an academy that stored none.

### Step 2 — the academy

The teacher enters the academy's own keys at **Settings → الإشعارات → بوابة
الرسائل النصية**, or by `PUT /teacher/sms-settings`:

```json
{ "enabled": true, "provider": "zadx", "api_key": "pk_…", "api_secret": "sk_…", "sender_id": "" }
```

`api_secret` is write-only — `GET` returns `has_secret`, never the value. Leave
`sender_id` blank unless you know the academy's own approved sender: ZADX rejects
one that is not assigned to the app (`403 sender_id_not_allowed`), while omitting
it uses the app's default.

**ZADX apps are provisioned by ZADX's admin team, not self-service.** Onboarding a
new academy starts with a request to them, before anyone touches this screen.

### Step 3 — prove it

```bash
php artisan sms:probe --tenant=<slug-or-host> --phone=<tester-mobile> --otp
```

Sends one real message and prints the gateway's verdict, the credits before and
after, and the message row ZADX wrote. Drop `--otp` to probe the plain-text path
the notification engine uses — the two are separate endpoints and can fail
independently.

Then the acceptance check proper: register a test student on the launch academy
with SMS verification on, and confirm the code reaches a handset within 60
seconds and appears in the ZADX portal's message log.

---

## Reading a failure

The probe prints ZADX's own error code. Each one means a different fix.

| Code | What it means |
|---|---|
| `SMS is not enabled for this tenant` | Ours, not ZADX's. The academy has no active `sms` row — step 2 was not done. |
| `SMS is not fully configured for this tenant` | Ours. The row exists but `api_key`/`api_secret` is blank. |
| `invalid_credentials` | The key pair is wrong or was rotated. Re-copy from the ZADX dashboard. |
| `app_inactive` | ZADX suspended or revoked the app. Contact them; nothing to fix here. |
| `sender_id_not_allowed` | The stored `sender_id` is not assigned to this app. Clear it to use the app default. |
| `mode_not_allowed` | The app is provisioned for OTP only, or SMS only. Whichever endpoint you probed is not enabled for it. |
| `quota_exhausted` / `no_active_subscription` | Out of credits, or no plan. Billing, not code. |
| `rate_limited_phone_minute` / `_hour` | ZADX allows **1 send per minute and 5 per hour to one number**. Tighter than our own `throttle:otp` (5/min per identifier), so our API can accept a resend ZADX then refuses. Wait, do not retry into it. |
| `missing_idempotency_key` | A bug on our side — every write must carry one. See `ZadxClient::idempotencyKey`. |
| `idempotency_conflict` | The same key was reused with a different body. Keys live 24h. |
| `too_many_segments` / `message_too_long` | Arabic fits 70 characters per segment, English 160; max 6 segments, 800 characters. ZADX also appends the app name as a mandatory last line of every plain-text message, which counts. |

Two results that are **not** failures and must not be retried:

- **`pending_verification`** — ZADX never got provider confirmation. Credits stay
  reserved, the message may still arrive, and ZADX will not resend it. Check the
  handset before sending anything else to that number.
- A 2xx with `status: queued` — accepted, not yet delivered. The portal's message
  log is the source of truth.

## Rolling back

```
SMS_DRIVER=log
```

```bash
php artisan config:clear && php artisan config:cache && php artisan queue:restart
```

Messages go back to the log. Nothing else changes; stored credentials are
untouched. `SMS_DRIVER=connekio` switches an academy back to WE Business SMS
instead, if it still has WE credentials stored.

## Two things the move to ZADX changed

- **OTP wording is now the gateway's.** ZADX's `/otp/send` renders its own
  approved template (`كود التحقق الخاص بك هو: {otp}`) and ignores inline text, so
  an academy's `account.otp.requested` copy no longer applies to codes. It still
  applies to every other SMS. This was taken deliberately: the OTP endpoint is
  exempt from ZADX's content screening, and on the plain-text endpoint one
  screening violation costs a strike plus 10% of the academy's quota, with the
  third strike suspending the app. A login code is the last message that can
  afford that risk.
- **OTP length is constrained.** ZADX accepts 4 to 6 digits. `OTP_LENGTH` outside
  that range fails every code.

---

## Log

| Date | Change | By |
|---|---|---|
| 2026-09-22 | `SMS_DRIVER=zadx` on production; ZADX replaces WE Business SMS as the live gateway (EDU-OPS-004). WE/Connekio driver retained for rollback. | |
