# ClickBee — Telegram Earn Platform

A production-oriented **Telegram Earn Platform** built in **pure PHP 8.2+ / MySQL**
for **shared hosting** (Hostinger / cPanel). Workers complete tasks, advertisers
run campaigns, and a super admin controls the platform — all installable by
uploading a ZIP and completing a browser-based wizard. **No SSH, Composer,
Docker, Node, Python or terminal required.**

---

## Highlights

- **Modular task engine** — new task types are added via a database row + one
  handler class; existing engine code never changes.
- **Webhook bot** (no polling), separated into API client, keyboard/message
  builders, update router and handlers.
- **USD internal ledger** — balances are authoritative columns mutated only
  through transactions; never recomputed from history.
- **Security first** — prepared statements everywhere, CSRF on panels, hashed
  admin passwords, webhook secret-token verification, HMAC payment callbacks,
  rate limiting, `.htaccess`-protected sensitive folders.
- **Everything configurable from the database** — no hardcoded tokens, fees,
  minimums, referral %, or task rules.

---

## Installation (no terminal)

1. **Upload** the project ZIP to your hosting and extract it into your web root
   (e.g. `public_html`).
2. **Create a MySQL database** and a user with full privileges (cPanel → MySQL
   Databases).
3. Make sure `/config`, `/logs` and `/storage` are **writable** (0755).
4. Open **`https://your-domain/install/`** and complete the wizard:
   - System check → Database → Bot (token, admin ID, URL) → Oxapay (optional)
     → Create admin → Finish.
5. The wizard creates all tables, writes `config/config.php`, registers the
   Telegram webhook, and locks itself.
6. **Delete the `/install` folder** (the installer reminds you and self-locks).
7. Add the printed **cron URLs** in cPanel → Cron Jobs.

---

## Architecture

```
core/        Kernel, autoloader, config, PDO wrapper, logger, settings cache,
             security, migrator  (App\Core)
helpers/     Validator, Money, RateLimiter                       (App\Helpers)
models/      Repositories: users, campaigns, submissions         (App\Models)
classes/
  Tasks/     TaskType (abstract), TaskRegistry, VerificationResult,
             Types/*  — the modular task engine                  (App\Tasks)
  Services/  Ledger, TaskEngine, Campaign/Deposit/Withdraw/Referral,
             Notification, State, Oxapay, Cron, Container         (App\Services)
telegram/    TelegramApi, Keyboard, Update, Menu, Bot, User/Advertiser handlers
webhook/     Telegram + Oxapay entry points
cron/        cPanel cron entry point (token-protected)
install/     Browser install wizard
admin/       Super Admin Panel (login + dashboard)
database/    schema.php, seeds.php, migrations/
```

### Task engine

Every task type extends `App\Tasks\TaskType` and is registered in the
`campaign_task_types` table (with its `handler_class`, minimum CPC, daily
budget, platform fee, verification type, pending/auto-approval hours). The
shared worker workflow — **present → act → verify → reward → next** — lives in
`TaskEngine`; each type only defines its presentation and `verify()`.

Verification decisions (`VerificationResult`) drive the `Ledger`:

| Decision            | Effect                                              |
|---------------------|-----------------------------------------------------|
| `APPROVE_AVAILABLE` | credit available balance now (Visit / Join Bot)     |
| `APPROVE_PENDING`   | hold in pending, re-checked by cron (Join Channel)  |
| `PENDING_REVIEW`    | queue manual submission (App/Review/Social)         |
| `REJECT` / `RETRY`  | no reward; show message                             |

### Money

Internal currency is **USD** (`DECIMAL(18,6)`). `Money::splitCpc($cpc, $feePct)`
derives worker reward and platform fee. Example: CPC `$0.0200`, fee `10%` →
worker `$0.0180`, platform `$0.0020`.

---

## Cron jobs

Add each to cPanel (every 5–15 minutes). The token is generated at install:

```
/cron/index.php?job=pending_verification&token=SECRET
/cron/index.php?job=auto_approval&token=SECRET
/cron/index.php?job=campaign_expiry&token=SECRET
/cron/index.php?job=cleanup&token=SECRET
```

- **pending_verification** — re-checks channel membership; confirms pending
  rewards or cancels them if the user left.
- **auto_approval** — approves manual submissions the advertiser didn't review
  within the configured window.
- **campaign_expiry** — expires ended campaigns; completes budget-exhausted ones.
- **cleanup** — drops stale conversation states.

---

## Implemented in this build

- Core kernel, autoloader, PDO layer, logger, cached settings, security,
  migrator/seeder.
- Full MySQL schema (24 tables, InnoDB/utf8mb4, indexes, foreign keys) + the
  dynamic `campaign_task_types` table.
- Telegram webhook bot with reply/inline keyboards and the FSM state manager.
- **Worker panel**: Visit Sites, Join Channels, Join Bots, More, Balance
  (deposit/withdraw/history/transactions/notifications), Referrals, Info.
- **Advertiser panel**: create-ad wizard (all task types), wallet, campaigns
  (pause/resume), statistics, pending-review approve/reject, deposit/withdraw.
- Modular task engine with 7 task types and fair task distribution.
- USD ledger with atomic balance mutations and full transaction history.
- Referral commissions (deposit + task), notifications, anti-abuse (unique
  submission index, rate limiting, budget guards).
- Oxapay deposit invoices + verified webhook crediting; manual withdrawals.
- Browser install wizard (system check → DB → bot → payment → admin → finish,
  self-locking, webhook auto-registration).
- Token-protected cron runner with per-job logging.
- Super Admin Panel: secure login (CSRF, rate-limited, session timeout, audit
  log) + live KPI dashboard.

## Roadmap (next phases)

The database and services already back these; the remaining work is primarily
admin **web UI** pages on top of the existing data layer:

- Admin management screens (users, advertisers, campaigns, deposits,
  withdrawals, per-task-type settings, broadcasts, backups, reports/exports).
- Worker "request admin review" flow for rejected manual tasks (schema in
  `reviews` is ready).
- Support tickets UI and 2FA for admins (schema/architecture ready).

---

## Security notes

- Never commit `config/config.php` (git-ignored) — it holds DB credentials and
  the app key.
- Sensitive folders ship with deny-all `.htaccess`; uploads disable PHP
  execution. Keep the `/install` folder deleted after setup.
