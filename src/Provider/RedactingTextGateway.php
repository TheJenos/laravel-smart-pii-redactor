<?php

namespace TheJenos\SmartPiiRedactor\Provider;

use Generator;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use TheJenos\SmartPiiRedactor\SmartPiiRedactor;

/**
 * Redacts the messages right before they are sent to the base provider's API.
 *
 * Because redaction happens below the agent pipeline, conversation storage
 * still receives the original, unredacted prompt.
 */
class RedactingTextGateway implements StepTextGateway
{
    /**
     * Headers whose values are never written to the log.
     */
    protected const SECRET_HEADERS = ['authorization', 'x-api-key', 'api-key', 'x-goog-api-key'];

    /**
     * Whether the HTTP client listeners have been registered.
     */
    protected static bool $listening = false;

    /**
     * The gateway whose provider call is currently in flight, if logging is enabled for it.
     */
    protected static ?self $active = null;

    public function __construct(protected StepTextGateway $gateway, protected array $config)
    {
        if (($this->config['log'] ?? false) && ! static::$listening) {
            static::$listening = true;

            Event::listen(RequestSending::class, fn (RequestSending $event) => static::$active?->logRequest($event));
            Event::listen(ResponseReceived::class, fn (ResponseReceived $event) => static::$active?->logResponse($event));
        }
    }

    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $replacementKey = Str::random(10);

        $messages = $this->redactMessages($messages, $replacementKey);

        $response = $this->capturingHttp(fn () => $this->gateway->generateTextStep(
            $provider,
            $model,
            $instructions,
            $messages,
            $tools,
            $schema,
            $options,
            $timeout,
            $stepContext,
        ));

