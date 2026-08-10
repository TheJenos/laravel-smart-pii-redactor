<?php

namespace TheJenos\SmartPiiRedactor;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Log;

class SmartPiiRedactorMiddleware
{
    protected $method;
    protected $onlyEntities;
    protected $exceptEntities;

    public function __construct(string $method = 'redact', array $onlyEntities = [], array $exceptEntities = [])
    {
        $this->method = $method;
        $this->onlyEntities = $onlyEntities;
        $this->exceptEntities = $exceptEntities;
    }

    public function handle(AgentPrompt $prompt, Closure $next)
    {
        $smartPiiRedactor = app(SmartPiiRedactor::class);

        $entities = $smartPiiRedactor->getEntities($prompt->prompt, $this->onlyEntities, $this->exceptEntities);

        if (count($entities) === 0) {
            return $next($prompt);
        }

        switch ($this->method) {
            case 'redact':
                $newPrompt = $smartPiiRedactor->redact($prompt->prompt, $entities);
                break;
            case 'mask':
                $newPrompt = $smartPiiRedactor->mask($prompt->prompt, $entities);
                break;
        }

        return $next($prompt->revise($newPrompt));
    }
}
