# LeadRouter — System Architecture

## 1. Purpose and Scope

The LeadRouter system provides automated routing and delivery of leads to partner endpoints
based on configurable group quotas, partner availability schedules, and retry policies.

The system supports:
- Daily quota-based weighted routing across groups
- Partner-level availability windows and daily limits
- Multiple delivery channels (HTTP, email)
- Retry and deferred delivery via queue workers
- Observability through database and file logs

---

## 2. High-Level Architecture

The system is composed of the following subsystems:

1. Lead Intake
2. Group Dispatcher (WRR)
3. Partner Availability Filter
4. Delivery Layer (Sender)
5. Retry and Queue Processing
6. State and Logging
7. Configuration and Admin Interface

Primary execution paths:
- Cron-driven automatic dispatch
- Manual dispatch (admin or CLI)
- Deferred dispatch via queue workers

---

## 3. Core Data Flow

1. A lead is inserted into the leads table with status `new`.
2. The New Leads Worker selects eligible leads and marks them as processing.
3. The Group Dispatcher selects a target group using WRR/eff algorithm.
4. Partners in the selected group are filtered by availability rules.
5. Delivery attempts are executed via Sender.
6. Delivery results are classified and logged.
7. Lead-level status is updated based on aggregated delivery outcomes.
8. Retryable or deferred deliveries are scheduled via queue workers.

---

## 4. Lead Lifecycle

Lead states represent the aggregate status of all delivery attempts.

Typical states:
- new — lead has not been processed
- processing_* — currently being processed by a worker
- queued / await — delivery deferred due to time windows or retry policy
- sent — at least one successful delivery occurred
- error — delivery cannot proceed without manual intervention

State transitions:
new → processing → sent  
new → processing → queued → sent  
new → processing → error  

Final state is derived from delivery-level outcomes.

---

## 5. Group Routing Algorithm (WRR / eff)

Routing is performed at the group level using a Weighted Round Robin mechanism
based on an `eff` (effective usage) counter.

Definitions:
- weight_today(G): daily quota of group G for the current weekday
- eff(G): effective usage counter of group G for the current day

All daily calculations use the America/New_York timezone.

Algorithm:
1. Exclude groups where weight_today ≤ 0
2. Exclude groups where eff ≥ weight_today
3. Select group with minimal normalized usage
4. Increment eff of selected group
5. Log routing decision

Day boundary handling:
- When a new day is detected in EST, eff counters are reset.

If no eligible groups remain:
- Lead is deferred until next routing window or next day.

---

## 6. Partner Availability Model

Each partner is subject to two independent constraints:

### 6.1 Working Hours

For each weekday:
- start_time
- end_time

Rules:
- If no window is defined, partner is considered open all day
- Time comparison uses EST timezone
- Cross-midnight windows may require explicit handling

### 6.2 Daily Partner Limits

For each weekday:
- limit_today

Rules:
- If limit is empty or zero, partner does not accept leads that day
- used_today is calculated from delivery logs
- Partner is unavailable if used_today ≥ limit_today

Partner is considered available only if both conditions are satisfied.

---

## 7. Delivery Pipeline

Delivery execution steps:

1. Build payload from lead data
2. Generate idempotency key
3. Send via selected transport channel
4. Capture HTTP/email response
5. Classify result
6. Persist delivery attempt log

Delivery classification:
- success
- retryable_error
- fatal_error
- skipped_closed
- skipped_limit

Lead-level status is derived from delivery-level results.

---

## 8. Retry and Queue Strategy

Retry is applied only for retryable_error classifications.

Mechanisms:
- Exponential backoff when Retry-After header is not present
- Respect Retry-After when provided
- Maximum retry attempts per delivery

Deferred execution scenarios:
- Partner closed by schedule
- Retryable error with next_retry_at timestamp
- Group quota exhausted

Deferred deliveries are executed by queue workers.

---

## 9. Concurrency and Safety

Safety mechanisms:
- Cron locks to prevent parallel workers
- Idempotency keys to prevent duplicate deliveries
- Atomic delivery logging

Guarantees:
- Multiple executions of the same delivery intent do not produce duplicate partner records
- Failed workers do not permanently lock lead state

---

## 10. Observability

Observability is provided through:

- leadrouter_logs: routing and orchestration events
- send_log: delivery attempts and responses
- file logs: system-level diagnostics

Correlation is possible using:
- lead_id
- partner_id
- delivery_uuid

These identifiers allow reconstruction of full processing history.
