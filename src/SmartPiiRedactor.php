<?php

namespace TheJenos\SmartPiiRedactor;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use StanfordNLP\NERTagger;

class SmartPiiRedactor
{
    public const CACHE_KEY = 'smart_pii_redactor_cache';

    public const DRIVER = 'redactor_wrapper_driver';

    public const REGEX_PATTERN = [
        SmartPiiRedactorEntites::EMAIL->value => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
        SmartPiiRedactorEntites::URL->value => '/https?:\/\/[^\s"\'<>]+/',
        SmartPiiRedactorEntites::IPV4_ADDRESS->value => '/\b(?:\d{1,3}\.){3}\d{1,3}\b/',
        SmartPiiRedactorEntites::IPV6_ADDRESS->value => '/(?<![\w:])(?:(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}|(?:[0-9a-f]{1,4}:){1,7}(?::[0-9a-f]{1,4}){1,7}|::[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6}|(?:[0-9a-f]{1,4}:){1,7}:)(?![\w:])/i',
        SmartPiiRedactorEntites::SSN->value => '/\b\d{3}-\d{2}-\d{4}\b/',
        SmartPiiRedactorEntites::CREDIT_CARD->value => '/\b\d(?:[ -]?\d){12,18}\b/',
        SmartPiiRedactorEntites::PHONE->value => '/(?<![\w+\-:.])(?!\d{4}-\d{2}-\d{2})(?:\+\d{1,3}[\s-]?)?(?:\(\d{1,4}\)[\s-]?)?\d{1,4}(?:[\s-]\d{2,4}){1,4}(?![\w\-:])/',
        SmartPiiRedactorEntites::IBAN->value => '/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/',
        SmartPiiRedactorEntites::API_KEY->value => '/\b(?:sk-(?:live|test)-[A-Za-z0-9]{24,}|pk-(?:live|test)-[A-Za-z0-9]{24,}|plaid-(?:sandbox|development|production)-[a-f0-9]{32,}|api[_-]?key[_-]?[A-Za-z0-9\-]{16,}|AKIA[0-9A-Z]{16}|[Ss]ecret[_-]?[Kk]ey[_-]?[A-Za-z0-9\-_=]{16,})\b/',
        SmartPiiRedactorEntites::BEARER_TOKEN->value => '/\bBearer\s+[A-Za-z0-9\-_.]{16,}\b/',
    ];

    public const MODELS_PATH = __DIR__.'/../resources/models';

    public const NER_JAR = 'stanford-ner.jar';

    public const NER_CLASSIFIER = 'english.all.3class.distsim.crf.ser.gz';

    /**
     * PTB escapes the Stanford tokenizer writes in place of brackets and quotes.
     */
    protected const PTB_ESCAPES = [
        '-LRB-' => '(', '-RRB-' => ')',
        '-LSB-' => '[', '-RSB-' => ']',
        '-LCB-' => '{', '-RCB-' => '}',
        '``' => '"', "''" => '"',
    ];

    protected ?NERTagger $ner = null;

    protected function ner(): NERTagger
    {
        return $this->ner ??= new NERTagger(
            self::MODELS_PATH.'/'.self::NER_CLASSIFIER,
            self::MODELS_PATH.'/'.self::NER_JAR,
            ['-mx1g'],
        );
    }

    public function getTags(): array
    {
        return SmartPiiRedactorEntites::modelEntities();
    }

    public function getEntitiesFromModel(string $text): array
    {
        $tokens = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === []) {
            return [];
        }

        $result = $this->ner()->tag($tokens);

        $errors = $this->ner()->getErrors();
        if ($result === [] && $errors) {
            throw new \RuntimeException('Stanford NER failed: '.$errors);
        }

        // Group runs of tokens sharing a tag; the tagger doesn't mark entity boundaries...
        $spans = [];
        $current = null;
        foreach (array_merge(...$result) as [$word, $tag]) {
            if ($tag === 'O') {
                $current = null;

                continue;
            }

            if ($current !== null && $spans[$current]['tag'] === $tag) {
                $spans[$current]['words'][] = $word;
            } else {
                $spans[] = ['tag' => $tag, 'words' => [$word]];
                $current = array_key_last($spans);
            }
        }

        $entities = [];
        foreach ($spans as $span) {
            $entityText = $this->findInText($text, $span['words']);
            if ($entityText !== null) {
                $entities[] = ['text' => $entityText, 'tag' => $span['tag']];
            }
        }

