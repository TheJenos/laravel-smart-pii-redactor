<?php

namespace TheJenos\SmartPiiRedactor;

use Mitie\NER;
use Mitie\Vendor;

class SmartPiiRedactor
{
    protected $ner;

    public function __construct()
    {
        $basePath = __DIR__.'/Models/ner_model.dat';
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
            return ! isset($entity['tag']) || $entity['tag'] !== 'MISC';
        });

        $uniqueEntities = [];
        foreach ($entities as $entity) {
            if (! isset($uniqueEntities[$entity['text']])) {
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
            $text = str_replace($entity['text'], '['.$entity['tag'].']', $text);
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

            $tag = '['.$entity['tag'].'_'.$id.']';

            $map[$tag] = $entity['text'];

            $text = str_replace($entity['text'], $tag, $text);
        }

        return [$text, $map];
    }

    public static function check(): bool
    {
        $dest = Vendor::defaultLib();
        if (file_exists($dest)) {
            echo "✔ MITIE found\n";
        } else if (getenv('SKIP_MODEL_DOWNLOAD') != 1) {
            Vendor::check();
        }

        $destinationDir = __DIR__.'/Models';
        $destinationPath = $destinationDir.'/ner_model.dat';

        // Skip download and extraction if ner_model.dat already exists
        if (file_exists($destinationPath)) {
            echo "✔ Model file already exists. Skipping download and extraction.\n";
            return true;
        } else {
            echo "✘ Model file not found.\n";
        }

        if(getenv('SKIP_MODEL_DOWNLOAD') == 1){
            echo "✔ Skipping model download because SKIP_MODEL_DOWNLOAD is set.\n";
            return false;
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
                    echo "✘ Failed to copy model file to Models\n";
                    return false;
                }
                $found = true;
                echo "✔ Model file copied to Models directory!\n";
                break;
            }
        }

        if (! $found) {
            echo "✘ Could not find model file in extracted files.\n";
            return false;
        }

        // Clean up
        @unlink($tmpFile);
        @unlink(isset($tarPath) ? $tarPath : null);

        echo "✔ Model download and setup completed.\n";
        return true;
    }
}
