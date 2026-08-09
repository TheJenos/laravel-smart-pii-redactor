<?php

namespace TheJenos\SmartPiiRedactor;

use Closure;
use Laravel\Ai\Prompts\AgentPrompt;

class SmartPiiRedactorMiddleware
{
    protected $method;

    public function __construct($method = 'redact')
    {
        $this->method = $method;
    }

    public function handle(SmartPiiRedactor $smartPiiRedactor, AgentPrompt $prompt, Closure $next)
    {
        $entities = $smartPiiRedactor->getEntities($prompt->prompt);

        if (count($entities) === 0) {
            return $next($prompt);
        }

        $replacement = [];

        switch ($this->method) {
            case 'redact':
                $newPrompt = $smartPiiRedactor->redact($prompt->prompt, $entities);
                break;
            case 'mask':
                $newPrompt = $smartPiiRedactor->mask($prompt->prompt, $entities);
                break;
            case 'mask-replace':
                [$newPrompt, $replacement] = $smartPiiRedactor->maskWithMap($prompt->prompt, $entities);
                break;
        }

        $output = $next($prompt->revise($newPrompt, $replacement));

        return $output;
    }
}
