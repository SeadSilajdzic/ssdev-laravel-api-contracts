# ssdev/laravel-api-contracts

API response contract snapshot testing for Laravel — catch breaking changes before they hit production.

---

## What it does

Every time you push, this package verifies that your API responses haven't changed shape or types compared to stored snapshots. If something breaks — a field removed, a type changed from `integer` to `string` — the push is blocked and you're shown exactly what changed.

**Additive changes** (new fields) are detected but never block a push. Only removals and type changes are breaking.

Snapshots are plain JSON files committed alongside your code. Any contract change is visible as a git diff — no magic, no external services.

---

## Installation

```bash
composer require ssdev/laravel-api-contracts --dev
```

Run the installer to set up the snapshot directory and the pre-push git hook:

```bash
php artisan api:contract:install
```

This will:
- Create `tests/snapshots/api/`
- Write `.githooks/pre-push`
- Run `git config core.hooksPath .githooks`

---

## Writing contract tests

Create a test file (e.g. `tests/Feature/ApiContractTest.php`) and use the `InteractsWithApiContract` trait:

```php
use Ssdev\ApiContracts\Testing\InteractsWithApiContract;

uses(InteractsWithApiContract::class);

it('products index matches contract', function () {
    $partner  = Partner::factory()->create();
    $response = $this->withHeaders(['X-API-KEY' => $partner->api_key])
                     ->getJson('/api/v1/products');

    $response->assertStatus(200);
    $this->assertMatchesApiContract('GET_products', $response->json());
});
```

The snapshot name (`GET_products`) is free-form — use whatever naming convention makes sense for your project.

### PHPUnit

```php
use PHPUnit\Framework\TestCase;
use Ssdev\ApiContracts\Testing\InteractsWithApiContract;

class ApiContractTest extends TestCase
{
    use InteractsWithApiContract;

    public function test_products_index_matches_contract(): void
    {
        $response = // ... make your request
        $this->assertMatchesApiContract('GET_products', $response->json());
    }
}
```

---

## Generating snapshots

On first run (no snapshot file exists yet), `assertMatchesApiContract` writes the snapshot automatically and the test passes.

To regenerate all snapshots intentionally after a planned response change:

```bash
php artisan api:contract:update
```

This runs your contract tests with `API_CONTRACT_UPDATE=1`, rewrites every snapshot file, and prints the git commands to commit the changes.

---

## How violations work

| Violation | Type | Blocks push? |
|---|---|---|
| Field removed | `REMOVED` | Yes |
| Type changed (`integer` → `string`) | `TYPE_CHANGED` | Yes |
| New field added | `NEW` | No |

A `null` value in a snapshot is treated as "unknown type" — if the field later has a real value, it is not flagged as a type change.

---

## Pre-push hook

After `api:contract:install`, every `git push` automatically runs your contract tests. If a violation is detected, the push is blocked and you'll see:

```
Running API contract tests...

[GET_products] BREAKING API contract violation:
  ✖ [BREAKING] Field removed: 'data.products[].sku' (was 'string')
  ✖ [BREAKING] Type changed: 'data.products[].price' was 'integer', now 'string'

If INTENTIONAL: run  php artisan api:contract:update
  then commit the snapshot files and push again.

If ACCIDENTAL:  fix the code before pushing.

Accept these changes and update snapshots now? (y/N)
```

If you answer `y`, snapshots are regenerated in place. You then commit them and push again — so the updated contract is always part of the same push.

The hook resolves PHP automatically: standard `PATH`, Windows/Herd (`cmd //c php`), and common Herd install paths are all tried.

---

## Configuration

Publish the config file to customise paths and behaviour:

```bash
php artisan vendor:publish --tag=api-contract-config
```

```php
// config/api-contract.php

return [
    // Where snapshot JSON files are stored (committed to git)
    'snapshot_dir' => 'tests/snapshots/api',

    // Test file passed to php artisan test on update
    'test_path' => 'tests/Feature/ApiContractTest.php',

    // Extra flags for the test runner
    'test_flags' => '--no-coverage',

    // Env variable that triggers snapshot writing instead of asserting
    'update_env' => 'API_CONTRACT_UPDATE',

    // Directory where the pre-push hook is installed
    'hooks_dir' => '.githooks',
];
```

---

## Snapshot format

Snapshots are type-maps of your API response, stored as JSON:

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

Arrays are represented by the shape of their first element. Committing these files gives you a permanent, reviewable record of your API contract.

---

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12

---

## License

MIT
