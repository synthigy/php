# Synthigy PHP SDK

Thin, low-dependency (ext-curl + ext-json only, both bundled with virtually
every PHP install) client for Synthigy's `/data` endpoint.

**Scope: CRUD + auth + codegen.** There is no watch/SSE/subscriptions layer
— PHP's dominant runtime (PHP-FPM, one process per request) has no place
to hold a long-lived streaming connection, which is the whole premise of
the watch layer the other SDKs ship. A future watch port would need a
long-lived worker process (Swoole, RoadRunner, ReactPHP or a daemonized
CLI) to hold the SSE connection and somewhere to fan deltas out to
request handlers — neither is assumed here. Everything else — reads,
writes, XSQL, auth, typed codegen — is present.

## Install

```bash
composer require synthigy/sdk
```

Requires PHP ≥ 8.1 with `ext-curl` and `ext-json`. Composer also links the
`synthigy-codegen` CLI into `vendor/bin/`.

## Hello world

```php
use Synthigy\Synthigy;

use function Synthigy\eq;
use function Synthigy\gt;

Synthigy::connect('https://synthigy.example.com',
    clientId: 'my-service', clientSecret: getenv('SYNTHIGY_SECRET'));

$users = Synthigy::search('User',
    ['active' => eq(true), 'age' => gt(18), '_limit' => 10],
    ['name' => null, 'email' => null, 'roles' => ['name' => null]],
    actingAs: $userXid);
```

## One process, one client

`Synthigy::connect()` installs a process-wide default; every static verb
(`Synthigy::search()`, `Synthigy::sync()`, ...) delegates to it. **Identity
is multiplexed per-call via `actingAs:`, never a second connect().**
Constructing `new Synthigy\Client(...)` directly is the escape hatch for
tests or a genuine multi-endpoint script.

## Reads

```php
$movies = Synthigy::search('Movie', ['_limit' => 5], ['title' => null]);
$movie  = Synthigy::get('Movie', ['xid' => 'm-1'], ['title' => null]); // flat unique-key args, null if missing

$rows = Synthigy::sqlTemplate(
    'SELECT COUNT(*) AS n FROM {movie} WHERE {movie.release_year} > ?', [1990]);

// XSQL — string query surface (server parses/compiles; GraphQL model)
$rows = Synthigy::query(<<<'XSQL'
movie (release_year > ?y:int, _limit 10)
  title
  ->genres
    name
XSQL, ['y' => 1990]);
```

- Operators: `eq neq gt gte lt lte in_ nin like ilike isNull isNotNull` and
  `and_ / or_ / not_` — `and`/`or`/`not` are reserved words in PHP, hence
  the underscore, same reason as the Python SDK. Wire mapping note:
  `gte` → `_ge`, `lte` → `_le`.
- Selections mirror the shape you want back: `null` = scalar, a nested
  array = relation. The server's join default is **LEFT** — a projected
  relation never drops its parent, and relation args filter the related
  rows. To scope parents to those HAVING the relation, be explicit:
  `rel([...], args: ['_join' => 'inner'])`. The SDK injects nothing.
- **Empty relations are omitted** by the wire, never `[]` — check with
  `$movie['genres'] ?? []`.
- Counting/aggregation has **no dedicated op**: use `sqlTemplate` or XSQL
  `_count`/`_agg` selections.

## Writes

```php
Synthigy::sync('Movie', ['xid' => 'm-1', 'title' => 'Dune',
    'genres' => [['xid' => 'g-scifi']]]);      // upsert, REPLACES link-sets
Synthigy::stack('user_rating', ['value' => 5, 'movie' => ['xid' => 'm-1']]); // additive
Synthigy::slice('Movie', ['xid' => 'm-1'], ['genres' => [['xid' => 'g-scifi']]]); // unlink
Synthigy::delete('Movie', ['xid' => 'm-1']);   // soft delete
Synthigy::purge('user_rating', ['value' => ['_lt' => 2]]);  // hard delete by filter
```

Batch heterogeneous ops in one round trip:

```php
use function Synthigy\opSlice;
use function Synthigy\opStack;

$results = Synthigy::exec([
    opSlice('User', ['xid' => $u], ['roles' => [['xid' => $old]]]),
    opStack('User', ['xid' => $u, 'roles' => [['xid' => $new]]]),
], actingAs: $userXid);
```

