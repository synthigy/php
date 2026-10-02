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

Synthigy::connect();          // endpoint + identity from `synthigy exec`

// XSQL: the shape you write is the shape you get back
$movies = Synthigy::query(<<<'XSQL'
movie (release_year > ?since:int, limit 10)
  xid
  title
  ->genres
    name
XSQL, ['since' => 1990], actingAs: $userXid);
```

Run it with `synthigy exec -- php app.php`.

## One process, one client

`Synthigy::connect()` installs a process-wide default; every static verb
(`Synthigy::query()`, `Synthigy::sync()`, ...) delegates to it. **Identity
is multiplexed per-call via `actingAs:`, never a second connect().**
Constructing `new Synthigy\Client(...)` directly is the escape hatch for
tests or a genuine multi-endpoint script.

## Reads

```php
$movies = Synthigy::query(<<<'XSQL'
movie (release_year > ?y:int, limit 10)
  title
  ->genres
    name
XSQL, ['y' => 1990]);

$movie = Synthigy::query(<<<'XSQL'
movie (xid = ?xid:string)
  title
XSQL, ['xid' => $xid], op: 'get');         // one record, or null

$rows = Synthigy::sqlTemplate(
    'SELECT COUNT(*) AS n FROM {movie} WHERE {movie.release_year} > ?', [1990]);
```

- `?name:type=default` are named params, passed as an array.
- `->genres` is a **left** pull — a movie with no genres is still returned;
  `-genres` is **inner** and keeps only movies that have one.
- **Empty relations are omitted**, never `[]` — use `$movie['genres'] ?? []`.
- Counting and aggregation compute in the database: `_count` / `_agg` in XSQL,
  or `sqlTemplate`.

## Writes

```php
Synthigy::sync('movie', ['xid' => $xid, 'title' => 'Dune']);               // upsert, REPLACES link-sets
Synthigy::stack('user_rating', ['value' => 5, 'movie' => ['xid' => $xid]]); // additive
Synthigy::delete('movie', ['xid' => $xid]);                               // soft delete
```

`slice` (unlink without deleting) and `purge` (hard delete by filter) exist
too.

Several operations in one round trip:

```php
use function Synthigy\opQuery;
use function Synthigy\opStack;

