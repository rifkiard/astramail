<?php

namespace Rifkiard\AstraMail;

use Illuminate\Support\ServiceProvider;

class AstraMailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge package config so consumers don't need to publish it.
        $this->mergeConfigFrom(
            __DIR__ . '/../config/astramail.php',
            'astramail'
        );
    }

    public function boot(): void
    {
        // Allow consumers to publish the config for customisation.
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/astramail.php' => config_path('astramail.php'),
            ], 'astramail-config');
        }

        // Use callAfterResolving so the transport is registered regardless of
        // whether MailManager was already resolved before boot() ran (Laravel 12).
        $this->callAfterResolving('mail.manager', function ($manager) {
            $manager->extend('astramail', function (array $config = []) {
                return new AstraMailTransport();
            });
        });
    }
}
