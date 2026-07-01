# ssdev/laravel-api-contracts

API response contract snapshot testing for Laravel — catch breaking changes before they hit production.

---

## How it works

1. You write contract tests that hit your API endpoints and call `assertMatchesApiContract()`
2. On first run, the response shape is saved as a JSON snapshot (committed to git)
3. On every subsequent `git push`, the hook re-runs those tests and compares against the snapshots
4. If a field was removed or a type changed, the push is **blocked** — you see exactly what broke
5. New fields (additive changes) are reported but never block a push

Snapshots are plain JSON files committed alongside your code. Any contract change is a visible git diff.

---

## Installation

```bash
composer require ssdev/laravel-api-contracts --dev
```

```bash
php artisan api:contract:install
```

This sets up:
- `tests/snapshots/api/` — snapshot directory (commit this)
- `.githooks/pre-commit` — warns about routes with no snapshot coverage
- `.githooks/pre-push` — blocks push if existing contracts are broken
- `git config core.hooksPath .githooks`

---

## Generating test stubs

Scan your API routes and generate a test file automatically:

```bash
php artisan api:contract:generate --prefix=api/v1
```

This produces `tests/Feature/ApiContractTest.php` with one test per GET route, ready to fill in:

```php
<?php

use Ssdev\ApiContracts\Testing\InteractsWithApiContract;

uses(InteractsWithApiContract::class);
uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

// TODO: fill in your auth headers
function contractHeaders(): array
{
    return ['X-API-KEY' => '...', 'Accept' => 'application/json'];
}

// ---------------------------------------------------------------------------
// GET /api/v1/products
// ---------------------------------------------------------------------------

it('GET /api/v1/products matches contract', function () {
    $response = $this->withHeaders(contractHeaders())->getJson('/api/v1/products');
    $response->assertStatus(200);
    $this->assertMatchesApiContract('GET_api_v1_products', $response->json());
});

// ---------------------------------------------------------------------------
// GET /api/v1/products/{id}
// ---------------------------------------------------------------------------

it('GET /api/v1/products/{id} matches contract', function () {
    $id = 1; // TODO: replace with a valid id
    $response = $this->withHeaders(contractHeaders())->getJson("/api/v1/products/{$id}");
    $response->assertStatus(200);
    $this->assertMatchesApiContract('GET_api_v1_products_show', $response->json());
});
```

Non-GET routes are generated as commented-out stubs for you to implement manually.

After filling in the auth setup, generate the initial snapshots:

```bash
php artisan api:contract:update
```

### Adding tests for new routes

When you add new routes later, run with `--merge` to append only the missing tests without touching existing ones:

```bash
php artisan api:contract:generate --merge
```

---

## Git hooks

### pre-commit — coverage check

Every commit checks whether any API routes are missing snapshot coverage and warns you:

```
  [api:contract] Coverage warning:
  → POST /api/v1/orders
  → DELETE /api/v1/orders/{id}
  Run php artisan api:contract:generate --merge to add test stubs.
  (this is a warning only — commit is not blocked)
```

### pre-push — contract enforcement

Every push runs your contract tests. If a breaking violation is detected, the push is blocked:

```
Running API contract tests...

[GET_api_v1_products] BREAKING API contract violation:
  ✖ [BREAKING] Field removed: 'data.products[].sku' (was 'string')
  ✖ [BREAKING] Type changed: 'data.products[].price' was 'integer', now 'string'
  + [NEW]       New field: 'data.products[].discount'

If INTENTIONAL: run  php artisan api:contract:update
  then commit the snapshot files and push again.

If ACCIDENTAL:  fix the code before pushing.

Accept these changes and update snapshots now? (y/N)
```

If you answer `y`, snapshots are regenerated in place. You commit them and push again — the updated contract becomes part of the same push.

---

## Violations

| Type | Meaning | Blocks push? |
|---|---|---|
| `REMOVED` | Field existed in snapshot, now missing | **Yes** |
| `TYPE_CHANGED` | Field type changed (e.g. `integer` → `string`) | **Yes** |
| `NEW` | Field added, not in snapshot | No |

A `null` value in a snapshot is treated as "unknown type" — if the field later returns a real value, it is not flagged as a type change.

---

## Commands

| Command | Description |
|---|---|
| `api:contract:install` | Install hooks, snapshot dir, git config |
| `api:contract:generate --prefix=api/v1` | Generate test stubs from routes |
| `api:contract:generate --merge` | Add tests for new routes only |
| `api:contract:update` | Regenerate all snapshots |
| `api:contract:coverage --prefix=api/v1` | Report routes with no snapshot coverage |

---

## Writing tests manually

If you prefer to write tests by hand, use the `InteractsWithApiContract` trait directly:

**Pest:**
```php
use Ssdev\ApiContracts\Testing\InteractsWithApiContract;

uses(InteractsWithApiContract::class);

it('products index matches contract', function () {
    $response = $this->getJson('/api/v1/products');
    $response->assertStatus(200);
    $this->assertMatchesApiContract('GET_products', $response->json());
});
```

**PHPUnit:**
```php
use Ssdev\ApiContracts\Testing\InteractsWithApiContract;

class ApiContractTest extends TestCase
{
    use InteractsWithApiContract;

    public function test_products_index(): void
    {
        $response = $this->getJson('/api/v1/products');
        $this->assertMatchesApiContract('GET_products', $response->json());
    }
}
```

---

## Snapshot format

Snapshots capture the type shape of your response, not the actual values:

```json
{
    "success": "boolean",
    "data": {
        "products": [
            {
                "id": "integer",
                "name": "string",
                "price": "double",
                "status": "string",
                "brand": {
                    "id": "integer",
                    "name": "string",
                    "logo_url": "null"
                }
            }
        ],
        "pagination": {
            "current_page": "integer",
            "per_page": "integer",
            "total": "integer",
            "last_page": "integer"
        }
    },
    "message": "string"
}
```

Arrays are represented by the shape of their first element. Committing these files gives you a permanent, reviewable record of your API contract — any change is visible as a `git diff`.

---

## Configuration

```bash
php artisan vendor:publish --tag=api-contract-config
```

```php
// config/api-contract.php

return [
    'snapshot_dir' => 'tests/snapshots/api',   // where snapshots are stored
    'test_path'    => 'tests/Feature/ApiContractTest.php', // used by update + hook
    'test_flags'   => '--no-coverage',          // extra flags for test runner
    'update_env'   => 'API_CONTRACT_UPDATE',    // env var that triggers snapshot write
    'route_prefix' => 'api',                    // prefix for generate + coverage commands
    'hooks_dir'    => '.githooks',              // where hooks are installed
];
```

---

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12

---

## License

MIT
