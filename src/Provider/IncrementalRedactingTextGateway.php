<?php

namespace TheJenos\SmartPiiRedactor\Provider;

use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Messages\Message;
use TheJenos\SmartPiiRedactor\SmartPiiRedactor;
use TheJenos\SmartPiiRedactor\SmartPiiRedactorEntites;

/**
 * Remembers the entities found in each message, so only messages that haven't been
 * seen before are scanned. Earlier messages reuse the entities found for them.
 *
 * Entities are merged in message order, so the tags given to earlier messages stay
 * the same as the conversation grows.
 */
class IncrementalRedactingTextGateway extends RedactingTextGateway
{
    public const CACHE_KEY = 'smart_pii_redactor_entities';

    /**
     * @param  Message[]  $messages
     */
    protected function detectEntities(array $messages): array
    {
        $texts = array_map(fn (Message $message) => implode(PHP_EOL, $this->textsOf($message)), $messages);

        $found = [];
        $unseen = [];

        foreach ($texts as $index => $text) {
            if (trim($text) === '') {
                $found[$index] = [];

                continue;
            }

            $cached = Cache::get($this->cacheKey($text));

            if (is_array($cached)) {
                $found[$index] = $cached;
            } else {
                $unseen[$index] = $text;
            }
        }

        if ($unseen !== []) {
            // New messages are scanned together, so the model is only run once per step...
            $entities = app(SmartPiiRedactor::class)->getEntities(
                implode(PHP_EOL, $unseen),
                $this->config['only'] ?? [],
                $this->config['except'] ?? [],
            );

            foreach ($unseen as $index => $text) {
                $found[$index] = array_values(array_filter(
                    $entities,
                    fn (array $entity) => str_contains($text, $entity['text']),
                ));

                Cache::forever($this->cacheKey($text), $found[$index]);
            }
        }

        ksort($found);

        return app(SmartPiiRedactor::class)->uniqueEntities(array_merge(...$found));
    }

    /**
     * Get the cache key for a message's entities, which depends on the entities being detected.
     */
    protected function cacheKey(string $text): string
    {
        $only = SmartPiiRedactorEntites::toValue($this->config['only'] ?? []);
        $except = SmartPiiRedactorEntites::toValue($this->config['except'] ?? []);

        sort($only);
        sort($except);

        return self::CACHE_KEY.'_'.hash('sha256', implode(',', $only).'|'.implode(',', $except).'|'.$text);
    }
}
