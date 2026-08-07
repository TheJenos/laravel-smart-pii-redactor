<?php

namespace TheJenos\LaravelSmartPiiRedactor\Commands;

use Illuminate\Console\Command;

class LaravelSmartPiiRedactorCommand extends Command
{
    public $signature = 'laravel-smart-pii-redactor';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
