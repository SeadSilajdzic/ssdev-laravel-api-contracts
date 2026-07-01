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
     * Set to '1' to regenerate snapshots: API_CONTRACT_UPDATE=1 php artisan test ...
     */
    'update_env' => 'API_CONTRACT_UPDATE',

    /*
     * Path (relative to base_path()) where the pre-push git hook is installed.
     */
    'hooks_dir' => '.githooks',
];
