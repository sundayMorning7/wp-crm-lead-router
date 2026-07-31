---
applyTo: "leadrouter/**"
---

# LeadRouter — GitHub Copilot Instructions

## Project Overview

LeadRouter is a WordPress plugin that automates routing and delivery of leads to partner endpoints. It uses a Weighted Round Robin (WRR) dispatcher, per-partner availability windows, daily quotas, and retry/queue logic.

---

## Technology Stack

- **PHP 7.4+** (WordPress plugin)
- **WordPress hooks, WP_Query, wpdb** for all DB and lifecycle operations
- **Carbon Fields** for custom meta fields (`carbon_get_post_meta()`)
- **InnoDB MySQL tables** — defined in `leadrouter_install_db()` in `leadrouter.php`
- **All date/time logic** must use the `America/New_York` timezone (EST/EDT)

---

## Directory Structure

```
leadrouter/
├── leadrouter.php               # Plugin bootstrap: requires, DB install, cron init
├── includes/
│   ├── class-leadrouter-admin.php       # Admin AJAX handlers, menus, UI
│   ├── class-leadrouter-cpt.php         # Custom Post Types: leadrouter_group, leadrouter_partner
│   ├── class-leadrouter-transform.php   # Field transformation helpers (date formats, etc.)
│   ├── functions-leadrouter.php         # Global helpers: normalize_phone, normalize_date, group weights
│   ├── helpers.php                      # leadrouter_recalc_sum_weight, leadrouter_save_group_day_weights_by_post
│   ├── leadrouter-main.php              # Legacy stub (unused entry point, keep as-is)
│   ├── classes/
│   │   ├── class-leadrouter-flow.php           # Core orchestrator: dispatch_broadcast, mark_lead_status, log_*
│   │   ├── class-leadrouter_dispatcher_eff.php # WRR group selection using eff counter
│   │   ├── class-leadrouter-partners.php       # Partner availability: schedule + daily limit checks
│   │   ├── class-leadrouter_sender_light.php   # HTTP/email delivery, payload mapping, send_log
│   │   ├── class-leadrouter-sender.php         # Base sender utilities and error classification
│   │   ├── class-leadrouter-lead.php           # Lead CRUD + bulk operations (transactional)
│   │   ├── class-leadrouter-hooks.php          # WP hooks: recalc weights on partner/group save
│   │   ├── class-leadrouter_cron_new_leads.php # Cron worker: picks `new` leads, dispatches via Flow
│   │   ├── class-leadrouter_cron_await_leads.php # Cron worker: picks `await` leads, re-dispatches
│   │   ├── class-leadrouter_cron_error_leads.php # Cron worker: picks `error` leads, force-dispatches
│   │   ├── class-leadrouter-cli.php            # WP-CLI commands for manual dispatch/maintenance
│   │   ├── class-lr-billing*.php               # Billing subsystem (Stripe, mailer, DB, cron)
│   │   └── class-lr-partner-*.php             # Partner portal: auth, impersonate, card, complaints
│   ├── admin/
│   │   ├── class-leadrouter_leads_table.php    # WP_List_Table for leads admin view
│   │   ├── class-leadrouter_leads_stats.php    # Aggregated delivery statistics
│   │   ├── class-leadrouter-logviewer.php      # File log viewer (rotated .log files)
│   │   ├── class-leadrouter-group-toggle.php   # Group enable/disable toggle in admin
│   │   ├── lr-est-clock.php                    # EST clock widget in admin header
│   │   ├── lr-partner-user-link.php            # Link WP user ↔ partner post
│   │   ├── lr-send-test-lead.php               # Admin: test send a lead to a partner
│   │   ├── page-billing-dashboard.php          # Billing dashboard page
│   │   ├── page-billing-report.php             # Billing monthly report
│   │   ├── page-complaints.php                 # Partner complaints admin page
│   │   └── page-partner-billing.php            # Per-partner billing settings
│   └── cron/
│       └── class-leadrouter_cron_leads.php     # Legacy cron (CPT-based; current system uses DB table)
```

---

## Database Tables

