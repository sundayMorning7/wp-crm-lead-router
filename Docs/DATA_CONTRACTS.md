# LeadRouter — Data Contracts

## Lead Record

Table: leadrouter_leads

Required fields:
- id
- created_at
- response_status
- partner_id (nullable)
- sent_at (nullable)
- response_json

Status semantics:
- new: not processed
- processing_*: in worker
- queued/await: deferred
- sent: delivery succeeded
- error: terminal failure

---

## Group State

Table: leadrouter_groups

Fields:
- group_id
- eff
- last_day_key
- weight_mon ... weight_sun

Rules:
- eff resets when day_key changes (EST)
- weight_today determines daily quota

---

## Delivery Log

Table: leadrouter_send_log

Fields:
- delivery_uuid
- lead_id
- partner_id
- attempt_no
- idempotency_key
- request_json (masked)
- response_body (masked)
- classified_status
- next_retry_at
- created_at

Rules:
- delivery_uuid identifies one lead→partner delivery intent
- attempt_no increments on each retry
- idempotency_key remains constant per delivery_uuid

---

## Routing Log

Table: leadrouter_logs

Fields:
- event_type
- lead_id
- group_id
- context_json
- created_at

Purpose:
- Audit routing decisions
- Trace orchestration logic

---

## Partner Contract

Configuration:
- schedule per weekday (start/end)
- daily limit per weekday

Rules:
- Availability requires both:
  - current time in schedule window
  - used_today < limit_today

---

## Time Semantics

All daily calculations use:
- Timezone: America/New_York

Affected processes:
- Group quota reset
- Partner limits
- Schedule windows

---

## Idempotency Rules

Idempotency key must be derived from:
- lead_id
- partner_id
- channel
- payload version or hash
- static salt

Key must remain stable across:
- retries
- queue executions
- manual re-dispatch

---

## Invariants

- No duplicate deliveries for same delivery_uuid
- Group daily quotas are not exceeded
- Partner limits are respected
- Lead-level status reflects aggregate delivery outcome
