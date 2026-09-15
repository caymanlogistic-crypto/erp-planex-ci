# MAX event notifications

`MAX — интеграция` → `События` is the company-owner-only management surface.
Each registered event has an enabled flag, one tenant chat, a text template,
plain/HTML/Markdown format and Push setting. Template variables are validated and
escaped for the chosen format. Preview uses sample data and never calls MAX.

The initial event `feo.placement.block_created` covers both addition and exclusion
blocks. Preview, status changes, reconcile, delete and historical blocks do not
emit this event. Settings start disabled without a selected recipient.

## Delivery contract

- The creation controller passes a callback to `FeoPlacementService::createBlock`.
  The callback records a delivery inside the block transaction. A rollback removes
  both records. Rendering/configuration errors are stored as failed deliveries.
- Only after commit and consumption of the preview token does the controller send
  through the existing `MaxMessagingService` / `MaxApiClient`. MAX failures do not
  roll back the block or remove its uploaded source.
- `(event_key, entity_key)` is unique in each tenant. A conditional pending →
  sending claim prevents simultaneous sends. Successful deliveries never retry.
- Text, format, Push, bot and external chat address are captured at creation.
  Later rule edits apply to future events. Editing the configured chat's external
  address stops old deliveries instead of redirecting them.
- Sending is synchronous after commit (existing MAX HTTP timeout: 20 seconds).
  There is no scheduled retry worker. Failed, pending and stale sending deliveries
  are visible on the Events tab. The owner may explicitly retry; a sending claim
  is eligible only after five minutes. Timeouts can mean delivery was accepted by
  MAX, so the UI asks the owner to check the chat before retrying.
- Disabled rules and inactive/removed chats/bots stop sending. A template that
  exceeds the final 4,000-character limit is recorded as failed, never truncated
  or sent with unresolved placeholders.

## Adding another ERP event

Register its stable key, description, default template and variable definitions
in `MaxEventCatalog`. Enqueue a stable entity key and scalar values inside the
owning business transaction, then dispatch after commit. Reuse the same management
screen and `MaxMessagingService`; do not add another HTTP integration client.

## Rollout

Migration `136_create_max_event_notifications.sql` creates only
`max_event_rules` and `max_event_deliveries`. It does not enable events, select
chats, alter bots or send historical messages. Production migration requires
separate task-specific owner authorization through the existing guarded migration
request and `max_events_136_production_migration.yml`, before code deploy. Merely
merging this code does not authorize the migration. A missing event schema is
handled as unavailable while existing MAX management continues to work.

Validation: mandatory MAX contracts/MySQL gate includes template, transaction,
idempotency, failure/retry, address snapshot and actual migration-script tests.
`tests/max_events_ui_test.mjs` checks the rendered PHP UI with Playwright using
local fixture data and a mocked preview response, with no external messages.