| Table | Purpose |
|---|---|
| `{prefix}leadrouter_leads` | Lead records with lifecycle status |
| `{prefix}leadrouter_groups` | Group routing state: eff counter, per-day weights |
| `{prefix}leadrouter_partner_logs` | Per-partner delivery attempt history |
| `{prefix}leadrouter_send_log` | Transport-level send log (request/response/classification) |
| `{prefix}leadrouter_logs` | Routing/orchestration event log |
| `{prefix}leadrouter_state` | Key-value state store |
| `{prefix}leadrouter_daily_adstats` | Manual ad spend / Facebook leads input |
| `{prefix}leadrouter_lead_complaints` | Partner complaints against leads |

All table definitions live in `leadrouter_install_db()` in `leadrouter.php`.

---

## Lead Lifecycle (Status Field)

The `status` column in `leadrouter_leads` follows this state machine:

```
new → processing_newcron → sent
new → processing_newcron → await → sent
new → processing_newcron → error
new → processing_newcron → state_error  (AK/HI filter)
```

- `new` — just inserted, not processed
- `processing_newcron` — claimed by the New Leads cron worker
- `await` — deferred (partner closed, quota exhausted)
- `sent` — at least one successful delivery
- `error` — all attempts failed
- `state_error` — excluded state (AK/HI) with no eligible partners

Use `LeadRouter_Flow::mark_lead_status(int $lead_id, string $status, array $extra)` to update the lead status — it also writes to `leadrouter_logs`.

---

## Core Classes and Patterns

### LeadRouter_Flow (orchestrator)

The main entry point for all dispatching. All workers and admin actions call:

```php
$result = LeadRouter_Flow::dispatch_broadcast(int $lead_id, array $opts);
```

Key `$opts` keys:
- `group_meta_key` — meta key linking partners to groups (default: `_leadrouter_partner_group`)
- `statuses` — partner log statuses that count as "used today" (default: `['sent','accepted']`)
- `initial_status` — status to record on success (default: `'sent'`)
- `dispatch_method` — caller identifier (`'auto_cron_new_lead'`, `'auto_cron_await_lead'`, `'manual_bulk'`, etc.)
- `queue_if_closed` — whether to queue delivery when partner is closed (default: `false`)
- `force_group_post_id` — force a specific group (bypasses WRR, used for error lead re-routing)

Returns `array` with keys `sent`, `failed`, `all`, `summary` on success, or `WP_Error` on failure.

### LeadRouter_Dispatcher_Eff (WRR routing)

Selects a group for a lead using Smooth Weighted Round Robin:

```php
$group = LeadRouter_Dispatcher_Eff::assign_group_for_lead(int $lead_id, array $opts);
// Returns: ['group_id'=>int, 'group_post_id'=>int, 'name'=>string, 'weight'=>int]
// or WP_Error
```

- Reads `weight_1`…`weight_7` from `leadrouter_groups` (Mon=1…Sun=7, EST)
- Uses `eff` counter for smooth distribution across the day
- Resets `eff` to 0 when EST day boundary is crossed
- Excludes AK/HI leads from quota counting (`group_assigned_excluded_state`)

### LeadRouter_Partners (availability)

```php
// All available partners in a group
$partners = LeadRouter_Partners::available_in_group(int $group_post_id, array $opts);

// Single partner check
$row = LeadRouter_Partners::check_partner(int $partner_id, array $opts);
```

Partner availability requires **both**:
1. Current EST time within `_leadrouter_partner_{day}_start` / `_leadrouter_partner_{day}_end`
   - Missing start/end → always open
   - Overnight windows (e.g. 22:00–06:00) are handled
2. `used_today < limit_today` where `_leadrouter_partner_{day}_limit` > 0
   - Missing or zero limit → partner does not accept today

All `{day}` slugs: `mon`, `tue`, `wed`, `thu`, `fri`, `sat`, `sun`.

### LeadRouter_Sender_Light (HTTP/email transport)

```php
$out = LeadRouter_Sender_Light::send(array $our_payload, int $partner_post_id, array $context);
// Returns: ['result'=>['success'=>bool, 'retryable'=>bool, ...], 'debug'=>[...], 'req'=>[...], 'resp'=>[...]]
```

