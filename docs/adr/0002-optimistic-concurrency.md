# ADR 0002: Optimistic concurrency on a unique (stream_id, version) index

- Status: accepted
- Date: 2026-11-23

## Context

Two requests can load the same account, both decide that a withdrawal is
allowed, and both try to persist it. Only one of them may win, otherwise
the overdraft rule is broken.

Pessimistic locking (`SELECT ... FOR UPDATE` on the stream) would serialise
writers but holds locks while the domain decides, and behaves differently
across SQLite, MySQL and PostgreSQL.

## Decision

- Each event row carries the stream version it was written at, and the
  table has a unique index on `(stream_id, version)`.
- A writer appends versions `expected + 1 ... expected + n`. If another writer
  got there first, the database raises a unique violation, which the store
  translates into `ConcurrencyException` (Laravel exposes one exception type,
  `UniqueConstraintViolationException`, for all three drivers).
- The command bus retries the *whole* handler a bounded number of times
  (`LEDGER_COMMAND_MAX_ATTEMPTS`, default 3). Each attempt reloads the
  aggregate, so invariants are checked again against the winner's events.
  The transfer saga retries each step the same way.

## Consequences

- No locks are held while business logic runs; conflicts are rare and cheap.
- A retry can legitimately turn into a business error (the competing
  withdrawal used the funds); `AccountCommandsTest` reproduces exactly this
  race by injecting a competing write between load and append.
- Under sustained contention on one account the caller can still receive
  `409 concurrency_conflict` after the last attempt; clients retry with the
  same `Idempotency-Key`.
