# Admin screens on a phone - what was checked, what was changed, what is still open

Branch `codex/identity-first-jhtech-experience`, 2026-10-17. Part 3 of the owner's brief.
Production was not touched: no live database, no provider call, no deployment.

## 1. What was asked for

Every admin page had to be usable on a phone, and specifically:

- transaction rows readable at 320, 360, 375, 390, 414 and 768 px wide, plus desktop;
- View Details / Expand, Copy Reference, long references that wrap instead of pushing
  the layout sideways, and sensitive figures masked by default;
- navigation usable with one hand, tap targets you can actually hit, nothing running off
  the screen, pagination you can reach, dialogs that scroll instead of being cut off,
  sticky headers that do not cover content;
- the Transaction Explorer answering every filter in the database, not in the browser.

## 2. How this was verified, and what that does NOT prove

The browser in this workspace is a hidden tab: screenshots fail and `requestAnimationFrame`
never fires, so no pixel measurement was taken here. What was done instead:

1. **Static analysis of the shipped CSS** - `resources/css/app.css` and the built
   `public/build/assets/app-*.css` were read rule by rule: which element scrolls, which
   ancestor clips, which breakpoint applies at each width, what minimum size each control
   is given.
2. **Rendering the real pages** through Laravel's test layer against an in-memory database
   seeded with invented data: 30-character references, ₦ amounts with thousands separators,
   two-word-plus names, phone/NIN/BVN digits inside descriptions, 25+ rows so the pager has
   to work. Those tests assert on the HTML the pages actually produce.
3. **`artisan serve` on 127.0.0.1:8000** over a database of invented rows, signed in as the
   owner account and read with a phone user-agent, so every admin screen is confirmed to return
   its own page rather than an error. It is also there for the owner to open on a real handset.
   The synthetic database and the builder live outside the repository, in
   `scratch/mobile-audit/` of the Qoder workspace, and are rebuilt with:

   ```sh
   # 7 members, 20 orders, 12 ledger rows, 6 trail rows, 3 held deposits,
   # 2 identity requests in the manual queue. All figures invented.
   "C:/laragon/bin/php/php-8.3.28-Win32-vs16-x64/php.exe" scratch/mobile-audit/build-preview.php

   # Serve it with every provider key blanked, so nothing on this screen can
   # reach a live gateway or send mail, and the owner id set so the trail is readable.
   PHP_CLI_SERVER_WORKERS=4 \
   DB_DATABASE="<workspace>/scratch/mobile-audit/audit.sqlite" \
   AUDIT_OWNER_USER_IDS=1 \
   GSUBZ_API_KEY= JHTECH_API_KEY= BVN_API_KEY= NIN_API_KEY= \
   FLUTTERWAVE_SECRET_KEY= FLUTTERWAVE_PUBLIC_KEY= FLUTTERWAVE_ENCRYPTION_KEY= FLUTTERWAVE_SECRET_HASH= \
   MAIL_MAILER=log \
   "C:/laragon/bin/php/php-8.3.28-Win32-vs16-x64/php.exe" artisan serve --host=127.0.0.1 --port=8000
   ```

   Sign in as `preview-admin@example.test` / `preview-only`. That account is the only admin in
   the file; the six members exist to be listed, not to be signed in as. **Read-only: the
   provider keys are blank on purpose, so do not use a paid service from this screen.**

So the layout claims below are **reasoned from the rules that the browser will apply**, and
the content claims are **proved by executed tests**. The one thing nobody has done is look at
a rendered phone screen. That is the owner's step, and it is listed in section 6.

What step 3 did establish, because it is worth separating from the layout claims: eighteen
admin screens return HTTP 200 when fetched with a phone user-agent against this synthetic data -
`/admin`, orders, users, one customer profile, manual-orders, one open manual request,
wallet-transactions, wallet-stats, activity-log, administrators, held deposits, notifications,
support chats, services, settings, website-editor, broadcast and one issued-codes page. Nothing
500s, and the masking and Copy markers are present in the served HTML, not only in the test
double. The opened request page really does show `11223344556` whole while the queue shows
`*******4556`, which is the split section 4.5 describes, seen on a served page.

