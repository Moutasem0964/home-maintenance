# Money & Order Flows

GitHub renders these diagrams automatically. They show WHERE each
`EscrowService` method is called in the order lifecycle.

## Happy path: urgent order → repair → release

```mermaid
sequenceDiagram
    autonumber
    participant C as Client App
    participant API as API
    participant ASG as AssignmentService
    participant ESC as EscrowService
    participant LED as Ledger (wallet_transactions)
    participant T as Technician App
    participant CRON as Scheduler

    C->>API: POST /orders (UC-01)
    API->>ESC: holdFunds(order, inspection_fee, inspection, key1, op1)
    ESC->>LED: hold pair: available -X, held +X
    API->>ASG: dispatchNearest(order)
    ASG-->>T: FCM: dispatch offer (90s)
    T->>API: accept
    API->>ASG: accept(order, tech) — CAS under lock
    T->>API: arrived (order in_progress)
    T->>API: POST quote (UC-02: labor + parts + warranty)
    C->>API: approve quote
    API->>ESC: holdFunds(order, quote_total, repair, key2, op2)
    ESC->>LED: hold pair: available -Y, held +Y
    T->>API: work documented (before/after photos)
    C-->>T: closure code (spoken in person)
    T->>API: submit code — verified SERVER-side only
    API->>API: status completed, dispute_deadline_at = now + 48h
    Note over CRON: after deadline passes
    CRON->>ESC: releaseFunds(order, op3)
    ESC->>ESC: lockForUpdate, then check completed AND no open dispute
    ESC->>LED: release from client held, payout to tech available, commission to platform
```

## Dispute path: the race EscrowService must win

```mermaid
sequenceDiagram
    autonumber
    participant C as Client App
    participant API as API
    participant DSP as DisputeService
    participant ESC as EscrowService
    participant CRON as Scheduler

    Note over C,CRON: minutes before the dispute deadline...
    C->>API: POST /orders/{id}/dispute (UC-12)
    CRON->>ESC: releaseFunds(order)  (same moment!)
    Note over DSP,ESC: both race for the SAME row lock on orders
    alt dispute wins the lock
        DSP->>API: status = disputed, payment frozen
        ESC->>ESC: sees disputed → releases NOTHING
    else release wins the lock
        ESC->>ESC: sees completed & un-disputed → releases
        DSP->>API: dispute rejected (deadline passed)
    end
    Note over DSP: exactly one of the two succeeds — never both (SRS note 3)
```

## Where each EscrowService method fires

| Method | Called from | Trigger |
|---|---|---|
| `holdFunds` (inspection) | OrderController@store | client confirms order (UC-01 step 3) |
| `holdFunds` (repair) | QuoteController@approve | client approves quote (UC-02) |
| `holdFunds` (addon) | QuoteController@approve | client approves addon quote |
| `releaseFunds` | Scheduler releaseExpiredHolds | dispute window passed |
| `releaseFunds` | DisputeService@resolve | admin decides release_to_technician |
| `refund` (full) | DisputeService@resolve | admin decides full_refund |
| `refund` (partial) | DisputeService@resolve / cancel flow | partial_refund / late cancel split |
| `refund` (inspection) | AssignmentService | no technician found → fee returned |
