<?php

namespace TheJenos\SmartPiiRedactor;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
// use TheJenos\SmartPiiRedactor\Commands\SmartPiiRedactorCommand;

class SmartPiiRedactorServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('smart-pii-redactor')
            ->hasConfigFile()
            ->hasViews();
    }
}
