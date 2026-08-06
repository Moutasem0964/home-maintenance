# Deferred Work / Known Limitations

A living list of things intentionally postponed (YAGNI) or flagged during
development. Each item says **what**, **why deferred**, and **when** to do it.
Delete an item when its branch closes. This doubles as the "known limitations"
section for the project defense — deferring on purpose is a design decision, not
an oversight.

## Escrow (feature/escrow-service)

- **Inspection fee carries no commission.** `holdFunds` currently applies
  `commission_rate` to every payment type. Team decision: commission is charged
  on **repair only**, not the inspection fee. *When:* order-creation / quotes
  branch — either `holdFunds` sets `commission_amount = 0` for
  `PaymentType::Inspection`, or the caller passes the commission explicitly.
- **Skip zero-value platform ledger entry.** When `commission_amount` is 0,
  `releaseFunds` still writes a 0 platform entry + 0 increment. Harmless but
  noisy. *When:* same branch as above.
- ~~**`isReleasable` only allows `Completed`.**~~ DONE (feature/disputes):
  `isReleasable` now allows `Completed` *and* `Resolved` (both gated on
  `! hasOpenDispute`), so an admin `release_to_technician` resolution settles.
- ~~**`no_show` / `inspection_only` release paths.**~~ DONE. Both `NoShow` and
  `InspectionOnly` are in the `isReleasable` allow-list; client-no-show and
  quote-rejection/expiry now release the inspection fee to the tech (releaseFunds
  early-returns when nothing is held, so orders with no fee are unaffected). NOTE:
  these reuse `settlePartial`/`releaseFunds`, so they inherit the (currently applied)
  inspection commission; when the "inspection carries no commission" fix lands, these
  inherit it automatically.
- **Multi-payment release test.** Current release test has one held payment.
  Add a test with inspection + repair held together to prove the loop pays the
  sum minus commission. *When:* after order-creation exists to produce two holds.
- **Double-release idempotency test.** Release is already idempotent (the
  `status = Held` filter empties on the second call), but there's no test
  asserting it. Add one. *When:* escrow-cron branch.
- **`holdFunds` idempotency race.** Two truly-simultaneous identical requests:
  the second dies on the unique `idempotency_key` index instead of returning the
  existing payment. Catch the unique-violation and return the existing payment.
  *When:* when wiring the HTTP layer (order-creation), where real concurrency
  appears.

## Scheduling / dispatch (later branches)

- **Cron churn on settled orders.** After release, the order stays `Completed`
  forever, so the release cron keeps re-selecting it (finds no held payments,
  does nothing). Safe but wasteful. *When:* escrow-cron branch — add a settled
  marker or a "has held payments" filter to the query.
- ~~**Scheduled orders + appointments flow.**~~ DONE (feature/scheduling-appointments):
  `SchedulingService::book` (on accept of a scheduled order → confirmed appointment,
  order `Scheduled`), `activateDue` + `remindUpcoming` crons, and scheduled dispatch
  offers only to time-conflict-free technicians. Still deferred within this area:
  (a) ~~appointment cancellation~~ DONE — `SchedulingService::cancelFor` frees the
  slot on client-cancel / technician-withdraw (feature/cancellation-noshow); (b) the
  **pending→confirmed handshake** — appointments are created `Confirmed` directly on
  accept (the `Pending` state is reserved for a future explicit client-confirm step);
  (c) **repair/followup appointments** — only the first `inspection` visit is booked;
  a multi-visit job doesn't yet spawn follow-on appointments.
- **Nearest-tech is a squared-euclidean approximation.** `AssignmentService`
  orders by `(lat-x)^2 + (lng-y)^2` (portable, no DB math funcs). Fine at city
  scale; swap for haversine + the bounding-box prefilter (SRS note 13) when the
  tech pool grows. *When:* performance/scale pass.
- ~~**Technician withdraw-after-accept + reliability flagging.**~~ DONE. Withdraw
  (`POST /orders/{id}/withdraw`) frees the slot, returns the order to Pending and
  re-dispatches. Reliability failures (withdraw / no-show) now raise a **`technician_flags`**
  row (captured with the tech id before withdraw nulls `technician_id`) for admin
  assessment — the system flags, the admin decides (SRS-faithful: no auto-sanction).
  Admin: `GET /admin/technician-flags` (open queue), `POST /admin/technician-flags/{id}/review`
  (dismiss + note), and `POST /admin/technicians/{id}/suspend|ban` which also auto-resolve
  that tech's open flags. *Deferred:* an `acceptance_rate`/rating impact, flagging
  disputes lost by the tech, and admin reinstate beyond re-`approve`.