        return $this->restoreResponse($response, $replacementKey);
    }

    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $replacementKey = Str::random(10);

        $stream = $this->gateway->generateStreamStep(
            $invocationId,
            $provider,
            $model,
            $instructions,
            $this->redactMessages($messages, $replacementKey),
            $tools,
            $schema,
            $options,
            $timeout,
            $stepContext,
        );

        // Tags can be split across deltas, so text that may be the start of a tag is held back per text/reasoning block...
        $held = [];

        $flush = function (?string $except = null) use (&$held, $replacementKey): Generator {
            foreach ($held as $key => [$event, $buffer]) {
                if ($key === $except) {
                    continue;
                }

                unset($held[$key]);

                yield $this->withDelta($event, $buffer, $replacementKey);
            }
        };

        // The request is sent when the stream is started, so only that part is captured...
        $this->capturingHttp(fn () => $stream->current());

        foreach ($stream as $event) {
            if (! $event instanceof TextDelta && ! $event instanceof ReasoningDelta) {
                yield from $flush();

                if ($event instanceof ToolCallEvent) {
                    $this->restoreArguments($event->toolCall->arguments, $replacementKey);
                }

                // Provider-side tools (web search, code interpreter, ...) report their input in the event data...
                if ($event instanceof ProviderToolEvent) {
                    $this->restoreArguments($event->data, $replacementKey);
                }

                yield $event;

                continue;
            }

            $key = $event instanceof TextDelta ? 'text:'.$event->messageId : 'reasoning:'.$event->reasoningId;

            // A new block started, so whatever the previous one held back is complete...
            yield from $flush($key);

            $buffer = ($held[$key][1] ?? '').$event->delta;

            $pending = $this->pendingTagPrefix($buffer);
            $ready = substr($buffer, 0, strlen($buffer) - strlen($pending));

            if ($ready !== '') {
                yield $this->withDelta($event, $ready, $replacementKey);
            }

            if ($pending === '') {
                unset($held[$key]);
            } else {
                $held[$key] = [$event, $pending];
            }
        }

        yield from $flush();

        $response = $stream->getReturn();

        if ($response !== null) {
            // A streamed body can't be read from the HTTP event without consuming it, so the result is logged here...
            $this->log('debug', 'Provider stream finished.', $this->responseContext($response));
        }

        return $response === null ? null : $this->restoreResponse($response, $replacementKey);
    }

    /**
     * Redact every message using one shared set of entities so tags stay consistent across the history.
     *
     * @param  Message[]  $messages
     * @return Message[]
     */
    protected function redactMessages(array $messages, string $replacementKey): array
    {
        $redactor = app(SmartPiiRedactor::class);

        $entities = $redactor->getEntities(
            implode(PHP_EOL, array_merge(...array_map(fn (Message $message) => $this->textsOf($message), $messages))),
            $this->config['only'] ?? [],
            $this->config['except'] ?? [],
        );

        if (count($entities) === 0) {
            return $messages;
        }

        $method = $this->config['method'] ?? 'mask';

        $redact = fn (mixed $value) => $this->mapStrings(
            $value,
            fn (string $text) => $redactor->{$method}($text, $entities, $replacementKey),
        );

        $redacted = array_map(function (Message $message) use ($redact) {
            $message = clone $message;

            if (! blank($message->content)) {
                $message->content = $redact($message->content);
            }

            // Tool calls and results are replayed with their original values, so they're masked too...
            if ($message instanceof AssistantMessage) {
                $message->toolCalls = $message->toolCalls->map(function (ToolCall $toolCall) use ($redact) {
                    $toolCall = clone $toolCall;
                    $toolCall->arguments = $redact($toolCall->arguments);

                    return $toolCall;
                });
            }

            if ($message instanceof ToolResultMessage) {
                $message->toolResults = $message->toolResults->map(function (ToolResult $toolResult) use ($redact) {
                    $toolResult = clone $toolResult;
                    $toolResult->arguments = $redact($toolResult->arguments);
                    $toolResult->result = $redact($toolResult->result);

                    return $toolResult;
                });
            }

            return $message;
        }, $messages);

        $this->ensureNothingLeaked($redacted, $entities);

        return $redacted;
    }

    /**
     * Get every piece of text in the message that is sent to the provider.
     *
     * @return string[]
     */
    protected function textsOf(Message $message): array
    {
        $texts = [(string) $message->content];

        $collect = function (mixed $value) use (&$texts): void {
            $this->mapStrings($value, function (string $text) use (&$texts) {
                $texts[] = $text;

                return $text;
            });
        };

        if ($message instanceof AssistantMessage) {
            $message->toolCalls->each(fn (ToolCall $toolCall) => $collect($toolCall->arguments));
        }

        if ($message instanceof ToolResultMessage) {
            $message->toolResults->each(function (ToolResult $toolResult) use ($collect): void {
                $collect($toolResult->arguments);
                $collect($toolResult->result);
            });
        }

        return $texts;
    }

    /**
     * Apply the callback to every string in the value, recursing into arrays.
     */
    protected function mapStrings(mixed $value, callable $callback): mixed
    {
        if (is_string($value)) {
            return $callback($value);
        }

        if (is_array($value)) {
            return array_map(fn ($item) => $this->mapStrings($item, $callback), $value);
        }

        return $value;
    }

    /**
     * Warn when a detected entity is still present in the outgoing messages.
     *
     * @param  Message[]  $messages
     */
    protected function ensureNothingLeaked(array $messages, array $entities): void
    {
        foreach ($messages as $index => $message) {
            $text = implode(PHP_EOL, $this->textsOf($message));

            foreach ($entities as $entity) {
                if (str_contains($text, $entity['text'])) {
                    // Only the tag is logged, never the value itself...
                    $this->log('warning', 'Detected PII is still present in an outgoing message.', [
                        'message_index' => $index,
                        'role' => $message->role->value,
                        'tag' => $entity['tag'],
                    ]);
                }
            }
        }
    }

    /**
     * Build log context for the provider's response before the original values are put back.
     */
    protected function responseContext(StepResponse $response): array
    {
        return [
            'text' => $response->text,
            'tool_calls' => array_map(fn ($toolCall) => [
                'name' => $toolCall->name,
                'arguments' => $toolCall->arguments,
            ], $response->toolCalls),
        ];
    }

    /**
     * Run the callback with this gateway marked as the one whose HTTP traffic is logged.
     */
    protected function capturingHttp(callable $callback): mixed
    {
        $previous = static::$active;

        static::$active = ($this->config['log'] ?? false) ? $this : null;

        try {
            return $callback();
        } finally {
            static::$active = $previous;
        }
    }

    protected function logRequest(RequestSending $event): void
    {
        $request = $event->request;

        $this->log('debug', 'Provider request.', [
            'method' => $request->method(),
            'url' => $request->url(),
            'headers' => $this->safeHeaders($request->headers()),
            'body' => json_decode($request->body(), true) ?? $request->body(),
        ]);
    }

    protected function logResponse(ResponseReceived $event): void
    {
        $response = $event->response;

        $streamed = str_contains($response->header('Content-Type'), 'text/event-stream');

        $this->log('debug', 'Provider response.', [
            'url' => $event->request->url(),
            'status' => $response->status(),
            'headers' => $this->safeHeaders($response->headers()),
            'body' => $streamed ? '(streamed)' : ($response->json() ?? $response->body()),
        ]);
    }

    /**
     * Mask credential headers so API keys never reach the log.
     */
    protected function safeHeaders(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), self::SECRET_HEADERS, true)) {
                $headers[$name] = '***';
            }
        }

        return $headers;
    }

    /**
     * Write to the configured log channel when logging is enabled.
     *
     * Only redacted data is passed here, so the logs never hold the original values.
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if (! ($this->config['log'] ?? false)) {
            return;
        }

        Log::channel($this->config['log_channel'] ?? null)->{$level}('[smart-pii-redactor] '.$message, $context);
    }

    /**
     * Get the trailing part of the text that could still grow into a tag, including escaped
     * forms like \\[PERSON\\_0 that models sometimes write back.
     */
    protected function pendingTagPrefix(string $text): string
    {
        $start = strrpos($text, '[');

        if ($start !== false) {
            if ($start > 0 && $text[$start - 1] === '\\') {
                $start--;
            }

            $tail = substr($text, $start);

            if (strlen($tail) <= 64 && preg_match('/^\\\\?\[[A-Za-z0-9_\\\\\s]*$/', $tail)) {
                return $tail;
            }
        }

        // A trailing backslash may be the start of an escaped tag...
        return str_ends_with($text, '\\') ? '\\' : '';
    }

    /**
     * Copy the delta event with the given text, putting the original values back.
     */
    protected function withDelta(TextDelta|ReasoningDelta $event, string $text, string $replacementKey): TextDelta|ReasoningDelta
    {
        $event = clone $event;
        $event->delta = SmartPiiRedactor::reapplyMaskedText($text, $replacementKey);

        return $event;
    }

    /**
     * Put the original values back into the model's reply and tool call arguments.
     */
    protected function restoreResponse(StepResponse $response, string $replacementKey): StepResponse
    {
        $response->text = SmartPiiRedactor::reapplyMaskedText($response->text, $replacementKey);

        $response->reasoning = SmartPiiRedactor::reapplyMaskedText($response->reasoning, $replacementKey);

        foreach ($response->toolCalls as $toolCall) {
            $this->restoreArguments($toolCall->arguments, $replacementKey);
        }

        foreach ($response->providerToolCalls as $providerToolCall) {
            $this->restoreArguments($providerToolCall->data, $replacementKey);
        }

        return $response;
    }

    /**
     * Put the original values back into the strings of the given (nested) array.
     */
    protected function restoreArguments(array &$arguments, string $replacementKey): void
    {
        array_walk_recursive($arguments, function (&$value) use ($replacementKey): void {
            if (is_string($value)) {
                $value = SmartPiiRedactor::reapplyMaskedText($value, $replacementKey);
            }
        });
    }
}
