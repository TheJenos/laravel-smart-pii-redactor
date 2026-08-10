<?php

namespace TheJenos\SmartPiiRedactor;

use Cache;
use Mitie\NER;
use Session;
use Validator;

class SmartPiiRedactor
{
    const CACHE_KEY = 'smart_pii_redactor_cache';

    const REGEX_PATTERN = [
        SmartPiiRedactorEntites::EMAIL->value => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
        SmartPiiRedactorEntites::URL->value => '/https?:\/\/[^\s"\'<>]+/',
        SmartPiiRedactorEntites::IPV4_ADDRESS->value => '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
        SmartPiiRedactorEntites::IPV6_ADDRESS->value => '/\b([0-9a-fA-F]{1,4}:){1,7}[0-9a-fA-F]{1,4}\b|\b([0-9a-fA-F]{1,4}:){1,7}:|::([0-9a-fA-F]{1,4}:){0,6}[0-9a-fA-F]{1,4}\b|\b([0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}\b/',
        SmartPiiRedactorEntites::SSN->value => '/\b\d{3}-\d{2}-\d{4}\b/',
        SmartPiiRedactorEntites::CREDIT_CARD->value => '/\b\d(?:[ -]?\d){12,18}\b/',
        SmartPiiRedactorEntites::PHONE->value => '/(?:\+\d{1,3}\s?\d{1,4}[\s-]?)?(?:\(?\d{1,4}\)?[\s-]?)?\d{1,4}(?:[\s-]\d{2,4}){1,4}\b/',
        SmartPiiRedactorEntites::IBAN->value => '/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/',
        SmartPiiRedactorEntites::API_KEY->value => '/\b(?:sk-(?:live|test)-[A-Za-z0-9]{24,}|pk-(?:live|test)-[A-Za-z0-9]{24,}|plaid-(?:sandbox|development|production)-[a-f0-9]{32,}|api[_-]?key[_-]?[A-Za-z0-9\-]{16,}|AKIA[0-9A-Z]{16}|[Ss]ecret[_-]?[Kk]ey[_-]?[A-Za-z0-9\-_=]{16,})\b/',
        SmartPiiRedactorEntites::BEARER_TOKEN->value => '/\bBearer\s+[A-Za-z0-9\-_.]{16,}\b/',
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

    public function getEntities(string $text, array $onlyEntities = [], array $exceptEntities = []): array
    {
        $allTags = SmartPiiRedactorEntites::all();
    
        $onlyEntities = SmartPiiRedactorEntites::toValue($onlyEntities);
        $exceptEntities = SmartPiiRedactorEntites::toValue($exceptEntities);

        $invalidOnly = array_diff($onlyEntities, $allTags);
        $invalidExcept = array_diff($exceptEntities, $allTags);

        if (count($invalidOnly) > 0) {
            throw new \Exception('Invalid entities in onlyEntities: ' . implode(', ', $invalidOnly));
        }
        if (count($invalidExcept) > 0) {
            throw new \Exception('Invalid entities in exceptEntities: ' . implode(', ', $invalidExcept));
        }

        $selectedTags = $allTags;

        if (count($onlyEntities) > 0) {
            $selectedTags = $onlyEntities;
        }

        if (count($exceptEntities) > 0) {
            $selectedTags = array_diff($allTags, $exceptEntities);
        }

        $foundEntities = [];

        if (array_diff(SmartPiiRedactorEntites::modelEntities(), $selectedTags) == []) {
            $doc = $this->ner->doc($text);
            $foundEntities = array_merge($foundEntities, $doc->entities());
        }

        if (array_diff(SmartPiiRedactorEntites::regexEntities(), $selectedTags) == []) {
            $foundEntities = array_merge($foundEntities, $this->getEntitiesFromRegex($text));
        }

        $foundEntities = array_filter($foundEntities, function ($entity) {
            return ! isset($entity['tag']) || $entity['tag'] !== 'MISC';
        });

        $uniqueEntities = [];
        foreach ($foundEntities as $entity) {
            if (!isset($uniqueEntities[$entity['text']])) {
                $uniqueEntities[$entity['text']] = $entity;
            }
        }
        $entities = array_values($uniqueEntities);

        $finalEntities = [];
        foreach ($entities as $i => $entity) {
            $isPartOfOther = false;
            foreach ($entities as $j => $otherEntity) {
                if ($i !== $j && strpos($otherEntity['text'], $entity['text']) !== false) {
                    $isPartOfOther = true;
                    break;
                }
            }
            if (! $isPartOfOther) {
                $finalEntities[] = $entity;
            }
        }

        $foundEntities = $finalEntities;

        return $foundEntities;
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

        Cache::put(self::getCacheKey(), $map);

        return $text;
    }

    public static function getCacheReplacement(): array
    {
        return Cache::get(self::getCacheKey(), []);
    }

    public static function reapplyMaskedText($text): string
    {
        $replacement = self::getCacheReplacement();
        foreach ($replacement as $key => $value) {
            $text = str_replace($key, $value, $text);
        }
        return $text;
    }

    private static function getCacheKey(): string
    {
        return self::CACHE_KEY . '_' . Session::getId();
    }
}
