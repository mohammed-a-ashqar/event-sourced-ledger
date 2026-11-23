# ADR 0005: Transfers as a saga with compensation, not one transaction

- Status: accepted
- Date: 2026-11-23

## Context

Each account is an aggregate: its stream is the consistency boundary, and
one command changes one aggregate. A transfer touches two accounts. Writing
both streams in a single database transaction would work in this monolith,
but it couples the aggregates and stops working the day accounts live in
different shards or services.

## Decision

- A `Transfer` aggregate records the progress of the transfer.
- `TransferProcessManager` drives it step by step, each step being its own
  append: debit the source; credit the destination; if the credit is refused
  (frozen account, other currency), reverse the debit (compensation).
- Money in flight is visible in double-entry terms: the debit credits a
  `transfer-clearing` ledger account, the credit or the reversal debits it,
  so the clearing account is zero whenever no transfer is in flight.
- Every account step is idempotent per transfer id (the account remembers
  which transfer legs it applied), so a step can be repeated safely.
- `ledger:transfers:resume` (scheduled every five minutes) picks up transfers
  that stopped in a non-final state, e.g. after a crash, and drives them to
  `completed` or `compensated`.

## Consequences

- Between the steps, the source has been debited and the destination not yet
  credited. This intermediate state is real and is reported by the API
  (`source_debited`, `credit_failed`).
- A transfer whose source refuses the debit ends as `rejected` with nothing
  moved; the HTTP API still answers `201` because the transfer was recorded.
- Crash recovery depends on the scheduler running; without it a stalled
  transfer waits until someone runs the command.
