# Live technician location (Firebase Realtime Database)

Live tracking lets the client watch the assigned technician approach, and lets the technician
publish their position, **only** during the trip — from the moment the order is accepted (or a
scheduled appointment activates) until the technician marks arrival. Laravel never carries GPS
pings; it only opens and closes the gate. The stream itself is client ⇄ RTDB.

## Who does what

- **Backend (Laravel, Admin SDK — bypasses rules):** writes the membership node
  `/orders/{id}/meta` when tracking opens and flips `active` to `false` (and clears the last
  location) when it closes. See `App\Services\Realtime\FirebaseLocationTracker`.
- **Technician app:** signs in with a custom token, then writes `/orders/{id}/location` every
  ~3–5 s (or per ~20 m) while `meta/active === true`.
- **Client app:** signs in with a custom token and subscribes to `/orders/{id}/location`.

## Auth: custom token

The Firebase uid **is the app user id** (`users.id`, as a string). Get a token from the API and
exchange it for a Firebase session:

```
POST /api/firebase/token        (Bearer <sanctum token>)
→ 200 { "token": "<firebase custom token>" }
```

Then on the device: `signInWithCustomToken(token)`. The token carries a `role` claim, and
`admin: true` for admins (which the rules honour for read access to any order).

## Data shape

```
/orders/{id}/meta      { "client_uid": "12", "tech_uid": "47", "active": true }   // backend-only
/orders/{id}/location  { "lat": 33.5138, "lng": 36.2765, "updated_at": 1723800000000 }
```

`updated_at` is epoch **milliseconds**. Stray keys under `location` are rejected by the rules.

## Lifecycle (when the gate opens / closes)

| Event | Backend action |
| --- | --- |
| Urgent order accepted (`AssignmentService::accept` → Accepted) | `open` |
| Scheduled appointment activates (`SchedulingService::activateDue` → Accepted) | `open` |
| Technician marks arrival (`ArrivalService::markArrived`) | `close` |
| Client cancels a committed order | `close` |
| Technician withdraws (job returns to the pool) | `close` (next tech reopens on accept) |

Warranty visits activate straight to `in_progress` and are **not** tracked.

## Rules

Security rules live in [`database.rules.json`](database.rules.json): only the order's
`client_uid`, `tech_uid`, or an admin may read the location; only the assigned `tech_uid` may
write it, and only while `meta/active` is `true`. Deploy with:

```
firebase deploy --only database
```

## Config

`config/realtime.php` → `driver` (`REALTIME_DRIVER`): `log` (default; local/tests, no Firebase)
or `firebase` (mints real custom tokens + writes the RTDB membership node). The `firebase`
driver needs the kreait credentials already used for FCM plus `FIREBASE_DATABASE_URL`.
