# Codegen example — movies

An end-to-end example (same MovieLens-shaped dataset the other SDKs'
examples use), committed as the four artifacts codegen produces + a demo
script that runs against a live server:

| File | What it is |
|---|---|
| `movies.xsql` | Hand-written source: two `@search`/`@get` reads, a `@sql-template`, a `@batch` |
| `schema.json` | `bin/synthigy-codegen pull` output — the IAM-filtered `/schema` snapshot |
| `movies.ir.json` | `bin/synthigy-codegen gen` output — the server's `op:"describe"` IR + a `sourceHash` lockfile |
| `movies_gen.php` | The generated typed PHP (namespace `Synthigy\Examples\Movies`) |
| `demo.php` | Runs against a real server: raw SDK calls, the generated typed operations, and the `@batch` |

## Regenerating

```bash
cd sdk/php
composer install
SYNTHIGY_CLIENT_ID=... SYNTHIGY_CLIENT_SECRET=... \
  bin/synthigy-codegen pull http://localhost:7887 codegen-example/schema.json
SYNTHIGY_CLIENT_ID=... SYNTHIGY_CLIENT_SECRET=... \
  bin/synthigy-codegen gen codegen-example/movies.xsql \
    --endpoint http://localhost:7887 --namespace 'Synthigy\Examples\Movies' --pull
```

(`--pull` forces a fresh `describe` instead of reusing the cached
`movies.ir.json`, e.g. after editing `movies.xsql`.)

## Running the demo

```bash
SYNTHIGY_ENDPOINT=http://localhost:7887 \
SYNTHIGY_CLIENT_ID=... SYNTHIGY_CLIENT_SECRET=... \
  php codegen-example/demo.php
```

`SYNTHIGY_TOKEN=<static token>` works too instead of client id/secret — see
the root `README.md`'s Auth section for what each is for, and the
**important gotcha**: a `client_credentials` mint needs its client linked
to the platform's `/data` API, or the token it mints will be
identity-only and every call here will 401. This was hit and root-caused
while building this example — see `docs/plans/PLAN-PHP-SDK.md`.

This was run for real against a live dev server while building this SDK —
not just generated and syntax-checked. Sample output (a dev seed dataset,
titles included as seen, not curated):

```
Dashboard (generated Dashboard::stats())
----------------------------------------
  9754 movies, 101059 ratings (avg 3.50), 1465 actors

Movies since 1990, with genres + rating/actor counts (generated Movie::list())
------------------------------------------------------------------------------
  A Wrinkle in Time (2100)                      [Adventure, Children, Fantasy, Film-Noir, Horror, Mystery, Sci-Fi]
      19 ratings (avg 3.95) · 5 cast
  Novi film (2028)                              [Action, Drama, Mystery, Romance]
      5 ratings (avg 4.00) · 8 cast
```
