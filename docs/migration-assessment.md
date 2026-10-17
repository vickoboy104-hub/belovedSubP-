# Migration assessment - the ten migrations between live and the release candidate

Live runs `0935a7f7`. The candidate branch adds **ten** migrations. This file is the review of
what each one does to the rows already in the production database, which of them can be undone,
and the order the owner has to apply them in. It was written from the migration files
themselves (`database/migrations/`), not from a changelog.

**The one fact that governs everything below: not one of these migrations reads, writes,
adjusts or moves a wallet balance.** Eight create tables, one adds two nullable columns and an
index, and one rewrites JSON inside a table that is empty on first run. No migration touches
`wallets.balance`, and no migration inserts a row into `wallet_transactions` - the ledger a
customer's money is measured by. The only migrations that write to a table holding real money are
#2 (`2026_10_15_000001`) and #4 (`2026_10_15_000003_record_purchase_intents`), and between them they
add **three new nullable columns** on `orders` - an assignment owner, an assignment timestamp and an
intent token - plus one index. Not one amount, status or `user_id` is written.

## 1. The ten, in the order Laravel will run them

| # | Migration | What it does | Effect on existing rows | Reversible |
|---|---|---|---|---|
| 1 | `2026_10_13_000001_create_service_forms_table` | Creates `service_forms` (the configurable identity-form definitions) | None. The table is new and empty | Yes - drops the table |
| 2 | `2026_10_15_000001_add_assignment_to_orders_table` | Adds `orders.assigned_to`, `orders.assigned_at` (nullable) + `orders_assigned_to_status_index`; then backfills both from `orders.meta.work_state` for rows an admin had taken | Existing orders get two NULL columns; rows whose meta says `in_progress` get an owner and a timestamp. Amounts, status, user_id and every other column are untouched | Yes, and it is clean - `down()` drops the index then the columns |
| 3 | `2026_10_15_000002_retire_saved_customer_notes_fields` | **No DDL.** Rewrites the saved JSON in `service_forms`, removing the two retired customer-input fields and renaming one shipped label | Only rows already in `service_forms`. On first run against live that table is empty (created one migration earlier by #1), so **zero rows are touched** | **No.** `down()` is deliberately empty: a renamed label cannot be reconstructed once the old text is gone |
| 4 | `2026_10_15_000003_record_purchase_intents` | Creates `purchase_intents`, adds nullable `orders.intent_token` + index | Existing orders keep `intent_token = NULL`. A NULL never collides with the unique key, so no history is refused and nothing is invented | Yes |
| 5 | `2026_10_15_000004_record_document_filings` | Creates `document_filings` (the upload-each-way record) | None | Yes |
| 6 | `2026_10_16_000001_create_flutterwave_charge_claims_table` | Creates `flutterwave_charge_claims` (one unique row per gateway charge identity - the global "paid once" lock); then reads `wallet_transactions` meta and **inserts claim rows** for charges this site already banked | Reads the money table, writes only the new one. Historic credits become claimed, which is the point: a replay of an old charge can no longer pay anybody. No balance, no ledger row, no transaction status is altered | Yes - drops the table |
| 7 | `2026_10_16_000002_create_flutterwave_held_deposits_table` | Creates `flutterwave_held_deposits` (a verified transfer whose owner cannot be proved, held whole for a human) | None | Yes |
| 8 | `2026_10_17_000001_create_admin_activity_logs_table` | Creates `admin_activity_logs` + its read indexes | None | Yes |
| 9 | `2026_10_18_000001_create_wallet_settlement_links_table` | Creates `wallet_settlement_links` (an admin's explicit declaration that one specific charge was already settled by hand) | None | Yes |
| 10 | `2026_10_18_000002_create_admin_permission_grants_table` | Creates `admin_permission_grants`, then gives **every account already flagged `is_admin`** a row for each of the 27 grantable permissions, marked as the baseline | Inserts `(number of admins - Master accounts) x 27` rows. It reads `users.is_admin`; it changes nothing on `users`. No existing administrator loses a single screen on deploy - that was the owner's condition, and this is how it is met | **Treat it as no.** The table can be dropped, but the decisions in it cannot be rebuilt from anything else in the database; only the audit history would remain |

## 2. What a normal deploy will look like

Nine of the ten are `CREATE TABLE` on an empty schema. The two that write rows (#6 and #10) are
`INSERT` into tables created moments earlier in the same run, both through `insertOrIgnore`, so a
re-run cannot double them. #2 is an `ALTER TABLE orders ADD COLUMN` twice, nullable, plus a
chunked backfill that reads `meta`.

The only long-running step depends on how many rows `orders` holds (#2's index build) and how many
administrators exist (#10's insert). Both are small tables on this site.

## 3. Pre-flight reads, all of them SELECT, run before the migration and recorded

```sql
SELECT COUNT(*) FROM orders;
SELECT COUNT(*) FROM wallets;
SELECT SUM(balance) FROM wallets;                                    -- kobo; the figure to compare after
SELECT COUNT(*) FROM wallet_transactions WHERE status = 'success';   -- compare after too
SELECT COUNT(*) FROM users WHERE is_admin = 1;
SELECT COUNT(*) FROM service_forms;                                  -- #3's blast radius
SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema = DATABASE()
    AND table_name IN ('service_forms','purchase_intents','document_filings','flutterwave_charge_claims',
                       'flutterwave_held_deposits','admin_activity_logs','wallet_settlement_links','admin_permission_grants');
```

Every line above is plain SQL - `COUNT`, `SUM`, one equality on a `varchar` status, and an
`information_schema` lookup - so it runs the same on MySQL and on the box's **MariaDB 10.6.28**
with no engine-specific function in it. Nothing in this block has been executed against the live
database, and it is written to be safe to run there read-only.

**The one figure deliberately kept out of that block.** #2 backfills `orders.assigned_to` from the
JSON in `orders.meta`, so "how many rows will that touch?" is a fair question - but answering it
needs `JSON_EXTRACT`/`JSON_UNQUOTE` (or `JSON_VALUE`) against `meta`, and **no JSON function in this
file has been proved on the live engine**, so it is not put in front of the technician as if it
were. It is informational - the migration decides the same thing in PHP (`json_decode`,
`2026_10_15_000001_add_assignment_to_orders_table.php:90-95`), and the authoritative count is taken
**after** the run, below. If someone wants the number beforehand, the safe route is a
read-only `artisan tinker` pass over `orders`, not a SQL guess.

**Reads deliberately absent from the pre-flight block, and why.** `orders.assigned_to` and
`orders.intent_token` do not exist until #2 and #4 create them, so a pre-flight `SELECT` on either
column fails with *Unknown column* rather than returning 0 - a dead command that looks like a
finding. Take them **after** the run instead, where they mean something:

```sql
SELECT COUNT(*) FROM orders WHERE assigned_to IS NOT NULL;  -- #2's backfill reached this many rows
SELECT COUNT(*) FROM orders WHERE intent_token IS NOT NULL;  -- expect 0: only new orders carry an intent
SELECT COUNT(*) FROM flutterwave_charge_claims;              -- #6's backfill: one claim per distinct charge identity, so <= the successful-credit count (existing duplicates collapse to one)
SELECT COUNT(*) FROM admin_permission_grants;                -- #10's backfill, = (admins - Masters) x 27
```

After the run, the two money figures must read exactly as they did before: **same
`SUM(wallets.balance)`, same count of successful ledger rows**. If either moved, stop and do not
send customers back to the site - that is the one result no part of this plan predicts.

## 4. Order of operations, and the step that must come first

#10 is the only migration that reads configuration at run time: it asks
`AdminPermissions::masterUserIds()` which accounts are Master, and that answer comes from
`config('admin.master_user_ids')` falling back to `config('audit.owner_user_ids')` - both of
which are read from `.env`. So:

1. **Put the owner's own user id into `.env` first**, as `MASTER_ADMIN_USER_IDS=<the id the owner
   named>` and `AUDIT_OWNER_USER_IDS=<the same id>`. Verified against the live administrator list,
   that is a single account. This file deliberately does not repeat the number: the repository is
   public, and a document that names which account is Master hands an attacker the target. The id
   lives in the owner's own copy of the decision and in the box's `.env`. An empty list is the safe
   failure, not a free pass: with nothing named, the All Administrators and Activity Log screens
   answer 403 to everybody, the owner included.
2. `php artisan config:clear`. Laravel caches configuration, and a migration reads the cached
   copy - editing `.env` and leaving the cache in place runs the migration against the old answer.
3. Confirm the read-only check agrees before migrating: `SELECT id, name, email, is_admin FROM
   users WHERE id = <that id>;` must show the owner's account. If the name or the address differs,
   stop and re-ask - never move Master Admin to whichever account the database happens to offer.
4. Only then `php artisan migrate --force`.

Applied in that order, the owner's account is skipped by #10 and holds the whole set implicitly. Applied in the
wrong order it is not a disaster - the owner's account simply gets 27 baseline rows like everyone
else, which the permissions screen refuses to edit for a Master anyway - but it leaves history in
the table that does not describe how the account actually works, so do it in the right order.

`config/audit.php` names `AUDIT_DATABASE_TRIGGERS` as a switch. It is inert: no code reads it and
no trigger DDL exists in the branch. Do not set it and do not describe the release as having
database-level tamper protection. Application-level only: the `AdminActivityLog` model refuses
update and delete.

## 5. Rollback, honestly

`migrate:rollback` is not the recovery plan for this set.

- **#3 cannot be undone at all** - its `down()` is empty by design.
- **#10 can drop the table but cannot restore the decisions** in it.
- #2, #4 and #6 remove data that the application may already have written on top of (an order
  assigned to an admin, a purchase intent issued, a charge claimed). Rolling back after traffic
  means those rows are gone while the code that expects them is still running.

The recovery path is the one the checklist already specifies: **take a verified backup before the
migration, test the restore on a scratch database, and restore that backup** if the release has to
come off. Never `migrate:rollback` on production, and never `git clean` there either.

## 6. What this assessment does not clear

- It is a reading of the migration files, not a run against a copy of live data. The restore test
  in §5 is what turns this into evidence.
- The row counts in §1 depend on production numbers nobody has read from this machine
  (`users.is_admin`, `orders`, `service_forms`) - they are in §3, and they should be recorded
  before the release is approved.
- Release state is still **NO-GO** until the owner approves it. Nothing in this file is that
  approval.
