<?php

return [
    /*
     * Directory where snapshot files are stored (relative to base_path()).
     * These files should be committed to version control.
     */
    'snapshot_dir' => 'tests/snapshots/api',

    /*
     * The test file (or directory) that contains your contract tests.
     * Used by the update command and the pre-push hook.
     */
    'test_path' => 'tests/Feature/ApiContractTest.php',

    /*
     * Extra PHPUnit/Pest flags passed when running contract tests.
     */
    'test_flags' => '--no-coverage',

    /*
     * Environment variable name that triggers snapshot writing instead of asserting.
     */
    'update_env' => 'API_CONTRACT_UPDATE',

    /*
     * URI prefix used by api:contract:generate and api:contract:coverage.
     * The pre-commit hook uses this to scan for uncovered routes.
     */
    'route_prefix' => 'api',

    /*
     * Path (relative to base_path()) where git hooks are installed.
     */
    'hooks_dir' => '.githooks',

    /*
     * Optional closure returning a bearer token (or null) for authenticating
     * generated contract tests. Called at test runtime, not at generation time.
     *
     * Example:
     *   'auth' => fn () => \App\Models\User::factory()->create()->createToken('test')->plainTextToken,
     */
    'auth' => null,

    /*
     * When false (default), only REMOVED and TYPE_CHANGED violations fail a test —
     * new (additive) fields are reported but never block. When true, NEW fields
     * fail the test too, requiring every response change to update the snapshot.
     */
    'strict' => false,
];
