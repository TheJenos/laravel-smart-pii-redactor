<?php

namespace TheJenos\LaravelSmartPiiRedactor\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \TheJenos\LaravelSmartPiiRedactor\LaravelSmartPiiRedactor
 */
class LaravelSmartPiiRedactor extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \TheJenos\LaravelSmartPiiRedactor\LaravelSmartPiiRedactor::class;
    }
}
