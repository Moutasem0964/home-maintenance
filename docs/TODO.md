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
- **`isReleasable` only allows `Completed`.** An admin dispute resolution of
  `release_to_technician` leaves the order `Resolved`, which this method would
  silently no-op. *When:* disputes branch — add `Resolved` to the allow-list
  (with its own test) or have `DisputeService` route the money directly.
- **`no_show` and `inspection_only` release paths.** Both pay the technician the
  inspection fee but via their own flows, not the `Completed` path. *When:*
  no-show flow / quote-rejection flow branches, each adding its allow-list arm
  and test.
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
- **Scheduled orders + appointments flow.** `book / confirm / activate / cancel`
  in a `SchedulingService`; `activateDueAppointments` + `remindUpcomingAppointments`
  cron jobs. *When:* scheduling branch.
- **dispatch_offers reassign-on-timeout flow.** `offerToNext`, expiry cron.
  *When:* dispatch branch.

## Cross-cutting hardening

- **`Model::preventSilentlyDiscardingAttributes()`** in `AppServiceProvider@boot`
  (dev/test only) so mis-typed mass-assignment keys throw instead of silently
  dropping. Bit us 3x (commision_amount, description, available_balance update).
  *When:* small standalone chore PR — expect it to surface a few existing typos.
- **Phone normalization + validation.** Store normalized `+9639XXXXXXXX`;
  validate format in the register FormRequest. *When:* auth branch.
- **Relation generics repo-wide.** Only the ~6 relations the escrow service
  traverses have `@return BelongsTo<Model, $this>` generics; the rest are still
  silenced by the `missingType.generics` ignore in phpstan.neon. Add generics to
  all relation methods and drop the ignore, so Larastan resolves every chain.
  *When:* standalone chore PR (or incrementally per branch as relations get used).
- **Model @property docblocks repo-wide.** Enum/decimal columns need
  `@property` lines so Larastan sees runtime types (done for User, Technician,
  AppSetting, Order, Wallet, Payment; do the rest as they're used in services).

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