$results = Synthigy::exec([
    opQuery("movie (limit 3)\n  title"),
    opStack('user_rating', ['value' => 4, 'movie' => ['xid' => $xid]]),
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

`->retryable` is true for `network`, `rate_limit` and `internal` codes, and
for any error the server marks retryable. A code this SDK doesn't know yet
is category `internal` but not retryable.

## Auth

- Client credentials (`clientId`+`clientSecret`): tokens minted from
  `/oauth/token`, cached per audience, refreshed 30s before expiry, one
  automatic clear-and-retry on 401. The cache outlives the request — see
  [Reusing tokens across requests](#reusing-tokens-across-requests).
  A refused mint is `INVALID_CLIENT` (wrong id or secret, inactive client),
  `INVALID_SCOPE`, or `INVALID_AUDIENCE` (the client isn't linked to the
  API), else `UNAUTHORIZED`; the message carries the server's
  `error_description`.
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

### Reusing tokens across requests

PHP forgets everything between requests, so a client that kept its token only
in memory would mint a new one on every page view. Client-credentials tokens
therefore go to a `TokenStore`, and every request reuses the token until 30s
before it expires. The default is a `FileTokenStore`: one `0600` file per token
in a `0700` directory under the system temp dir, one directory per OS user.
Nothing to configure.

- File names are an HMAC of the endpoint, client, scope and audience keyed by
  the client secret, so a token is only reused by the credentials that minted
  it, and a rotated secret mints a new one.
- A 401 deletes the stored token, so every worker mints a fresh one.
- The store is a cache, never a failure: if the directory can't be created, is
  a symlink, or belongs to another user — or a custom store throws — the SDK
  mints per request instead.
- `tokenStore: new FileTokenStore('/var/cache/my-app')` picks the directory;
  `tokenStore: false` keeps tokens in the process only.

Any shared cache works. APCu, shared by every PHP-FPM worker on the host:

```php
final class ApcuTokenStore implements Synthigy\TokenStore
{
    public function get(string $key): ?array
    {
        $v = apcu_fetch('synthigy:' . $key, $ok);
        return $ok && is_array($v) ? $v : null;
    }
    public function put(string $key, string $token, float $expiresAt): void
    {
        apcu_store('synthigy:' . $key, ['token' => $token, 'expires_at' => $expiresAt],
            max(1, (int)($expiresAt - time())));
    }
    public function delete(string $key): void { apcu_delete('synthigy:' . $key); }
}

Synthigy::connect(clientId: 'my-app', clientSecret: getenv('APP_SECRET'),
    tokenStore: new ApcuTokenStore());
```

### Logging users in (`loginStart` / `loginComplete`)

Authorization code + PKCE for a confidential server: the SDK owns the
protocol, your app owns sessions, cookies and routing. `loginStart()`
returns a URL, `loginComplete()` takes the callback's `code`/`state`. The
in-flight login lives in a `LoginStore` you pass as `loginStore:` —
`put($state, $login)` and one-shot `take($state)`.

Under PHP-FPM every request is a new process, so keep logins in the
session: `SessionLoginStore` does, and drops logins older than its TTL
(`ttlSeconds:`, default 600). `MemoryLoginStore` is for one long-lived
process only (tests, RoadRunner, Swoole); under FPM every login would fail
`LOGIN_STATE_UNKNOWN`.

```php
session_start();
Synthigy::connect(clientId: 'my-app', clientSecret: getenv('APP_SECRET'),
    loginStore: new Synthigy\SessionLoginStore());

// /login
header('Location: ' . Synthigy::loginStart(CALLBACK, returnTo: $_GET['returnTo'] ?? '/')['url']);

// /auth/callback
if (isset($_GET['error'])) {                       // the user cancelled at the IdP
    header('Location: ' . (Synthigy::loginCancel($_GET['state'] ?? '')['return_to'] ?? '/'));
    exit;
}
$done = Synthigy::loginComplete($_GET['code'], $_GET['state'], CALLBACK);
$_SESSION['user'] = $done['user'];                 // ['xid', 'name', 'scopes']
header('Location: ' . $done['return_to']);
```

- `$done['user']['xid']` is read from the id_token — no `/data` lookup. Pass
  it as `actingAs:` to act on the user's behalf.
- No store → `NO_LOGIN_STORE`; no `clientSecret` →
  `LOGIN_REQUIRES_CONFIDENTIAL_CLIENT`; `SessionLoginStore` without
  `session_start()` → `SESSION_NOT_STARTED`. Other codes:
  `LOGIN_STATE_UNKNOWN`, `LOGIN_NONCE_MISMATCH`, `LOGIN_EXCHANGE_FAILED`
  (with `->status`).
- Don't make the session cookie `SameSite=Strict`: the IdP's redirect back is
  a cross-site navigation, a Strict cookie isn't sent on it, and the callback
  fails `LOGIN_STATE_UNKNOWN`.
- `publicEndpoint:` when the browser reaches the IdP on a different URL than
  PHP does (containers, reverse proxies).
- The code exchange never retries: an authorization code is one-shot.

## Code generation

Write your queries in `.xsql` files and get typed functions for them. The
server compiles the queries, so the types always match what it returns.

**1. Get a server.** In your project folder:

```bash
synthigy env init
synthigy up
```

The first `up` prints a `/setup` link; open it and pick a database. (No
browser? `synthigy up --db sqlite` skips the wizard.) Already have a server?
Skip this step.

**2. Deploy your data model** in the modeler (or from code with `Synthigy::deploy()`, as a
client with the Dataset Developer role).

**3. Connect as your app.** Create its client once, then save it to the
project:

```bash
synthigy iam add-client "My App" --id my-app --type confidential \
  --role "Dataset Explorer" --api Synthigy --grant client_credentials --local
synthigy connect http://localhost:7887 --client-id my-app
```

`add-client` prints the secret once; `connect` asks for it. Code is generated
for what this app is allowed to see. (`--local` works on the server's own
machine; for a remote server, create the client in the console.)

**4. Install the SDK:**

```bash
composer require synthigy/sdk
```

**5. Write a query** in `xsql/movies.xsql`:

```
@search list
movie (release_year > ?since:int=1990, limit ?limit:int=20)
  xid
  title
  release_year
```

**6. Generate:**

```bash
synthigy exec -- vendor/bin/synthigy-codegen gen xsql/ --out gen/ops.php
```

This writes `gen/ops.php` (namespace `Generated`, change it with `--namespace`), and saves `xsql/schema.json` and `xsql/ops.ir.json`
next to your queries.

**7. Use it:**

```php
<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/gen/ops.php';

use Synthigy\Synthigy;

Synthigy::connect();
$movies = \Generated\Movie::list(['since' => 2000]);
echo json_encode(array_column($movies, 'title')), "\n";
```

```bash
synthigy exec -- php app.php
```

`synthigy exec` gives your program the server address and the app's identity.
Without it, pass them yourself: `Synthigy::connect($endpoint, clientId: ..., clientSecret: ...)`.

**After you edit a query**, run step 6 again. As long as the `.xsql` files are
unchanged it works offline from `xsql/ops.ir.json`; after an edit it needs the
server, and it never generates from outdated results. In CI:

```bash
vendor/bin/synthigy-codegen check xsql/
```

**What to commit:** your `.xsql` files and `xsql/ops.ir.json`.
`xsql/schema.json` is your whole data model, so commit it only in a private
repo.

`@batch` ops become one-round-trip methods on `Batches`, and every entity gets typed `Writes::sync<Entity>` / `stack<Entity>` / `delete<Entity>`. Every generated file passes `php -l` before it is written. A `@batch` method forwards one `$params` array to every member query.

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
# + SYNTHIGY_TEST_LOGIN_CLIENT_ID/_SECRET/_USER/_PASSWORD for the headless browser-login case
```

`SYNTHIGY_TEST_TOKEN=<bearer>` works instead of the id/secret pair.
`SYNTHIGY_TEST_ENTITY` picks the entity to exercise (default `movie`).
It covers token minting, schema, search/get, sqlTemplate, XSQL, batched
`exec`, operator filtering, typed errors, and one write→read→delete round
trip that creates and removes its own record (client-minted xid, cleaned
up in a `finally`).

## License

MIT — see [LICENSE](LICENSE). The SDKs are permissive client libraries; the
Synthigy engine is fair-code under the Sustainable Use License.
