<?php

namespace TheJenos\SmartPiiRedactor;

use Laravel\Ai\AiManager;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use TheJenos\SmartPiiRedactor\Commands\SmartPiiRedactorInitCommand;
use TheJenos\SmartPiiRedactor\Provider\RedactorProvider;

class SmartPiiRedactorServiceProvider extends PackageServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        $this->app->make(AiManager::class)->extend(SmartPiiRedactor::DRIVER, function ($app, $config) {
            $config = config('ai.providers.redactor', []);

            return new RedactorProvider(
                $config,
                $app->make('events')
            );
        });
    }

    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('smart-pii-redactor')
            ->hasCommand(SmartPiiRedactorInitCommand::class);
    }
}
