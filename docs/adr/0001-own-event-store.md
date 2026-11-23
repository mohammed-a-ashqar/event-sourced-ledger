# ADR 0001: Write the event store instead of using a library

- Status: accepted
- Date: 2026-11-23

## Context

Mature PHP event sourcing libraries exist (spatie/laravel-event-sourcing,
EventSauce, Prooph). They solve the problem well, but they also hide the
parts this project exists to show: how a stream is appended with optimistic
concurrency, how aggregates are reconstituted, how schemas evolve, how
projections keep their position.

## Decision

Implement a small event sourcing kernel in `src/EventSourcing` with no
framework dependency: `AggregateRoot`, `EventStore` port, `AggregateRepository`,
snapshots, a type registry, a serializer and an upcaster chain. The database
adapter (`DatabaseEventStore`) is one class on top of Laravel's query builder.

The kernel is held to the same standard as the rest of the code: the same
contract test suite runs against the in-memory and the database stores, and
an architecture test forbids it from importing anything outside itself.

## Consequences

- Every mechanism is visible and covered by tests, and the kernel is a few
  hundred lines.
- Features a library would bring are deliberately missing: event stream
  subscriptions over a message bus, multi-tenant streams, archiving, a UI.
- If the project grew, replacing the kernel with EventSauce would touch the
  infrastructure layer and the aggregates' base class, not the domain rules.
