<?php

namespace TheJenos\LaravelSmartPiiRedactor;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use TheJenos\LaravelSmartPiiRedactor\Commands\LaravelSmartPiiRedactorCommand;

class LaravelSmartPiiRedactorServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-smart-pii-redactor')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_smart_pii_redactor_table')
            ->hasCommand(LaravelSmartPiiRedactorCommand::class);
    }
}