## Errors

Everything throws `Synthigy\SynthigyError` (extends `RuntimeException`)
with stable `->code`, derived `->category` (`auth | iam | validation |
not_found | conflict | rate_limit | network | internal`) and `->retryable`.
Structured fields when the server sends them: `->hint`, `->entity`,
`->path`, `->errorLine`/`->col` (an XSQL source position — named
`errorLine` rather than `line` because `\Exception` itself already
declares a non-nullable `$line`, the throw site, and PHP won't let a child
class redeclare it with an incompatible type), `->diagnostics`,
`->requestId` (matches the `X-Request-Id` the SDK sends — correlate with
server logs). Discriminate on `->code`, never the message.

## Auth

- Client credentials (`clientId`+`clientSecret`): tokens minted from
  `/oauth/token`, cached per audience, refreshed 30s before expiry, one
  automatic clear-and-retry on 401.
- Static `token: '...'` for scripts/tests (`token: ''` for authless dev).
- With none of the above, resolution continues: under
  `SYNTHIGY_SUPERVISED=1` the SDK asks its supervising parent
  (`synthigy exec`/`agent`, or a robotics commander) for a token over the
  process's own stdio — **CLI-only**, meaningless under PHP-FPM (no stdio
  to a parent there) — then falls back to the `SYNTHIGY_TOKEN` env var,
  then throws `SynthigyError(code: 'NO_TOKEN')` with a message that teaches
  the fix.
- `actingAs` is server-verified impersonation for **trusted confidential**
  clients (the BFF model) — the SDK never handles end-user OAuth
  redirects.
