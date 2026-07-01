<?php

namespace Sead\ApiContract;

use Illuminate\Support\ServiceProvider;
use Sead\ApiContract\Commands\ApiContractInstallCommand;
use Sead\ApiContract\Commands\ApiContractUpdateCommand;

class ApiContractServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/api-contract.php', 'api-contract');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/api-contract.php' => config_path('api-contract.php'),
            ], 'api-contract-config');

            $this->commands([
                ApiContractInstallCommand::class,
                ApiContractUpdateCommand::class,
            ]);
        }
    }
}
