<?php

namespace TheJenos\SmartPiiRedactor\Commands;

use Illuminate\Console\Command;
use Mitie\Vendor;

class SmartPiiRedactorInitCommand extends Command
{
    protected $signature = 'smart-pii-redactor:init';

    public function handle()
    {
        Vendor::check();

        $destinationDir = __DIR__.'/../Models';
        $destinationPath = $destinationDir.'/ner_model.dat';

        if (file_exists($destinationPath)) {
            $this->info('✔ Model file already exists. Skipping download and extraction.');

            return self::SUCCESS;
        } else {
            $this->info('✘ Model file not found.');
        }

        $this->info('Checking for MITIE-models-v0.2.tar.bz2...');

        $url = 'https://github.com/mit-nlp/MITIE/releases/download/v0.4/MITIE-models-v0.2.tar.bz2';

        // Use a writable directory for the downloaded file and extraction
        $baseDir = sys_get_temp_dir();
        $tmpFile = $baseDir.DIRECTORY_SEPARATOR.'mitie_models.tar.bz2';
        $tmpExtractedDir = $baseDir.DIRECTORY_SEPARATOR.'mitie_models_extract';

        // Stream the download to disk; the archive is too large to hold in memory
        if (! copy($url, $tmpFile)) {
            $this->info('Failed to download model from '.$url);

            return self::FAILURE;
        }

        // Extract the bz2 (tar.bz2)
        $tarPath = substr($tmpFile, 0, -4); // remove .bz2
        try {
            // Decompress as a stream; PharData::decompress() loads the whole archive into memory
            $source = fopen('compress.bzip2://'.$tmpFile, 'rb');
            $target = fopen($tarPath, 'wb');
            if ($source === false || $target === false || stream_copy_to_stream($source, $target) === false) {
                throw new \RuntimeException('Unable to decompress '.$tmpFile);
            }
            fclose($source);
            fclose($target);

            $tar = new \PharData($tarPath);
            if (! is_dir($tmpExtractedDir)) {
                mkdir($tmpExtractedDir, 0755, true);
            }
            $tar->extractTo($tmpExtractedDir, null, true);
        } catch (\Exception $e) {
            $this->info('Error extracting tar.bz2: '.$e->getMessage());

            return self::FAILURE;
        }

        // Find and move ner_model.dat to Models directory
        $possibleModelLocations = [
            $tmpExtractedDir.'/english/ner_model.dat',
            $tmpExtractedDir.'/MITIE-models/english/ner_model.dat',
        ];

        $found = false;
        foreach ($possibleModelLocations as $modelPath) {
            if (file_exists($modelPath)) {
                if (! is_dir($destinationDir)) {
                    mkdir($destinationDir, 0755, true);
                }
                if (! copy($modelPath, $destinationPath)) {
                    $this->info('✘ Failed to copy model file to Models');

                    return self::FAILURE;
                }
                $found = true;
                $this->info('✔ Model file copied to Models directory!');
                break;
            }
        }

        if (! $found) {
            $this->info('✘ Could not find model file in extracted files.');

            return self::FAILURE;
        }

        // Clean up
        @unlink($tmpFile);
        @unlink($tarPath);

        $this->info('✔ Model download and setup completed.');

        return self::SUCCESS;
    }
}