One screen was not exercised this way: `/admin/services/{service}`, the form editor. It 404s for
a synthetic service because `ServiceFormsController::edit()` refuses any service without a stored
form definition, and the invented rows in the preview have none - that guard is the route working
as designed, not a fault. Its layout was read statically instead (section 3.1).

## 3. The two list generations, and which page uses which

There are deliberately two kinds of list on the admin surface, and the difference is not
cosmetic:

| Generation | Markup | Used by | Why |
| --- | --- | --- | --- |
| Read board | `x-records-table` (`components/records-table.blade.php`) - flat table at 768px and up, `+` cards below it, expandable detail, Copy chips | Transaction Explorer, customer profile ledger + orders, Wallet Statistics, Activity Log, All Administrators, the customer's own `/wallet/transactions` and `/vtu/orders` | A board that only reads can afford one markup for both shapes, and it gets the `+` expand and Copy chip for free |
| Work card | `app-record-card` - one card per row that carries its own forms | User Accounts, Manual Requests, Held Deposits | Each row hosts actions (set a discount, fund a wallet, credit this deposit). Those cannot live behind an expand that is also a read control, and a decision card must not be hidden until tapped |

Both generations wrap long values (`.records-card-value { overflow-wrap: anywhere }`,
`.app-record-card { overflow-wrap: anywhere }` - inherited by the cells) and both collapse to
one column on a narrow phone (`.app-record-grid` at max-width 420px, `.records-card` is single
column by construction).

### 3.1 Every admin screen, and what was found on it

The brief named fourteen pages. These are all of the admin screens reachable by GET on this
branch, with the one thing that matters for a phone:

| Screen | Shape | Phone finding |
| --- | --- | --- |
| `/admin` (Dashboard) | metric tiles + 3 hand-rolled tables | checked, see 4.7 - the cost table scrolls at `min-w-[560px]`, the two activity lists are cards below 768px |
| `/admin/wallet-transactions` (Transaction Explorer) | read board | pager fixed, 44px controls, server-side filters |
| `/admin/orders` (All Orders) | work card, both shapes | masked + Copy chips added |
| `/admin/users` (User Accounts) | work card | masked; still five forms per row (open item 2) |
| `/admin/users/{id}` (customer profile) | read board for ledger/orders, form for edits | board is masked; the editable phone and account fields hold the whole value on purpose - that page is the one the agent goes to for it |
| `/admin/manual-orders` (Manual Requests) | work card | identity chips masked, search still matches the full figure |
| `/admin/manual-orders/{id}` (one request) | detail page | unchanged: prints "What the customer submitted" whole |
| `/admin/wallet-stats` | read board | 44px controls, wrapping pager |
| `/admin/wallet-deposits/held` | work card | Copy chips added, account number deliberately left whole |
| `/admin/activity-log` | read board (14 columns) | already masked; `--records-min` gives it room to scroll instead of collapsing |
| `/admin/administrators` | read board | 44px controls, wrapping pager |
| `/admin/orders/{order}/keys` (issued codes) | one-column cards (`sm:grid-cols-4`) | nothing to fix - codes are alphanumeric and the grid is single-column below 640px |
| `/admin/broadcast` | form + 3 metric tiles (`grid-cols-2 sm:grid-cols-3`) | checked; two short figures per row at 320px, no wide content |
| `/admin/notifications` | stacked cards | checked; server-written titles, one link per card, uses the shared pager |
| `/admin/support/chats` | two panes, `grid-cols-1 lg:grid-cols-12` | checked; each pane scrolls inside itself, bubbles are `max-w-[85%]` with `break-words`, message text is escaped |
| `/admin/services`, `/admin/services/{service}` | cards (`grid-cols-1 sm:grid-cols-3`) | checked; the option editor stacks to one column |
| `/admin/settings` | long form + 2 hand-rolled tables | sticky bar verified (4.4); tables scroll at `min-w-[720px]` / `min-w-[520px]` |
| `/admin/website-editor` | cards (`grid-cols-1 sm:grid-cols-2 lg:grid-cols-4`) | checked; no fixed-width surface, no iframe to overflow |

