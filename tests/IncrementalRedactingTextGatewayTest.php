<?php

use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use TheJenos\SmartPiiRedactor\Provider\IncrementalRedactingTextGateway;
use TheJenos\SmartPiiRedactor\SmartPiiRedactor;

beforeEach(function () {
    // Detects known names with a lookup instead of the NER model, and records what it was asked to scan...
    $this->redactor = new class extends SmartPiiRedactor
    {
        public array $scanned = [];

        public function getEntities(string $text, array $onlyEntities = [], array $exceptEntities = []): array
        {
            $this->scanned[] = $text;

            $names = ['Dana Whitcombe' => 'PERSON', 'Rotterdam' => 'LOCATION', 'Halcyon' => 'ORGANIZATION'];

            return $this->uniqueEntities(array_values(array_map(
                fn ($name) => ['text' => $name, 'tag' => $names[$name]],
                array_filter(array_keys($names), fn ($name) => str_contains($text, $name)),
            )));
        }
    };

    app()->instance(SmartPiiRedactor::class, $this->redactor);

    $this->detect = fn (array $messages, array $config = []) => (fn () => $this->detectEntities($messages))
        ->call(new IncrementalRedactingTextGateway(Mockery::mock(StepTextGateway::class), $config));
});

it('only scans messages it has not seen before', function () {
    $history = [
        new UserMessage('Email Dana Whitcombe about the incident.'),
        new AssistantMessage('Sure, what happened?'),
    ];

    ($this->detect)($history);

    $entities = ($this->detect)([...$history, new UserMessage('It happened in Rotterdam.')]);

    expect($this->redactor->scanned)->toBe([
        'Email Dana Whitcombe about the incident.'.PHP_EOL.'Sure, what happened?',
        'It happened in Rotterdam.',
    ]);

    expect($entities)->toBe([
        ['text' => 'Dana Whitcombe', 'tag' => 'PERSON'],
        ['text' => 'Rotterdam', 'tag' => 'LOCATION'],
    ]);
});

it('keeps earlier entities first so their tags stay stable', function () {
    ($this->detect)([new UserMessage('Dana Whitcombe from Halcyon.')]);

    $entities = ($this->detect)([
        new UserMessage('Dana Whitcombe from Halcyon.'),
        new UserMessage('Rotterdam, then Dana Whitcombe again.'),
    ]);

    expect(array_column($entities, 'text'))->toBe(['Dana Whitcombe', 'Halcyon', 'Rotterdam']);
});

it('scans again when the detected entities change', function () {
    $messages = [new UserMessage('Email Dana Whitcombe.')];

    ($this->detect)($messages);
    ($this->detect)($messages, ['except' => ['LOCATION']]);
    ($this->detect)($messages, ['except' => ['LOCATION']]);

    expect($this->redactor->scanned)->toHaveCount(2);
});