## Quotes (feature/quotes)

- **Add-on quotes.** `QuoteType::Addon` exists (extra fault found mid-job) but
  only `initial` quotes are wired. Needs its own send/approve path that tops up
  the held repair amount. *When:* mid-job / addon branch.
- **inspection_only money is status-only.** Reject/expire move the order to
  `inspection_only` but do not yet release the inspection fee to the technician —
  that's the existing escrow `no_show / inspection_only release paths` TODO.
  *When:* escrow inspection-release branch.
- **Evidence photos + `waiting_for_parts`.** The `evidences` table and the
  `WaitingForParts` order state aren't used yet. *When:* work/closure branch.
- **FR-A2 anomaly is enforced but not surfaced.** A justification is required
  past the threshold, but there's no admin alert/dashboard for anomalous quotes.
  *When:* admin/dispute dashboard branch.

## Disputes (feature/disputes)

- **Closure review window is one clock.** The review/auto-complete window reuses
  `closure_code_ttl_minutes` (so code validity == time-to-dispute-or-confirm ==
  auto-complete deadline, all driven by `closure_expires_at`). If the business ever
  wants the auto-complete window to differ from code validity, that needs a second
  timestamp column + setting. *When:* only if product asks; single clock is the default.
- **Auto-completed orders weaken the closure-code guarantee by design.** A
  `closure:auto-complete` order was completed without a client-confirmed code, so
  the `ClosureAutoCompleted` event (not `ClosureVerified`) and null
  `closure_verified_at` flag it for the fraud/dispute board to weight differently.
  *When:* admin/dispute dashboard branch.
- **`warranty_order` resolution arm.** `DisputeResolution::WarrantyOrder` exists
  but `resolve()` throws "not implemented" for it — it needs to spawn a new
  (free) order for the same tech and decide what happens to the held money.
  *When:* warranty branch (pairs with reviews + warranty_until below).
- **Partial refund is FIFO, not proportional.** `settlePartial` refunds the
  admin's amount from the held payments oldest-first (each payment fully refunded,
  fully released, or split once) so there's no cross-payment rounding. If the
  business wants the refund spread *proportionally* across inspection + repair,
  revisit. *When:* only if product asks for it — FIFO is the documented default.
- **No tech counter / escalation flow.** A raised dispute goes straight from
  `open` to an admin decision; `DisputeStatus::UnderReview`/`Escalated` and any
  technician rebuttal step aren't wired. *When:* dispute-workflow branch.
- **Admin resolve is gated by an inline role check.** Like the technician-approve
  endpoint, `resolve` uses `abort_unless(role === Admin)`; folds into the Filament
  admin panel + `role:admin` middleware later. *When:* admin-panel branch.

## Reviews & warranty (feature/reviews-warranty)

- ~~**Reviews + warranty.**~~ DONE: client reviews a completed/resolved order once
  (`POST /orders/{id}/review`), `warranty_until` is stamped at completion from the
  approved quote's `warranty_days`, and the client can claim the warranty
  (`POST /orders/{id}/warranty-claim`) to spawn a same-tech zero-labor child order.
- **`price_anomaly_flag` is derived from a low price rating** (`price_rating <= 2`),
  not from the quote's guide-price anomaly computed in the quotes slice. If the
  board wants it tied to the *quote* anomaly instead, thread that through. *When:*
  admin/dispute dashboard branch.
- **One warranty visit per order.** `claim` blocks a second warranty child. If a
  warranty fix itself fails and needs another visit, that's not handled. *When:*
  only if it comes up in practice.
- **Warranty order has no lifecycle beyond completion.** The spawned child starts
  `in_progress` with the same tech and completes via the normal closure flow, but
  there's no reminder/scheduling for it and its own completion doesn't re-stamp a
  fresh warranty (no quote → 0 days). *When:* scheduling branch, if needed.
- **`warranty_order` dispute resolution is now buildable.** `DisputeResolution::WarrantyOrder`
  can reuse `WarrantyService::claim` to spawn the visit instead of moving money.
  *When:* wire it into `DisputeService::resolve` (currently throws "not implemented").
- **Reviews feed `technicians.rating_avg`.** The Review model comment notes a
  background job to average ratings onto the technician; not wired. *When:*
  technician-profile / ratings branch.

## Closure & release (feature/closure)