        return $entities;
    }

    /**
     * Map tokenizer output back to the exact substring of the original text, since the
     * tokenizer splits punctuation off and escapes brackets and quotes.
     */
    protected function findInText(string $text, array $words): ?string
    {
        $parts = array_map(
            fn ($word) => preg_quote(strtr($word, self::PTB_ESCAPES), '/'),
            $words
        );

        if (preg_match('/'.implode('\s*', $parts).'/u', $text, $match)) {
            return $match[0];
        }

        return null;
    }

    public function getEntitiesFromRegex(string $text): array
    {
        $entities = [];
        foreach (self::REGEX_PATTERN as $tag => $pattern) {
            preg_match_all($pattern, $text, $matches);
            foreach ($matches[0] as $match) {
                if (! $this->isPlausibleMatch($tag, $match)) {
                    continue;
                }

                $entities[] = [
                    'text' => $match,
                    'tag' => $tag,
                ];
            }
        }

        return $entities;
    }

    /**
     * Reject regex matches that have the right shape but can't be the entity.
     */
    protected function isPlausibleMatch(string $tag, string $match): bool
    {
        return match ($tag) {
            // E.164 numbers have at most 15 digits, and anything under 7 is a reference or a date part...
            SmartPiiRedactorEntites::PHONE->value => ($digits = strlen(preg_replace('/\D/', '', $match))) >= 7 && $digits <= 15,
            default => true,
        };
    }

    public function getEntities(string $text, array $onlyEntities = [], array $exceptEntities = []): array
    {
        $allTags = SmartPiiRedactorEntites::all();

        $onlyEntities = SmartPiiRedactorEntites::toValue($onlyEntities);
        $exceptEntities = SmartPiiRedactorEntites::toValue($exceptEntities);

        $invalidOnly = array_diff($onlyEntities, $allTags);
        $invalidExcept = array_diff($exceptEntities, $allTags);

        if (count($invalidOnly) > 0) {
            throw new \Exception('Invalid entities in onlyEntities: '.implode(', ', $invalidOnly));
        }
        if (count($invalidExcept) > 0) {
            throw new \Exception('Invalid entities in exceptEntities: '.implode(', ', $invalidExcept));
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
            $foundEntities = array_merge($foundEntities, $this->getEntitiesFromModel($text));
        }

        if (array_diff(SmartPiiRedactorEntites::regexEntities(), $selectedTags) == []) {
            $foundEntities = array_merge($foundEntities, $this->getEntitiesFromRegex($text));
        }

        $foundEntities = array_filter($foundEntities, function ($entity) {
            return ! isset($entity['tag']) || $entity['tag'] !== 'MISC';
        });

        $uniqueEntities = [];
        foreach ($foundEntities as $entity) {
            if (! isset($uniqueEntities[$entity['text']])) {
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

    public function redact(string $text, array $entities): string
    {
        foreach ($entities as $entity) {
            $text = str_replace($entity['text'], '***', $text);
        }

        return $text;
    }

    public function mask(string $text, array $entities, ?string $replacementKey = null): string
    {
        $counts = [];

        $map = [];

        $replacementKey = $replacementKey ?? Str::random(10);

        foreach ($entities as $entity) {
            if (! isset($counts[$entity['tag']])) {
                $counts[$entity['tag']] = 0;
            }

            $id = $counts[$entity['tag']]++;

            $tag = '['.$entity['tag'].'_'.$id.']';

            $map[$tag] = $entity['text'];

            $text = str_replace($entity['text'], $tag, $text);
        }

        Cache::put(self::getCacheKey($replacementKey), $map);

        return $text;
    }

    public static function getCacheReplacement(string $replacementKey): array
    {
        return Cache::get(self::getCacheKey($replacementKey), []);
    }

    public static function reapplyMaskedText(string $text, string $replacementKey): string
    {
        $replacement = self::getCacheReplacement($replacementKey);
        foreach ($replacement as $key => $value) {
            $text = preg_replace_callback(self::tagPattern($key), fn () => $value, $text);
        }

        return $text;
    }

    private static function tagPattern(string $tag): string
    {
        $parts = array_map(fn ($part) => preg_quote($part, '/'), explode('_', trim($tag, '[]')));

        return '/\\\\?\[\s*'.implode('(?:\\\\?_|\s)', $parts).'\s*\\\\?\]/i';
    }

    private static function getCacheKey(string $replacementKey): string
    {
        return self::CACHE_KEY.'_'.$replacementKey;
    }
}