Partner configuration is read via `carbon_get_post_meta()`:
- `leadrouter_partner_endpoint` — URL
- `leadrouter_partner_auth_variant` — `none|header|query|payload|payload_authkey|payload_xapikey`
- `leadrouter_partner_api_key` / `leadrouter_partner_api_key_header`
- `leadrouter_partner_http_method` — `GET|POST|PUT|PATCH`
- `leadrouter_partner_body_type` — `json|form|xml`
- `leadrouter_partner_map` — Carbon Fields repeater for payload field mapping

For email partners (`_leadrouter_partner_type = 'email'`), `send_via_email()` is called instead.

Delivery classification:
- `success` — 2xx HTTP (or 2xx + `{"ok":true}` if `require_ok_json` is set)
- `retryable_fail` — 408, 429, 5xx, or WP_Error (connection timeout)
- `hard_fail` — 4xx other than 408/429

Every send attempt is logged to `leadrouter_send_log`.

---

## Coding Conventions

### Time and Timezone

**Always** use `America/New_York` for all date/time operations:

```php
$tz  = new DateTimeZone('America/New_York');
$now = new DateTimeImmutable('now', $tz);
$ts  = $now->format('Y-m-d H:i:s');
```

Never use `current_time('mysql')` for business logic (only for legacy DB logging where explicitly noted). Use `self::now_mysql_est()` helpers where available.

### Database Queries

Always use `$wpdb->prepare()` for queries with user/dynamic input:

```php
global $wpdb;
$row = $wpdb->get_row(
    $wpdb->prepare("SELECT * FROM {$wpdb->prefix}leadrouter_leads WHERE id = %d", $lead_id),
    ARRAY_A
);
```

Use `$wpdb->insert()` / `$wpdb->update()` / `$wpdb->delete()` rather than raw SQL for writes.

For multi-step writes that must be atomic, wrap in explicit transactions:

```php
$wpdb->query('START TRANSACTION');
// ... inserts/updates ...
$wpdb->query('COMMIT');
// on failure:
$wpdb->query('ROLLBACK');
```

### Error Handling

Return `WP_Error` for recoverable failures, throw `\Throwable` only for truly exceptional conditions.

```php
if (empty($endpoint)) {
    return new WP_Error('endpoint_missing', 'Partner endpoint is not configured');
}
```

Always check `is_wp_error()` before using the result of any method that may return `WP_Error`.

### Logging

Use `LeadRouter_Flow` log helpers for all application-level logging:

```php
LeadRouter_Flow::log_info('message', ['key' => 'value']);
LeadRouter_Flow::log_error('error occurred', ['lead_id' => $lead_id]);
LeadRouter_Flow::log_debug('detail', ['ctx' => $ctx]);
```

For routing/dispatch events, also call:

```php
LeadRouter_Flow::log_event(int $lead_id, string $status, array $extra);
```

Delivery attempts to partners are recorded via `LeadRouter_Flow::log_attempt()`.

### Partner Meta Keys (conventions)

| Meta key pattern | Type | Purpose |
|---|---|---|
| `_leadrouter_partner_group` | int (post_id) | Which group the partner belongs to |
| `_leadrouter_partner_active` | `1`\|`0`\|empty | Active flag (empty = active) |
| `_leadrouter_partner_{day}_limit` | int | Daily lead limit (0 = closed today) |
| `_leadrouter_partner_{day}_start` | `HH:MM` | Working hours start (EST) |
| `_leadrouter_partner_{day}_end` | `HH:MM` | Working hours end (EST) |
| `_leadrouter_partner_allow_alaska` | `1`\|`0` | Accept AK leads |
| `_leadrouter_partner_allow_hawaii` | `1`\|`0` | Accept HI leads |
| `leadrouter_partner_endpoint` | string | HTTP endpoint URL (Carbon Field) |
| `leadrouter_partner_auth_variant` | string | Auth type (Carbon Field) |
| `leadrouter_partner_api_key` | string | API key value (Carbon Field) |
| `leadrouter_partner_map` | repeater | Payload field mapping (Carbon Field) |
| `_leadrouter_partner_type` | string | `standard`\|`email` |

