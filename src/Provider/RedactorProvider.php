<?php

namespace TheJenos\SmartPiiRedactor\Provider;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

class RedactorProvider extends Provider implements TextProvider
{
    protected TextProvider $provider;

    public function __construct(protected array $config, protected Dispatcher $events)
    {
        $baseDriver = $this->config['base_driver'] ?? throw new \Exception(
            "The [{$this->config['name']}] redactor provider requires a [base_driver] configuration value."
        );

        $this->provider = clone app(AiManager::class)->textProvider($baseDriver);

        if (method_exists($this->provider, 'textGateway')) {
            $this->provider->useTextGateway(new RedactingTextGateway($this->provider->textGateway(), $this->config));
        }
    }

    public function providerCredentials(): array
    {
        return $this->provider->providerCredentials();
    }

    public function additionalConfiguration(): array
    {
        return $this->provider->additionalConfiguration();
    }

    public function withHeaders(array $headers): static
    {
        return tap(clone $this, function (self $redactor) use ($headers): void {
            $redactor->provider = $this->provider->withHeaders($headers);
        });
    }

    public function prompt(AgentPrompt $prompt): AgentResponse
    {
        return $this->provider->prompt($prompt);
    }

    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        return $this->provider->stream($prompt);
    }

    public function useTextGateway(StepTextGateway $gateway): self
    {
        $this->provider->useTextGateway(new RedactingTextGateway($gateway, $this->config));

        return $this;
    }

    public function textGenerationLoop(): TextGenerationLoop
    {
        return $this->provider->textGenerationLoop();
    }

    public function defaultTextModel(): string
    {
        return $this->provider->defaultTextModel();
    }

    public function cheapestTextModel(): string
    {
        return $this->provider->cheapestTextModel();
    }

    public function smartestTextModel(): string
    {
        return $this->provider->smartestTextModel();
    }
}
