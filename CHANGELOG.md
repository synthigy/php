# Changelog

All notable changes to `synthigy/sdk` (Packagist). Follows
[semver](https://semver.org). Pre-1.0: breaking changes can land on minor bumps.

## 0.2.2

### Added
- **`SessionLoginStore`** — a `LoginStore` over `$_SESSION` with a TTL
  (`ttlSeconds:`, default 600), so a PHP-FPM app no longer copies one from the
  README. That sample never expired a login, though `created_at` is stored.
  Without an active session it throws `SESSION_NOT_STARTED`. `composer.json`
  suggests `ext-session` for it.

### Fixed
- **A login may take 10 minutes, not 5.** Synthigy's login page allows 8, so a
  user who took 5–8 minutes logged in there and then failed the callback with
  `LOGIN_STATE_UNKNOWN`. Both stores default to `LoginStore::TTL_SECONDS`
  (600).
- **`loginComplete()` and `loginCancel()` refuse a login older than that
  themselves.** Expiry used to live only in the store, so a custom store that
  never expired accepted a login of any age.
- **Permission denials are `iam`, not retryable `internal`.**
  `RELATION_FORBIDDEN`, `ATTRIBUTE_FORBIDDEN`, `ROW_FORBIDDEN`,
  `CREATE_FORBIDDEN`, `DELETE_FORBIDDEN` and `SLOT_OCCUPIED` were missing from
  the code table, so a denied relation read came back as a retryable server
  error.
- **A code the SDK doesn't know is no longer retryable.** It stays category
  `internal`, but `->retryable` is true only when the server marks the error
  retryable (it does for transient database failures) or the code is a known
  `network`/`rate_limit`/`internal` one.
- **A refused token mint says why.** `invalid_client` →
  `INVALID_CLIENT` (with a hint to check the client id and secret),
  `invalid_scope` → `INVALID_SCOPE`, `invalid_target` → `INVALID_AUDIENCE`;
  other refusals stay `UNAUTHORIZED`. The message carries the server's
  `error_description` and `->details` the decoded OAuth error. A wrong secret
  used to read the same as an expired session.

## 0.2.1

### Added
- **Client-credentials tokens are reused across requests.** They were cached in
  memory only, and PHP starts every request empty, so each page view minted a
  new token (a round trip to `/oauth/token` per request). Tokens now go to a
  `TokenStore`, passed as `tokenStore:` to `Synthigy::connect()` / `new Client()`.
  The default `FileTokenStore` keeps them in a per-user `0700` directory under
  the system temp dir; `false` restores in-process only. Entries are keyed by an
  HMAC of endpoint, client, scope and audience under the client secret, a 401
  deletes the stored token, and an unusable directory — or any store that
  throws — falls back to minting.
  The README shows an APCu store.

## 0.2.0

### Added
- **Codegen takes several `.xsql` files or directories.** `gen` and `check`
  accept any mix of files and directories (searched recursively, sorted by
  path); one file is generated for all of them. The default output is
  `<name>_gen.php` for a single file and `ops_gen.php` otherwise.
- **`sync`/`stack` document the list form**: one record, or a list of them in
  one operation (bulk import). It always worked at runtime; the docblocks
  (client, facade, `opSync`/`opStack`, and the generated `sync<Entity>` /
  `stack<Entity>`) typed it as one record, so PHPStan refused a bulk call.
- **Browser login: `loginStart` / `loginComplete` / `loginCancel`** — on
  `Client` and the `Synthigy` facade. OIDC authorization code + PKCE for a
  confidential client, same contract as the JS and Python SDKs. In-flight
  logins live in a `LoginStore` passed as `loginStore:` (README has a
  `$_SESSION` one; `MemoryLoginStore` is single-process and useless under
  PHP-FPM). `$clientId`/`$clientSecret` are now kept even alongside a static
  `$token`, since the code exchange needs them.
- **`Synthigy::connect()` / `new Client()` take the endpoint from
  `SYNTHIGY_ENDPOINT` when none is passed.** Under `synthigy exec` that and the
  identity are already set, so `Synthigy::connect()` with no arguments is the
  whole setup. No endpoint anywhere is `NO_ENDPOINT` (category `validation`,
  not retryable); it was `INVALID_BODY`.
- **`compile($source, $params, $op)`** — on `Client` and on the `Synthigy`
  static facade. `POST /compile` returns the wire operation the engine would
  execute for an XSQL source, without executing it. The compiler is the
  authority on the wire format, so this is how you take programmatic control of
  a query instead of hand-writing the array: edit what comes back and pass it
  to `exec()`. `$params` bind exactly as on `query()`, so the result IS what the
  engine receives.

### Changed
- **Codegen reads and writes `ops.ir.json`** in the `.xsql` directory, the IR
  file every other SDK's generator shares (was `<file>.ir.json` beside each
  source). A CI `check` gate pointing at the old file must be re-pointed;
  the first `gen` writes the new one.

