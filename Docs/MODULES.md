# LeadRouter — Component Responsibilities

## LeadRouter_Flow

Responsibility:
- End-to-end orchestration of lead delivery

Functions:
- Lead retrieval and status updates
- Group selection coordination
- Partner filtering
- Delivery execution
- Retry and queue scheduling
- Logging of events and attempts

Dependencies:
- Dispatcher
- Partners availability module
- Sender implementation
- Database tables (leads, logs, send_log)

Side Effects:
- Writes lead status
- Writes delivery logs
- Schedules queue tasks

---

## LeadRouter_Dispatcher_Eff

Responsibility:
- Group-level routing using WRR

Functions:
- Daily quota evaluation
- eff counter management
- Group selection

Dependencies:
- groups state table
- timezone utilities

Side Effects:
- Updates eff counters
- Logs routing decisions

---

## LeadRouter_Partners

Responsibility:
- Determine partner availability

Functions:
- Schedule evaluation
- Daily usage tracking
- Partner filtering by group

Dependencies:
- Partner meta configuration
- Delivery logs

Side Effects:
- None (read-only logic)

---

## LeadRouter_Sender_Light

Responsibility:
- Transport execution and response handling

Functions:
- HTTP and email delivery
- Response parsing
- Error classification
- Idempotency key generation
- Masking sensitive data

Dependencies:
- External APIs
- Payload transformers

Side Effects:
- None directly (results consumed by Flow)

---

## Cron Components

### New Leads Worker

Responsibility:
- Select new leads
- Prevent parallel processing
- Trigger dispatch

### Await / Queue Worker

Responsibility:
- Resume deferred deliveries
- Execute retries
- Enforce scheduling windows

---

## LeadRouter_Lead

Responsibility:
- Lead data access abstraction

Functions:
- Load lead row
- Update status and timestamps
- Persist responses

---

## LeadRouter_Sender (Base)

Responsibility:
- Common sender utilities and contracts

Functions:
- Standardized send interface
- Result normalization

---

## LeadRouter_CLI

Responsibility:
- Operational tooling

Functions:
- Manual dispatch
- Status inspection
- Maintenance operations

---

## Admin Components

### Leads Table
- Lead list view
- Filtering and bulk actions

### Statistics
- Aggregated delivery metrics

### Log Viewer
- File log inspection

---

## Configuration Components

### CPT Registration
- Groups
- Partners

### Admin UI
- Menus
- Assets
- Settings pages
