<?php

namespace TheJenos\SmartPiiRedactor;

use Mitie\NER;

class SmartPiiRedactor
{
    const REGEX_PATTERN = [
        'EMAIL' => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
        'URL' => '/https?:\/\/[^\s"\'<>]+/',
        'IPV4_ADDRESS' => '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
        'IPV6_ADDRESS' => '/\b([0-9a-fA-F]{1,4}:){1,7}[0-9a-fA-F]{1,4}\b|\b([0-9a-fA-F]{1,4}:){1,7}:|::([0-9a-fA-F]{1,4}:){0,6}[0-9a-fA-F]{1,4}\b|\b([0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}\b/',
        'SSN' => '/\b\d{3}-\d{2}-\d{4}\b/',
        'CREDIT_CARD' => '/\b\d(?:[ -]?\d){12,18}\b/',
        'PHONE' => '/(?:\+\d{1,3}\s?\d{1,4}[\s-]?)?(?:\(?\d{1,4}\)?[\s-]?)?\d{1,4}(?:[\s-]\d{2,4}){1,4}\b/',
        'IBAN' => '/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/',
        'API_KEY' => '/\b(?:sk-(?:live|test)-[A-Za-z0-9]{24,}|pk-(?:live|test)-[A-Za-z0-9]{24,}|plaid-(?:sandbox|development|production)-[a-f0-9]{32,}|api[_-]?key[_-]?[A-Za-z0-9\-]{16,}|AKIA[0-9A-Z]{16}|[Ss]ecret[_-]?[Kk]ey[_-]?[A-Za-z0-9\-_=]{16,})\b/',
        'BEARER_TOKEN' => '/\bBearer\s+[A-Za-z0-9\-_.]{16,}\b/',
    ];

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

    public function getEntitiesFromRegex(string $text): array
    {
        $entities = [];
        foreach (self::REGEX_PATTERN as $tag => $pattern) {
            preg_match_all($pattern, $text, $matches);
            foreach ($matches[0] as $match) {
                $entities[] = [
                    'text' => $match,
                    'tag' => $tag,
                ];
            }
        }

        return $entities;
    }

    public function getEntities(string $text): array
    {
        $doc = $this->ner->doc($text);
        $entities = $doc->entities();

        $entities = array_merge($entities, $this->getEntitiesFromRegex($text));

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

    public function redact($text, $entities): string
    {
        foreach ($entities as $entity) {
            $text = str_replace($entity['text'], '***', $text);
        }

        return $text;
    }

    public function mask($text, $entities): string
    {
        $counts = [];

        foreach ($entities as $entity) {
            $id = isset($counts[$entity['tag']]) ? $counts[$entity['tag']]++ : 0;

            $counts[$entity['tag']] = $id;

            $tag = '['.$entity['tag'].'_'.$id.']';

            $text = str_replace($entity['text'], $tag, $text);
        }

        return $text;
    }

    public function maskWithMap($text, $entities): array
    {
        $counts = [];

        $map = [];

        foreach ($entities as $entity) {
            if (! isset($counts[$entity['tag']])) {
                $counts[$entity['tag']] = 0;
            }

            $id = $counts[$entity['tag']]++;

            $tag = '['.$entity['tag'].'_'.$id.']';

            $map[$tag] = $entity['text'];

            $text = str_replace($entity['text'], $tag, $text);
        }

        return [$text, $map];
    }
}
