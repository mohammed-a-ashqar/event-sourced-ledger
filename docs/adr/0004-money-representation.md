# ADR 0004: Money as integer minor units with an ISO 4217 currency

- Status: accepted
- Date: 2026-11-23

## Context

Floating point cannot represent most decimal amounts exactly, and a ledger
must balance to the last cent. Decimal strings are exact but awkward to do
arithmetic with, and different currencies have different numbers of
decimals (JPY 0, EUR 2, KWD 3).

## Decision

- `Money` holds a PHP `int` of minor units plus a `Currency`.
- Arithmetic is integer-only and overflow-checked; PHP would otherwise turn
  an overflowing int into a float silently.
- Operations between different currencies throw `CurrencyMismatch`; there is
  no implicit conversion anywhere.
- `Currency` knows its ISO 4217 exponent, used to parse and format decimals
  digit by digit (`Money::fromDecimal('12.5', EUR)` is 1250, without floats).
- The API speaks minor units only (`"amount": 1250`) and adds a `formatted`
  string for display. JSON floats are rejected by validation.
- Amounts accepted by the API are capped at 10^15 minor units so that sums
  stay far inside the 64-bit range.

## Consequences

- Every stored amount is exact, in events, read models and JSON.
- The upcaster for `MoneyDeposited` v1 (which stored `"12.50"`) uses the same
  float-free parser to produce minor units.
- Amounts above about 9.2 x 10^18 minor units cannot be represented; that is
  accepted for a wallet ledger.
