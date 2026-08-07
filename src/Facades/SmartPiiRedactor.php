<?php

namespace TheJenos\SmartPiiRedactor\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \TheJenos\SmartPiiRedactor\SmartPiiRedactor
 */
class SmartPiiRedactor extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SmartPiiRedactor::class;
    }
}