Group meta is stored in the `leadrouter_groups` DB table (not post meta). Update via `leadrouter_save_group_day_weights_by_post()`.

### Idempotency

Every delivery intent (lead→partner) must have a stable idempotency key derived from:
- `phone`
- `origin_postal_code`
- `destination_postal_code`
- `ship_date`

```php
$idem_key = sha1(implode('|', [$phone, $origin_zip, $dest_zip, $ship_date]));
```

The key must remain the same across retries and re-queues.

### Cron Workers

Each cron worker follows this pattern:
1. Acquire a transient-based lock (`set_transient(LOCK_KEY, 1, 55)`)
2. Fetch exactly **one** lead in the target status
3. Check for test leads (name starts with `test` → mark `sent`, skip dispatch)
4. Dispatch via `LeadRouter_Flow::dispatch_broadcast()`
5. Release the lock (`delete_transient(LOCK_KEY)`)

Cron workers are initialized with `LeadRouter_Cron_*::init()` called at plugin bootstrap.

### AK/HI State Filtering

Leads from/to Alaska (`AK`) or Hawaii (`HI`) follow special routing:
- Partners must have `_leadrouter_partner_allow_alaska=1` or `_leadrouter_partner_allow_hawaii=1`
- The group dispatcher logs `group_assigned_excluded_state` instead of `group_assigned`
- AK/HI leads do NOT count against group `eff` quotas

---

## Standard Payload Structure

The internal (BATS-compatible) lead payload used between Flow and Sender:

```php
[
    'first_name'               => string,
    'last_name'                => string,
    'email'                    => string,
    'phone'                    => string,   // digits only: '3463502904'
    'ship_date'                => string,   // 'Y-m-d'
    'transport_type'           => '1',
    'comment_from_shipper'     => '',
    'Vehicles'                 => [[
        'vehicle_type'         => string,
        'vehicle_model_year'   => int,
        'vehicle_make'         => string,
        'vehicle_model'        => string,
        'vehicle_inop'         => '0'|'1',  // '0'=running, '1'=non-running
    ]],
    'origin_country'           => 'USA',
    'origin_city'              => string,
    'origin_state'             => string,   // 2-letter code
    'origin_postal_code'       => string,
    'destination_country'      => 'USA',
    'destination_city'         => string,
    'destination_state'        => string,
    'destination_postal_code'  => string,
    'utm_source'               => string,
]
```

Partner-specific payloads are built by `lr_build_partner_payload()` using the Carbon Fields map repeater.

---

## Adding a New Feature: Checklist

1. **New class** → place in `leadrouter/includes/classes/class-leadrouter-{name}.php`
2. **Require it** in `leadrouter/leadrouter.php` after existing requires
3. **DB changes** → add to `leadrouter_install_db()` using `dbDelta()`; bump plugin version in header
4. **Time-sensitive logic** → always use `America/New_York` timezone
5. **New meta field on partner** → add to `class-leadrouter-cpt.php` (Carbon Fields container)
6. **New admin page** → add to `leadrouter/includes/admin/`, register menu in `class-leadrouter-admin.php`
7. **New cron** → follow the `LeadRouter_Cron_*` pattern; call `::init()` in `leadrouter.php`
8. **New AJAX action** → register in `LeadRouter_Admin::register_ajax()`, add nonce verification
9. **Test leads** → leads with names starting with `test` (case-insensitive) must be skipped by all cron workers, marked `sent` without dispatching

---

## Known Technical Debt (do not replicate)

- `leadrouter-main.php` — legacy stub, all functions commented out; do not add new code here
- `class-leadrouter_cron_leads.php` in `includes/cron/` — CPT-based cron, superseded by the DB-table workers in `includes/classes/`; do not use
- `LeadRouter_Flow::send_to_partner()` — returns a mocked success; actual delivery goes through `LeadRouter_Sender_Light::send()`
- Double logging in `dispatch_broadcast` (noted with `// TODO подвійне логування`)
- AK/HI filtering logic is duplicated across `Flow`, `Partners`, and `Dispatcher`; consolidation is planned
- `attempted_at` in `leadrouter_send_log` uses `current_time('mysql')` instead of EST (marked `// TODO Fix`)
