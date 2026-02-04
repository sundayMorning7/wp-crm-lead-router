# LeadRouter — State Machine Specification

This document defines the lead-level state machine and its relationship to delivery-level outcomes.

---

## 1) Entities and State Layers

Two complementary state layers exist:

1) Lead-Level State (lead record)
- Represents aggregated processing outcome across all delivery intents.

2) Delivery-Level State (send_log record)
- Represents each attempt for a specific delivery intent (lead→partner).

Lead-level state is derived from delivery-level outcomes by policy.

---

## 2) Lead-Level States

### 2.1 Primary States

- new
  - Lead exists and has not been processed.

- processing_newcron
  - Lead is claimed by the New Leads Worker.

- queued
  - Lead requires deferred processing (e.g., closed partner, retry, quota exhaustion).

- await
  - Equivalent to queued; may be used to distinguish “waiting for retry window”.
  - If both exist, await is used for retry scheduling and queued for time-window scheduling.

- sent
  - Lead achieved delivery success criteria.

- error
  - Lead cannot proceed automatically and requires intervention or policy override.

- await_manual (optional)
  - Terminal non-success state requiring operator action.

### 2.2 State Properties

- Terminal states: sent, error (and await_manual if used)
- Non-terminal states: new, processing_*, queued/await

---

## 3) Allowed Lead-Level Transitions

The following transitions are permitted:

1) Intake:
- (none) → new

2) Worker claim:
- new → processing_newcron

3) Successful completion:
- processing_newcron → sent
- queued/await → sent

4) Deferred processing:
- processing_newcron → queued
- processing_newcron → await
- queued ↔ await (optional normalization)
- queued/await → queued/await (reschedule without progress)

5) Terminal failure:
- processing_newcron → error
- queued/await → error
- processing_newcron → await_manual (optional)
- queued/await → await_manual (optional)

6) Recovery / reprocessing (operator-driven):
- error → queued/await (optional; controlled operation)
- await_manual → queued/await (optional; controlled operation)

Notes:
- Recovery transitions are optional and must be explicitly controlled (CLI/admin action).

---

## 4) Delivery-Level States (Attempt Classification)

Each send_log attempt has a classified result:

- success
- retryable_error
- fatal_error
- skipped_closed
- skipped_limit

Additionally, attempts may include:
- next_retry_at (for retryable_error)
- attempt_no (monotonic increment per delivery intent)
- idempotency_key (stable per delivery intent)

---

## 5) Aggregation Rules (Delivery → Lead)

Lead-level state is derived from delivery-level outcomes by the following policy:

1) If any delivery intent results in success:
- lead state becomes sent

2) Else if any delivery intent is pending retry or deferred execution:
- lead state becomes queued/await

Pending conditions include:
- retryable_error with next_retry_at in the future
- skipped_closed with queue scheduling enabled
- routing deferred due to quota exhaustion

3) Else if all considered delivery intents are terminal non-success:
- lead state becomes error (or await_manual per policy)

Terminal non-success includes:
- fatal_error
- skipped_limit when no alternative partners exist
- exhausted quotas with no deferral policy enabled

Notes:
- The system may define “success” as “first successful delivery” or “all required deliveries succeeded”.
- The default aggregation model is “at least one success yields sent”.

---

## 6) Day Boundary Rules (EST)

Daily logic is evaluated using America/New_York timezone:

- Group quotas reset on EST day boundary.
- Partner daily limits reset on EST day boundary.
- Availability schedules use EST time-of-day comparisons.

---

## 7) Invariants

- A lead cannot remain indefinitely in processing_* states.
  - Stale processing states must be recoverable (operator action or automated reconciliation).

- A delivery intent preserves idempotency:
  - Multiple attempts for the same delivery intent must not create duplicate partner-side artifacts.

- Attempt numbers are monotonically increasing per delivery intent.

- Limits and quotas are enforced according to configured policy.
