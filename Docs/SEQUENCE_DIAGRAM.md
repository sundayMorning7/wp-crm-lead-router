# LeadRouter — Sequence Diagrams

This document describes the primary execution flows as step-by-step sequences.
All “daily” decisions are evaluated using the America/New_York timezone.

---

## 1) Cron: Dispatch New Leads

Actors:
- Cron Scheduler
- New Leads Worker
- Lead Store (leads table)
- Flow Orchestrator
- Group Dispatcher (WRR/eff)
- Partner Availability Module
- Sender (HTTP/Email)
- Delivery Log (send_log)
- Routing/Event Log (logs)

Sequence:
1. Cron Scheduler triggers New Leads Worker (every_minute).
2. New Leads Worker acquires a global lock to prevent parallel runs.
3. New Leads Worker queries Lead Store for leads in status `new`.
4. For each selected lead:
   1) Update lead status to `processing_newcron`.
   2) Invoke Flow Orchestrator: `dispatch_broadcast(lead_id, opts)`.
5. Flow Orchestrator loads lead record.
6. Flow Orchestrator requests group assignment from Group Dispatcher:
   - Group Dispatcher performs daily reset if day boundary changed (EST).
   - Group Dispatcher filters eligible groups by weight_today and exhaustion.
   - Group Dispatcher selects group using WRR/eff.
   - Group Dispatcher increments eff for selected group.
   - Group Dispatcher logs routing decision.
7. Flow Orchestrator fetches partner list for the selected group.
8. Flow Orchestrator filters partners using Partner Availability Module:
   - Exclude partners closed by schedule window.
   - Exclude partners exhausted by daily limit.
9. For each available partner:
   1) Build delivery payload.
   2) Generate idempotency key.
   3) Execute delivery via Sender.
   4) Classify response as success / retryable_error / fatal_error.
   5) Persist attempt to Delivery Log (send_log).
10. Flow Orchestrator aggregates outcomes:
    - If at least one success: set lead status to `sent`.
    - Else if retryable or deferred deliveries exist: set lead status to `queued/await`.
    - Else: set lead status to `error`.
11. Flow Orchestrator logs orchestration event(s).
12. New Leads Worker releases global lock.

Notes:
- The “success” criterion is defined by delivery classification rules and system policy.
- All attempts are recorded regardless of final lead state.

---

## 2) Manual Dispatch: Broadcast Delivery

Actors:
- Admin UI or CLI
- Flow Orchestrator
- Group Dispatcher
- Partner Availability Module
- Sender
- Delivery Log
- Routing/Event Log

Sequence:
1. A manual command triggers Flow Orchestrator: `dispatch_broadcast(lead_id, opts)`.
2. Flow Orchestrator validates lead status against allowed statuses.
3. Flow Orchestrator performs group assignment via Group Dispatcher (WRR/eff).
4. Flow Orchestrator filters partners via Partner Availability Module.
5. For each partner:
   - Execute delivery via Sender.
   - Persist attempt to Delivery Log.
6. Flow Orchestrator updates lead status based on aggregated outcome.
7. Routing/Event Log is updated with decision and execution context.

Notes:
- Manual dispatch must preserve idempotency semantics to avoid duplicates.

---

## 3) Closed Partner Window: Deferred Delivery via Queue

Actors:
- Flow Orchestrator
- Partner Availability Module
- Queue Scheduler
- Queue Worker (cron_send_worker)
- Sender
- Delivery Log

Sequence:
1. Flow Orchestrator evaluates partner availability.
2. Partner Availability Module returns “closed now” for a partner.
3. If queue_if_closed is enabled:
   1) Flow Orchestrator creates a queue task for (lead_id, partner_id, context).
   2) Queue Scheduler schedules execution via `leadrouter_queue_send`.
4. At scheduled time, Queue Worker is invoked.
5. Queue Worker reloads lead and partner context.
6. Queue Worker re-evaluates partner availability:
   - If still closed: reschedule or keep deferred (policy-defined).
   - If open: proceed to delivery.
7. Queue Worker builds payload, generates idempotency key, calls Sender.
8. Attempt is persisted to Delivery Log.
9. Lead status is updated if delivery success criteria are met.

Notes:
- Deferred delivery must reuse the same idempotency key for a given delivery intent.

---

## 4) Retryable Error: Backoff and Re-attempt

Actors:
- Flow Orchestrator
- Sender
- Delivery Log
- Await Worker

Sequence:
1. Sender returns a retryable_error classification for an attempt.
2. Flow Orchestrator calculates next_retry_at:
   - Prefer Retry-After header if present.
   - Otherwise apply exponential backoff policy.
3. Flow Orchestrator persists attempt with next_retry_at to Delivery Log.
4. Flow Orchestrator sets lead status to `queued/await` if no immediate success occurred.
5. Await Worker periodically scans Delivery Log for items where next_retry_at <= now.
6. Await Worker invokes Flow Orchestrator / Queue Worker to re-attempt delivery.
7. Subsequent attempts increment attempt_no while preserving idempotency key.
8. When max attempts are exceeded:
   - Delivery intent becomes terminal (policy-defined).
   - Lead status transitions to `error` or `await_manual`.

Notes:
- Max attempt count is enforced per delivery intent (lead→partner).

---

## 5) Group Quota Exhausted: Deferral to Next Day

Actors:
- Group Dispatcher
- Flow Orchestrator
- Await Worker

Sequence:
1. Group Dispatcher filters out all groups due to exhaustion or zero weight_today.
2. Group Dispatcher returns “no eligible group”.
3. Flow Orchestrator logs routing failure reason.
4. Flow Orchestrator transitions lead status to `queued/await` (policy-defined).
5. Await Worker retries routing after next day boundary in EST.
6. Once eligible groups exist again, the standard routing and delivery pipeline resumes.

Notes:
- If overflow routing is permitted, this scenario may be bypassed (policy-defined).