### Fixed
- **Codegen:** each `.xsql` file is sent to `describe` separately (`sources: [{path,
  source}]`) instead of one concatenated text. Concatenation leaked the
  first file's buffer-level `@namespace` into every later file and dropped
  the others'. Needs an engine with per-file `describe`. A `(namespace,
  name)` declared twice across files now fails with `DUPLICATE_OPERATION`,
  naming both files; a `@batch` naming an op from another file fails with
  `BATCH_MEMBER_UNRESOLVED`. Both are `validation` in the error table.
  A server error from `describe` is now reported as `codegen: describe
  failed: CODE: message`; it used to be an uncaught fatal error.
- **`TIMEOUT` is category `rate_limit`**, as in the Go, JS and Python SDKs; it
  was `network`. Still retryable.
- **`synthigy-codegen gen --pull xsql/` no longer fails with a usage error.**
  `--pull` and `--no-writes` swallowed the next argument as their value, so
  the order every other SDK's generator takes did not work here.
- **`--out` into a directory that does not exist yet creates it.** The write
  failed and the error blamed `php -l`, because the write itself was never
  checked. The first generation after a clean clone always hit this.
- **`gen` fetches `xsql/schema.json` itself** when it is missing, and
  refreshes it with `--pull`, like the other SDKs' generators. A first run
  used to stop and ask for a separate `pull`.
- **Regenerating from a current `ops.ir.json` works offline.** `gen` demanded
  an endpoint before looking at the cache; it now asks for one only when the
  `.xsql` has changed, and says so.

## 0.1.1

### Added
- **`deploy($exportContents)` and `destroy($datasetXid)`** — on `Client` and on
  the `Synthigy` static facade, with `opDeploy` / `opDestroy` builders. A model
  export goes over the wire verbatim as a string and the server decodes it, so
  no client needs a transit codec: `deploy` posts the export file's bytes, and
  `destroy` is `delete` on the `dataset` meta-entity. The deploy ack carries
  the dataset xid that `destroy` takes, so a caller holding nothing but the
  export can still tear down what it deployed. Both are scope-gated
  server-side (`dataset:deploy` / `dataset:delete`, which only the Dataset
  Developer role carries), so the SDK adds no permission surface of its own.

### Fixed
- `Client::$defaultAudience` was typed `?string` but the fallback chain ends at
  a non-empty constant, so it can never be null — phpstan rejected it, leaving
  `composer test` red. Now `string`. No behaviour change.

### Changed
- **The platform audience is now the default; nobody configures it.** A
  `client_credentials` mint naming no audience resolves to the identity-only
  OIDC audience, which `/data`, `/schema`, `/history`, `/logs` and
  subscriptions all reject — so every user had to set `SYNTHIGY_AUDIENCE` to a
  constant they could not look up, since the server does not advertise it in
  discovery. The failure was a bare 401 that said nothing about audiences.
  This SDK is the client for the platform API, so that is what it now mints
  for. Minting for a different API stays a per-call argument. The environment
  variable remains as an escape hatch.

## 0.1.0

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
