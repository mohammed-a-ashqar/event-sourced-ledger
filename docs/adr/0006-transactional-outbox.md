# ADR 0006: Integration events through a transactional outbox

- Status: accepted
- Date: 2026-11-23

## Context

Other systems need to know when money moves. Publishing to a broker
directly from a command handler has two failure modes: the events commit and
the publish fails (message lost), or the publish succeeds and the commit
fails (message about something that never happened).

## Decision

- The event store accepts `AppendListener`s that run inside the append
  transaction. `OutboxWriter` is one of them: it maps domain events to
  versioned integration messages (`ledger.transfer.completed.v1`, ...) and
  inserts them into `outbox_messages` in the same transaction.
- Only outcomes leave the service: account opened/frozen, funds deposited/
  withdrawn, transfer completed/failed. Internal saga steps stay internal.
- `ledger:outbox:relay` publishes pending rows in insertion order through the
  `MessagePublisher` port and marks them published. Failures increment
  `attempts` and keep `last_error`; after `LEDGER_OUTBOX_MAX_ATTEMPTS` a
  message is parked so it cannot block the rest.
- The message id is the id of the originating domain event, so consumers can
  deduplicate.

## Consequences

- Delivery is at-least-once: a crash between publishing and marking the row
  sends the message again. Consumers must be idempotent on `message_id`.
- The relay is expected to run as a single instance (the scheduler uses
  `withoutOverlapping()`). Running several relays in parallel would need
  `FOR UPDATE SKIP LOCKED`, which SQLite and older MariaDB do not support.
- Swapping the log publisher for RabbitMQ or Kafka means implementing one
  interface and changing one binding; see the README.
