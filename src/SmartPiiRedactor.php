<?php

namespace TheJenos\SmartPiiRedactor;

use Mitie\NER;

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
}