- `audience`: some servers require an explicit `client_credentials`
  audience for `/data` access — the platform's audience model is opt-in by
  design (a token minted with no audience resolves to an identity-only
  one, regardless of the client's roles or API links; there is no
  server-side default to configure around this). Pass `audience:
  'https://synthigy.com'` (or whatever your server's `/data` audience is)
  once at `connect()`/`Client` construction and every call this Client
  makes uses it automatically — no need to thread it through every
  `search()`/`sync()`/etc. call. `Client::token($audience)` still accepts
  a per-call override for minting tokens for a *different* audience (e.g.
  a third-party service Synthigy federates for).

## Codegen

`bin/synthigy-codegen` turns an `.xsql` operations document + the server
schema into one typed PHP file (inline PHPDoc `array{...}` shapes over
plain associative arrays + static-method namespace classes). The server
owns the XSQL grammar — `op: "describe"` compiles the source and returns a
language-neutral IR; the emitter renders PHP from IR JSON and parses
nothing.

```bash
# 1. Pull the IAM-filtered schema (commit it)
SYNTHIGY_CLIENT_ID=... SYNTHIGY_CLIENT_SECRET=... \
  bin/synthigy-codegen pull http://localhost:7887 schema.json

# 2. Describe + generate (saves movies.ir.json beside the .xsql — commit
#    schema.json + .xsql + .ir.json; after that, gen runs OFFLINE forever)
bin/synthigy-codegen gen movies.xsql          # -> movies_gen.php

# 3. CI drift gate: offline sourceHash check ("edited .xsql, forgot
#    codegen"); live describe-diff when SYNTHIGY_* creds are present
bin/synthigy-codegen check movies.xsql
```

```php
use Synthigy\Synthigy;
use Generated\Movies;
use Generated\Batches;
use Generated\Writes;

Synthigy::connect($endpoint, clientId: ..., clientSecret: ...);

$rows = Movies::topMovies(['since' => 2000]);     // list<array{xid: string, title: string, ...}>
$one  = Movies::movieDetail();                    // array{...}|null  (a @get op)
$both = Batches::overview();                      // @batch -> ONE round trip, keyed by member name
Writes::syncMovie(['title' => 'Dune', 'genres' => [['xid' => 'g-scifi']]]);
```

Codegen authenticates **as the app** — the same client credentials the app
uses at runtime. `/schema` and `describe` are IAM-filtered per principal,
so the generated contract is exactly what the app can do; a personal/dev
identity would generate a surface the app can't honor. `--no-writes` skips
the schema-derived `Writes` class. Every generated file is checked with
`php -l` before it's written — a syntax error in the emitter is a build
failure, never a silently broken file. Row/param "types" are **inline
PHPDoc array shapes**, not a separate reusable alias tier per op — PHP has
no structural array types, and PHPDoc is never executed, so there is no
ordering constraint and no hydration code to get wrong; a shape is
documentation over the plain associative array the wire already returns,
matching this SDK's "records = plain arrays" rule all the way through
codegen. The one place a real `@phpstan-type` alias is emitted is the
per-entity `Input` shape on the `Writes` class, since `sync`/`stack` for
the same entity both reference it.

**Known v1 simplification vs. the Python/Go/JS generators:** a `@batch`
method takes one raw `array $params` forwarded verbatim to every member's
query — there is no per-member param merge/dedupe (Python's "optional
only if optional in every member" rule). Most batches are parameterless
convenience bundles in practice; a batch whose members need different
params each needs a follow-up.

An end-to-end example lives in
[synthigy/examples: movies/php](https://github.com/synthigy/examples/tree/main/movies/php)
(`movies.xsql` → `pull` + `gen` → generated typed operations, plus a `demo.php`
and a small web page that run both those and raw SDK calls against a live
server).

## Development workflow

```bash
composer install
composer analyse       # PHPStan level 8 over src/, tests/, bin/
composer test          # analyse + the hermetic unit suite (no network)
composer test:live     # analyse + the live suite (needs creds, see below)
```

`composer test` runs PHPStan **first** and fails the whole command if
analysis fails. That's deliberate: the typed tier here is PHPDoc, so
static analysis is the only thing that keeps the annotations honest —
putting the gate inside the test command means anything that runs the
tests runs the gate, with no separate CI step to forget.

### No PHP installed? Use Docker

Everything above works without a local PHP toolchain:

```bash
cd sdk/php
docker run --rm -v "$PWD":/app -w /app php:8.3-cli sh -c '
  apt-get update -qq && apt-get install -y -qq unzip
  curl -sSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
  composer install --no-interaction
  composer test
'
```

Add `--network host` when the container needs to reach a Synthigy server
on `localhost`, plus `-e SYNTHIGY_TEST_*` for the live suite.

### Live suite

`composer test:live` runs `tests/IntegrationTest.php` against a real
server. It's excluded from the default run (`@group live`) and **skips
cleanly** when credentials are absent, so it never fails a hermetic run.

```bash
SYNTHIGY_TEST_ENDPOINT=http://localhost:7887 \
SYNTHIGY_TEST_CLIENT_ID=... SYNTHIGY_TEST_CLIENT_SECRET=... \
SYNTHIGY_TEST_AUDIENCE=https://synthigy.com \
  composer test:live
```

`SYNTHIGY_TEST_TOKEN=<bearer>` works instead of the id/secret pair.
`SYNTHIGY_TEST_ENTITY` picks the entity to exercise (default `movie`).
It covers token minting, schema, search/get, sqlTemplate, XSQL, batched
`exec`, operator filtering, typed errors, and one write→read→delete round
trip that creates and removes its own record (client-minted xid, cleaned
up in a `finally`).

The unit suite (70 tests) plus PHPStan level 8 run clean in a PHP 8.3
container; there was no PHP interpreter installed in the authoring
environment itself, hence the Docker recipe above.

Beyond the unit suite, this SDK has also been exercised for real against
a **live** dev server (`localhost:7887`, a throwaway `client_credentials`
client named `synthigy-php-sdk-e2e`): `schema()` against the real
127-entity model, `search()`/`sqlTemplate()`/`query()` (XSQL)/`exec()`
(batch) against a real entity with real rows back, and the full
`pull` → `gen` → `check` codegen pipeline including *running* the
generated code. The one gotcha that verification surfaced was a
platform-level OAuth audience default, not a bug in this SDK: a client
must be linked to the platform API or `/data` answers 401.
`composer test:live` itself — a committed, creds-gated PHPUnit class
mirroring the Python SDK's integration suite — is not yet written; the
verification above was ad hoc.

Register a dedicated OAuth client for that future live suite (trusted
confidential, `client_credentials`, linked to the platform API so its
token mint can request the `/data`-capable audience) — never share
identity with a live app.

## License

MIT — see [LICENSE](LICENSE). The SDKs are permissive client libraries; the
Synthigy engine is fair-code under the Sustainable Use License.
