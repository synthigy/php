# Changelog

All notable changes to `synthigy/sdk` (Packagist). Follows
[semver](https://semver.org). Pre-1.0: breaking changes can land on minor bumps.

## 0.1.0 — unreleased

First public release. CRUD + auth + codegen; see `README.md` for the scope
decision (no watch/SSE layer) and usage.

### Added

- **Reads/writes**: `search`, `get`, `sync`, `stack`, `slice`, `delete`,
  `purge`, `sqlTemplate`, `query` (XSQL), `searchTree`/`getTree` with tree
  composition, and `exec` for batching heterogeneous ops in one round trip.
- **Static facade** `Synthigy::` over a process-wide default client
  (`Synthigy::connect()`), with identity multiplexed per call via
  `actingAs:` rather than a second connection.
- **Auth**: OAuth client credentials with per-audience token caching, 30s
  pre-expiry refresh and one clear-and-retry on 401; static tokens; the
  `SYNTHIGY_TOKEN` env var; and supervised-stdio tokens under
  `SYNTHIGY_SUPERVISED=1` for CLI processes run by a parent.
- **`audience` option** binding a default `client_credentials` audience
  once at construction — required where `/data` access is audience-gated,
  since a mint with no audience yields an identity-only token by design.
- **Codegen** (`bin/synthigy-codegen pull|gen|check`): compiles an `.xsql`
  document via the server's `describe` op into typed PHP — PHPDoc
  `array{...}` shapes over plain arrays, namespace classes, `@batch`
  methods, and a schema-derived write tier. Generated output is checked
  with `php -l` before it is written, and `check` gates on a `sourceHash`
  lockfile plus a live describe-diff.
- **Errors**: `SynthigyError` with stable `->code`, derived `->category`,
  `->retryable`, and structured server fields.
- Worked example in `codegen-example/`, verified against a live server.

### Notes

- Requires PHP 8.1+ with ext-curl and ext-json. No Composer runtime
  dependencies.
- `composer test` runs PHPStan level 8 before PHPUnit; the gate lives in
  the test command rather than a separate CI step.
- The XSQL source position on errors is `->errorLine`, not `->line`,
  because `\Exception` already declares the latter as the throw site.
- Known limitation: `@batch` methods forward one shared parameter array to
  every member; there is no per-member merge/dedupe yet.
