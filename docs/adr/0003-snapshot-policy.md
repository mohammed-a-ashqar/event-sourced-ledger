# ADR 0003: Snapshot accounts every N events, never transfers

- Status: accepted
- Date: 2026-11-23

## Context

An account lives for years and its stream grows with every movement;
replaying thousands of events on every command would be slow. A transfer
stream never has more than four events.

## Decision

- `Account` implements `Snapshotable`; `Transfer` does not.
- `EveryNEvents` takes a snapshot when a save **crosses** a multiple of N
  (not when the version is exactly a multiple), because one save may append
  several events. N is `LEDGER_SNAPSHOT_EVERY`, default 50.
- Loading = latest snapshot + the events after its version. Saving a
  snapshot never replaces a newer one, so concurrent writers cannot move the
  cache backwards.
- Snapshots carry a schema version (`snapshotSchemaVersion()`). A snapshot
  with another schema version is ignored and the aggregate is replayed in
  full; changing the aggregate's state therefore never needs a migration of
  the snapshots table.
- Temporal queries ("balance as of") ignore snapshots and replay up to the
  requested moment.

## Consequences

- Snapshots are a disposable cache: deleting the table only costs replay time.
- Tests prove that snapshot + tail produces exactly the same state as a full
  replay, and that loading really starts from the snapshot (a tampered
  snapshot shows through).
- The snapshot is written after the events are committed, outside their
  transaction. A crash in between loses the snapshot, never data.