## 4. What was found and fixed in this pass

### 4.1 The pager was unreachable on a phone (fixed in the previous pass, recorded here)

Laravel's own paginator emits one unwrapping line of small buttons. The mobile shell sets
`.reference-app-shell { overflow-x: hidden }`, so the buttons past the screen edge were not
just off-screen - they were unreachable, because there is nothing to scroll them into view.
Page 2 and beyond could not be opened on a phone at all.

Fix: `Paginator::defaultView('components.pagination')` in `AppServiceProvider` points every
`->links()` call in the site (14 of them) at `components/pagination.blade.php`, whose buttons
wrap, and `.pager-button` is a 2.6rem square (2.75rem below 768px). `TransactionExplorerTest`
now fails if the framework pager comes back on any of the boards.

Seen on a served page rather than in a test: `/admin/wallet-transactions?per_page=5` against the
synthetic ledger emits seven `.pager-button` elements inside a wrapping `.pager-buttons` row. The
other boards page at a fixed size and the preview does not hold enough rows to split them, so
their pager is covered by the tests, not by this fetch.

### 4.2 Tap targets below 44px (fixed)

Measured from the rules that apply at each width, before this pass:

| Control | Was | Now (max-width 767px / 640px) |
| --- | --- | --- |
| `.records-search`, `.records-select`, `.records-search-button` (a board's filter box, page size and Search) | 38px | 2.75rem = 44px |
| `.records-expand` (the `+` that opens a row) | 38.4px | 2.75rem = 44px |
| `.records-copy` (Copy Reference chip) | 32px | 2.75rem tall, wider padding |
| `.app-modal-btn` (Confirm / Cancel in every dialog) | 39.2px | 2.75rem = 44px |
| `.app-modal-close` (the cross in a dialog) | 36px | 2.75rem = 44px |
| `.reference-nav-link` (the drawer's links) | ~42px from padding | min-height 2.75rem, flex-centred |
| `.mobile-topbar-icon` (notification bell, profile menu) | 40px | `h-11 w-11` = 44px |

The hamburger already was 44px, `.btn-primary` / `.btn-danger` / `.btn-outline` already cleared
it (py-3 plus a 20px line), and `.mobile-profile-menu-item` likewise.

One extra reason the filter box changed: iOS Safari **zooms the page** when a field smaller
than 16px text takes focus. Because the mobile shell hides sideways overflow, a zoomed board
cannot be dragged back into view. `.records-search` is now `font-size: 1rem` below 768px, so
typing in it no longer throws the layout.

### 4.3 Dialogs that could not be read (already safe - verified, not changed)

`.app-modal-panel` carries `max-height: min(82vh, 620px)` and `overflow: auto`. The confirm
sheet's markup also asks for `overflow-hidden`, but in the built stylesheet
`public/build/assets/app-k_-jzmjF.css` the `.overflow-hidden` utility sits at byte 29,533 and
`.app-modal-panel` at byte 76,738 - 47 KB later, at the same nesting depth, with the same
specificity - so the panel's `overflow: auto` is the rule the browser applies. (These offsets
belong to that one build; what has to stay true is the *order*: the panel after the utility.
The `@tailwind utilities` directive is on line 5 of `resources/css/app.css`, so every utility
expands near the top of the file and the un-layered component rules written below it -
`.app-modal-panel` among them - are emitted afterwards and win the tie.) At 320x568 the sheet is bounded at 465px and
scrolls; the overlay is
`fixed inset-0 flex items-center justify-center p-4`, so it stays centred. Esc, the dimmed
backdrop, the cross and Cancel all close it, and Cancel/Confirm stack full-width below 640px.

### 4.4 Sticky header covering content (verified, not changed)

`position: sticky` exists on exactly one admin page: the settings search-and-section bar,
`sticky top-24 z-20`. The site header is `fixed top-0 z-40` and 64px tall, so the sticky bar
rests 32px below it and never hides anything behind it. All 21 `#group-*` section anchors carry
`scroll-mt-44` (176px), so jumping to a section lands the heading below both bars.

### 4.5 Sensitive figures on the boards that print many rows at once (fixed)

The Explorer and the customer profile ledger already masked on display, and the audit
recorder has always masked on write. Reading the remaining admin boards found three that
printed whole figures for many people on one screen:

| Page | What was printed in full | Now |
| --- | --- | --- |
| `/admin/users` (User Accounts) | every customer's phone **and** virtual account number | last four digits in both the phone cards and the desktop table |
| `/admin/orders` (All Orders) | the number that was topped up (`meta.phone`), `meta.customerID`, `customer_ref` - which for VTU *is* a phone number | last four digits; the plan, meter type and the pin the customer paid for stay readable |
| `/admin/manual-orders` (Manual Requests) | **NIN and BVN** in the queue chips, plus the customer's phone | last four digits |

A fourth turned out to be a real gap rather than a list page, and it was only visible once
a row was put into the table behind the recorder: **`/admin/activity-log` prints `changes`,
`description` and `reason` exactly as stored.** Write-time masking is the only protection the
trail has, and it binds one PHP class - a row inserted by a migration, an import, or anyone
holding the database password reaches the screen whole. The view now applies the same
`SensitiveFigures::mask` on the way out. That cannot hide anything the writer meant to keep,
because it is the same rule; it only makes the two layers agree.

**One column is deliberately exempt: Reference.** `AdminActivityRecorder::reference()` stores
it whole by design ("a wallet reference is the useful thing to search on"), so masking it on
display would make the screen disagree with the writer and put the trail's only searchable key
out of reach. A gateway id that ends in digits - `FLW-MOCK-VOU-7788112200` - therefore stays
readable here and is masked nowhere else on this page. The test pins both halves.

The masking is display-level only. Nothing was changed in storage, in the ledger, or in the
audit trail (which has always masked). Search still matches the whole number: the digits are
hidden after the row is found, not before. And the full figure is still where the work happens -
`/admin/users/{id}` holds the phone and account number in editable fields, and
`/admin/manual-orders/{id}` prints "What the customer submitted" whole. Seven tests in
`SensitiveListTest` pin that split - the queue hides `12345678901` while the request the agent
opens shows it, a search for the full number still finds the row, and reading all four boards
moves no money - so the rule cannot drift to the wrong half of the screen again.

Each of those three pages now says so on itself, in one line under the filter, so an agent is
not left thinking the number is missing.

One existing test had to change, and that is worth stating rather than glossing:
`AdminManualQueueNavigationTest::test_the_waiting_tab_shows_the_details_needed_to_run_the_job`
asserted `assertSee('12345678901')` on the queue - it was a written record of the old
behaviour. It now asserts `*******8901` is shown, the whole figure is not, and the
alphanumeric tracking id still is; its second half, that a search for the full BVN still
finds the request, is unchanged and still passes. No other test in the suite expected a whole
figure on a list.

**Deliberate exception:** `/admin/wallet-deposits/held` still shows the payer's virtual account
number in full. That number is the evidence the decision turns on. Masking it would leave an
agent choosing whose money to credit from four digits, which is exactly the failure the hold was
built to prevent. The reason is written on the page.

### 4.6 Copy Reference where a reference has to leave the screen (added)

`/admin/orders` (both shapes) and `/admin/held-deposits` now carry the same
`data-copy-text` chip the records boards use, for the provider reference, the gateway charge id
and our own `tx_ref`. The handler is already delegated on `document` in `app.js`, so no new
script was needed.

The chip copies the field as stored, and none of those three fields is masked for display, so
what is copied is what is shown. That matters because the mask is a digit rule, not a "looks
like a reference" rule: it bites any run of nine or more digits, and it counts a space or a
hyphen as still being inside the run. So `GW-LONG-REFERENCE-20261017-000451` printed on a card
is whole, while the same string inside a free-text note would come out as
`GW-LONG-REFERENCE-**********0451`. Anything that has to stay exact and searchable - the trail's
`reference` column, the copied identifiers - is therefore kept out of the mask rather than
passed through it and hoped to survive.

### 4.7 Wide tables and their parents (checked, per the "don't just add overflow-x-auto" rule)

The desktop table inside both list generations is wrapped in a scroll container
(`.records-table-wrap { overflow-x: auto }` at 768px and up; `hidden md:block overflow-x-auto`
on the work-card pages) and the mobile shell's `overflow-x: hidden` is on a different element -
an ancestor that clips *its own* overflow, not a descendant's scroll container. So the boards
scroll inside their box and the page never grows past the viewport. Below 768px the table is
`display: none` and the cards are shown, which is why a 13-column Explorer board needs no
horizontal scroll on a phone at all.

Two hand-rolled tables were left alone after checking them rather than by assumption:

- `/admin/dashboard` - the provider-cost table is `overflow-x-auto` with `min-w-[560px]`; the
  two recent-activity lists are cards on mobile and a table at `md`.
- `/admin/settings` - the GSUBZ plan table is `min-w-[720px]` inside `overflow-x-auto`, and the
  error-code table is `min-w-[520px]`, so three text columns are not crushed into 320px.

`.records-table { min-width: var(--records-min, 40rem) }` with `$minWidth = min(96, max(36,
columns * 6))` per board is what gives the audit board (14 columns) room to scroll instead of
collapsing.

## 5. What the pages are now expected to do at each width

| Width | What a list page shows | What a dialog shows |
| --- | --- | --- |
| 320px | one-column cards, `+` expand at 44px, wrapping pager, filter controls at 44px and 16px text | sheet at full width minus 32px, bounded to 82vh, scrolls inside, buttons stacked full-width |
| 360 / 375 / 390 / 414px | as above (`.app-record-grid` is still one column up to 420px, two from 421 to 767px) | as above |
| 768px | flat table with its own horizontal scroll, cards hidden, pager above it | sheet at its max width, buttons back in a row |
| Desktop | table, filter toolbar, `Total Record(n)` count, fixed rail and header | centred, max 27rem (30rem for the confirm sheet) |

A four-word name, a `GW-LONG-REFERENCE-20261017-000451` and a `₦1,234,567.89` amount were put
through these pages in the tests. What keeps them inside the card rather than widening it is
`overflow-wrap: anywhere`, which is on both card generations (`.records-card-value` and
`.app-record-card`, and the latter inherits down to the cells), plus Tailwind's `break-all` on
the ten cells that hold a whole identifier on one line: the dashboard ledger reference, the two
held-deposit ids, four cells on one manual request, and one each on the issued-codes page, the
order board and the customer profile. Four more are on the customer's own NIN, BVN and receipt
pages. Nothing else on the admin surface relies on it.

## 6. Still open

1. **Look at it on a real phone.** Everything above is rules and rendered HTML. The push-drawer
   animation, the sheet's scroll feel and the density of the work cards on a 320px screen are
   judgement calls only a screen can settle. `artisan serve` on this box with the synthetic
   database is ready for that.
2. **The two generations.** User Accounts still carries five forms inside each row on mobile,
   which is a long card and a mis-tap risk next to `Fund Wallet`. Moving those actions behind a
   link to the customer's own profile would make it a read board like the others - but that
   page writes money and permissions, so it is the owner's call, not a drive-by refactor.
3. **Nothing here is a production change.** The release stays NO-GO: C1 credential rotation,
   the owner user ID still unnamed, `admin_activity_logs` not migrated on production, and the
   Part 4 evidence set unfinished.
