<?php

namespace TheJenos\SmartPiiRedactor;

use Mitie\NER;
use Mitie\Vendor;
use Str;

class SmartPiiRedactor {

    protected $ner;

    public function __construct()
    {
        $basePath = __DIR__ . '/Models/ner_model.dat';
        $this->ner = new NER($basePath);
    }

    public function getTags(): array
    {
        return $this->ner->tags();
    }

    public function getEntities(string $text): array
    {
        $doc = $this->ner->doc($text);
        $entities = $doc->entities();

        $entities = array_filter($entities, function ($entity) {
            return !isset($entity['tag']) || $entity['tag'] !== 'MISC';
        });

        $uniqueEntities = [];
        foreach ($entities as $entity) {
            if (!isset($uniqueEntities[$entity['text']])) {
                $uniqueEntities[$entity['text']] = $entity;
            }
        }

        $entities = array_values($uniqueEntities);

        usort($entities, function ($a, $b) {
            return strlen($b['text']) <=> strlen($a['text']);
        });

        return $entities;
    }

    public function redact(string $text): string
    {
        $entities = $this->getEntities($text);
        foreach ($entities as $entity) {
            $text = str_replace($entity['text'], '***', $text);
        }
        return $text;
    }

    public function mask(string $text): string
    {
        $entities = $this->getEntities($text);
        foreach ($entities as $entity) {
            $text = str_replace($entity['text'], "[" . $entity['tag'] . "]", $text);
        }
        return $text;
    }
    
    public function maskWithMap(string $text): array
    {
        $entities = $this->getEntities($text);
        
        $counts = [
            'PERSON' => 0,
            'ORGANIZATION' => 0,
            'LOCATION' => 0,
        ];

        $map = [];

        foreach ($entities as $entity) {
            $id = $counts[$entity['tag']]++;

            $tag = "[" . $entity['tag'] . "_" . $id . "]";
            
            $map[$tag] = $entity['text'];

            $text = str_replace($entity['text'], $tag, $text);
        }

        return [$text, $map];
    }

    public static function check(): bool
    {
        Vendor::check();

        $destinationDir = __DIR__ . '/Models';
        $destinationPath = $destinationDir . '/ner_model.dat';
        
        echo "Destination directory: " . $destinationDir . "\n";

        // Skip download and extraction if ner_model.dat already exists
        if (file_exists($destinationPath)) {
            echo "ner_model.dat already exists. Skipping download and extraction.\n";
            return true;
        }

        echo "Checking for MITIE-models-v0.2.tar.bz2...\n";

        $url = 'https://github.com/mit-nlp/MITIE/releases/download/v0.4/MITIE-models-v0.2.tar.bz2';

        // Use a writable directory for the downloaded file and extraction
        $baseDir = sys_get_temp_dir();
        $tmpFile = $baseDir . DIRECTORY_SEPARATOR . 'mitie_models.tar.bz2';
        $tmpExtractedDir = $baseDir . DIRECTORY_SEPARATOR . 'mitie_models_extract';

        // Download the file
        $fileData = file_get_contents($url);
        if ($fileData === false) {
            echo "Failed to download model from " . $url . "\n";
            return false;
        }
        file_put_contents($tmpFile, $fileData);

        // Extract the bz2 (tar.bz2)
        $phar = new \PharData($tmpFile);
        try {
            $phar->decompress(); // creates tar
            $tarPath = substr($tmpFile, 0, -4); // remove .bz2
            $tar = new \PharData($tarPath);
            if (!is_dir($tmpExtractedDir)) {
                mkdir($tmpExtractedDir, 0755, true);
            }
            $tar->extractTo($tmpExtractedDir, null, true);
        } catch (\Exception $e) {
            echo "Error extracting tar.bz2: " . $e->getMessage() . "\n";
            return false;
        }

        // Find and move ner_model.dat to Models directory
        $possibleModelLocations = [
            $tmpExtractedDir . '/english/ner_model.dat',
            $tmpExtractedDir . '/MITIE-models/english/ner_model.dat',
        ];
        $found = false;
        foreach ($possibleModelLocations as $modelPath) {
            if (file_exists($modelPath)) {
                if (!is_dir($destinationDir)) {
                    mkdir($destinationDir, 0755, true);
                }
                if (!copy($modelPath, $destinationPath)) {
                    echo "Failed to copy ner_model.dat to Models\n";
                    return false;
                }
                $found = true;
                echo "ner_model.dat copied to Models directory!\n";
                break;
            }
        }

        if (!$found) {
            echo "Could not find ner_model.dat in extracted files.\n";
            return false;
        }

        // Clean up
        @unlink($tmpFile);
        @unlink(isset($tarPath) ? $tarPath : null);

        echo "Model download and setup completed.\n";

        return true;
    }
}
