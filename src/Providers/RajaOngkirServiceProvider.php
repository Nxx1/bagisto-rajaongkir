<?php

namespace Akara\RajaOngkir\Providers;

use Illuminate\Support\ServiceProvider;
use Akara\RajaOngkir\Clients\RajaOngkirClient;

class RajaOngkirServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../Config/carriers.php', 'carriers');
        $this->mergeConfigFrom(__DIR__ . '/../Config/system.php', 'core');

        // Bind client and repository to container
        $this->app->bind(RajaOngkirClient::class, function ($app) {
            $apiKey = core()->getConfigData('sales.carriers.rajaongkir.api_key') ?? env('RAJAONGKIR_API_KEY', '');
            $account = core()->getConfigData('sales.carriers.rajaongkir.account_type') ?? env('RAJAONGKIR_ACCOUNT_TYPE', 'pro');
            return new RajaOngkirClient($apiKey, $account);
        });
    }

    public function boot(): void
    {
    }
}
