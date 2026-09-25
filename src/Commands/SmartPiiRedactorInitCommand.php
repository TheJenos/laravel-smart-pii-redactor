<?php

namespace TheJenos\SmartPiiRedactor\Commands;

use Illuminate\Console\Command;
use TheJenos\SmartPiiRedactor\SmartPiiRedactor;

class SmartPiiRedactorInitCommand extends Command
{
    protected $signature = 'smart-pii-redactor:init';

    public function handle()
    {
        $destinationDir = SmartPiiRedactor::MODELS_PATH;

        // Archive entry basename => file it's saved as in the Models directory...
        $files = [
            SmartPiiRedactor::NER_JAR => $destinationDir.'/'.SmartPiiRedactor::NER_JAR,
            SmartPiiRedactor::NER_CLASSIFIER => $destinationDir.'/'.SmartPiiRedactor::NER_CLASSIFIER,
        ];

        $missing = array_filter($files, fn ($path) => ! file_exists($path));

        if ($missing === []) {
            $this->info('✔ Stanford NER files already exist. Skipping download and extraction.');

            return self::SUCCESS;
        } else {
            $this->info('✘ Stanford NER files not found.');
        }

        if (! class_exists(\ZipArchive::class)) {
            $this->info('✘ The zip PHP extension is required to extract the Stanford NER archive.');

            return self::FAILURE;
        }

        $url = 'https://nlp.stanford.edu/software/stanford-ner-4.2.0.zip';

        $this->info('Downloading '.$url.'...');

        // Use a writable directory for the downloaded file
        $tmpFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stanford_ner.zip';

        // Stream the download to disk; the archive is too large to hold in memory
        if (! copy($url, $tmpFile)) {
            $this->info('Failed to download model from '.$url);

            return self::FAILURE;
        }

        $zip = new \ZipArchive;
        if ($zip->open($tmpFile) !== true) {
            $this->info('✘ Unable to open '.$tmpFile);

            return self::FAILURE;
        }

        if (! is_dir($destinationDir)) {
            mkdir($destinationDir, 0755, true);
        }

        // The archive nests everything under a dated folder, so entries are matched by basename
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            $name = basename($entry);

            if (! isset($missing[$name])) {
                continue;
            }

            $source = $zip->getStream($entry);
            $target = fopen($missing[$name], 'wb');
            if ($source === false || $target === false || stream_copy_to_stream($source, $target) === false) {
                $this->info('✘ Failed to extract '.$name);

                return self::FAILURE;
            }
            fclose($source);
            fclose($target);

            unset($missing[$name]);
            $this->info('✔ '.$name.' copied to Models directory!');
        }

        $zip->close();

        // Clean up
        @unlink($tmpFile);

        if ($missing !== []) {
            $this->info('✘ Could not find '.implode(', ', array_keys($missing)).' in the archive.');

            return self::FAILURE;
        }

        $this->info('✔ Model download and setup completed.');

        return self::SUCCESS;
    }
}