- **Cron churn on settled orders.** `releaseSettledOrders` filters on
  `whereHas(payments, held)` so released orders are skipped, but a fully-settled
  completed order still matches the status+deadline predicate each run. A settled
  marker would trim it. *When:* same as the existing escrow-cron churn item.

## Cross-cutting hardening

- **`Model::preventSilentlyDiscardingAttributes()`** in `AppServiceProvider@boot`
  (dev/test only) so mis-typed mass-assignment keys throw instead of silently
  dropping. Bit us 3x (commision_amount, description, available_balance update).
  *When:* small standalone chore PR — expect it to surface a few existing typos.
- **Phone normalization + validation.** Store normalized `+9639XXXXXXXX`;
  validate format in the register FormRequest. *When:* auth branch.
- **OTP phone verification (SRS UC-22 registration, UC-21 recovery).** Its own
  slice after basic auth. Needs: an `SmsSender` interface with a `LogSmsSender`
  (dev) + `Notification::fake()` (tests) and a real gateway swapped via config
  later; a code store (cache/table) with 5-min expiry; per SRS UC-21 rate limit
  (3 wrong attempts → 15-min lockout) + resend throttle. Then gate login on a
  non-null `users.phone_verified_at` (column added in the auth branch, left null
  by register). *When:* feature/phone-verification, immediately after feature/auth.
- **Real SmsSender driver (last step of the auth work).** Build + test the whole
  OTP flow with LogSmsSender (dev) / FakeSmsSender (tests) first. Candidates for
  live delivery, each a ~15-line `SmsSender` implementation selected via
  `config('sms.driver')`, credentials in `.env` (never committed): **SMS Chef**
  (real SMS via own Android SIM, free tier ~100/day — best SRS fit) or **OpenWA**
  (OTP over WhatsApp, self-hosted). No telecom API needed. Verification without a
  gateway: tests assert via FakeSmsSender; dev reads the code from
  `storage/logs/laravel.log` via LogSmsSender.
- **Relation generics repo-wide.** Only the ~6 relations the escrow service
  traverses have `@return BelongsTo<Model, $this>` generics; the rest are still
  silenced by the `missingType.generics` ignore in phpstan.neon. Add generics to
  all relation methods and drop the ignore, so Larastan resolves every chain.
  *When:* standalone chore PR (or incrementally per branch as relations get used).
- **Model @property docblocks repo-wide.** Enum/decimal columns need
  `@property` lines so Larastan sees runtime types (done for User, Technician,
  AppSetting, Order, Wallet, Payment, DispatchOffer, Quote; do the rest as used).

## Technician onboarding (feature/technician-onboarding)

- **No admin management or admin auth yet.** `admin/technicians/{id}/approve` is
  gated by an inline `abort_unless(role === Admin)`, and admins exist only via
  `AdminSeeder` (a seeded admin logs in through the normal endpoint to get a
  token). No admin registration/management API on purpose. *When:* Filament
  admin-panel branch — brings admin UI + web session auth for all admin actions.
- **Role check is inline, not middleware.** Role gates live in the controllers.
  Extract to `role:admin` / `role:technician` middleware once those routes grow.
  *When:* dispatch branch or a chore PR.
- **Availability allowed while pending.** A `pending` technician can set
  `is_available = true`; dispatch gates on `status = Active`, so it's inert until
  approval. Decide whether to reject going-available before approval for clearer
  feedback. *When:* dispatch branch.
- **KYC document upload deferred.** Encrypted `id_doc/selfie/criminal_record/proof`
  columns exist but no upload endpoint — needs file storage. *When:* its own slice.
- **Reject / ban / probation transitions + `daily_order_limit`.** Admin-driven
  approve (→active), suspend (→probation + `probation_daily_limit` + offline) and ban
  (→banned + offline) are wired. Still missing: rejection→pending and a proper
  reinstate flow (re-`approve` currently doubles as reinstate). *When:* admin-panel branch.

## Product / team decisions (not code)

- **Dual-role user (client + technician).** Schema already allows it; decide
  officially and document in SRS.
- **Part classification: 2 vs 3 tiers.** SRS says عادية/ممتازة (2). Confirm
  whether a third tier is wanted; if so, one enum case + SRS update.
- **Dispute windows for `no_show` / `inspection_only`.** Should a client be able
  to dispute these? Product call.

## Documentation debt

- **SRS text is still v1.0** — hasn't been updated to match the v2 diagrams
  (dispatch_offers, appointments, assignment model, closure-code server-side).
- **Class diagram PNG** not regenerated for v2 (add SchedulingService, the escrow
  services layer).
