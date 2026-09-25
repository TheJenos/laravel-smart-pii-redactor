# Smart PII Redactor for Laravel AI

[![Latest Version on Packagist](https://img.shields.io/packagist/v/thejenos/smart-pii-redactor.svg?style=flat-square)](https://packagist.org/packages/thejenos/smart-pii-redactor)
[![Tests](https://img.shields.io/github/actions/workflow/status/TheJenos/laravel-smart-pii-redactor/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/TheJenos/laravel-smart-pii-redactor/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/thejenos/smart-pii-redactor.svg?style=flat-square)](https://packagist.org/packages/thejenos/smart-pii-redactor)

Keep personal data out of your LLM provider's hands. This package adds a provider driver to [laravel/ai](https://github.com/laravel/ai) that sits in front of any other provider (OpenAI, Anthropic, Gemini, …). It finds PII in everything sent to the model, swaps it for placeholders like `[PERSON_0]`, and swaps the real values back into the model's reply.

```text
You write:            Email Dana Whitcombe at dana@halcyon.example.com about the Rotterdam incident.
The provider sees:    Email [PERSON_0] at [EMAIL_0] about the [LOCATION_0] incident.
The model replies:    I've drafted the email to [PERSON_0] ([EMAIL_0]) ...
Your app receives:    I've drafted the email to Dana Whitcombe (dana@halcyon.example.com) ...
```

Your application, including stored conversations, always works with the real values. Only the request to the provider is redacted.

## Features

- **Drop-in provider driver**: wrap an existing laravel/ai provider; your agents don't change.
- **Hybrid detection**: an on-device [Stanford NER](https://nlp.stanford.edu/software/CRF-NER.html) model finds names, organizations and locations, and regex rules find structured data such as emails, cards and API keys. Nothing is sent to a third party for detection.
- **Reversible masking**: placeholders are restored in replies, streamed text, reasoning and tool call arguments. Your tools receive real values.
- **Streaming-safe**: placeholders split across stream chunks (`[PER` + `SON_0]`) are held back until they're complete, then restored.
- **Tolerant restore**: variants models tend to write back, such as `\[PERSON\_0\]`, `[person_0]` or `[PERSON 0]`, are restored too.
- **Full conversation coverage**: history, tool calls and tool results in multi-step runs are masked, not just the latest prompt.
- **Unredacted storage**: conversations remembered by laravel/ai are stored with the original text.
- **Audit logging**: optionally log the exact HTTP request and response exchanged with the provider, so you can verify what left your server.

## Detected entities

| Entity | Detected by | Example |
|---|---|---|
| `PERSON` | NER model | Dana Whitcombe |
| `ORGANIZATION` | NER model | Halcyon Data Systems |
| `LOCATION` | NER model | Rotterdam |
| `EMAIL` | Regex | dana@example.com |
| `URL` | Regex | https://reports.example.com/v2/export |
| `IPV4_ADDRESS` | Regex | 203.0.113.47 |
| `IPV6_ADDRESS` | Regex | 2001:db8:4f2a::9c1 |
| `SSN` | Regex | 123-45-6789 |
| `CREDIT_CARD` | Regex | 4111 1111 1111 1111 |
| `PHONE` | Regex | +1 (503) 555-0142 |
| `IBAN` | Regex | GB82WEST12345698765432 |
| `API_KEY` | Regex | `sk-live-…`, `AKIA…`, `secret_key…` |
| `BEARER_TOKEN` | Regex | `Bearer eyJhbGciOi…` |

Detection is heuristic. NER models miss some names and flag some non-names, and the phone and IPv6 patterns can match things like dates and times. Treat this as a strong safety net, not a guarantee, and use [logging](#verifying-what-is-sent) to check real traffic.

## Requirements

- PHP 8.4+ with the **FFI** and **bz2** extensions
- Laravel 11, 12 or 13
- [laravel/ai](https://github.com/laravel/ai) ^1.0
- About 350 MB of disk space for the English NER model

The NER model is loaded through PHP's FFI extension. With the default `ffi.enable=preload`, FFI works only on the command line (queues, Artisan), not under PHP-FPM or Octane. To redact in web requests, set this in your `php.ini`:

```ini
ffi.enable = true
```

## Installation

Install the package:

```bash
composer require thejenos/smart-pii-redactor
```

Named-entity recognition uses [Stanford NER](https://nlp.stanford.edu/software/CRF-NER.html), which runs on Java, so a Java runtime (8 or newer) must be available as `java` on the `PATH`.

The Stanford NER jar and the English 3-class classifier ship with the package in `resources/models`, so there's nothing else to download.

## Configuration

Add a provider named `redactor` to `config/ai.php` and point it at the provider that should actually handle the requests:

```php
'providers' => [

    'openai' => [
        'driver' => 'openai',
        'key' => env('OPENAI_API_KEY'),
    ],

    'redactor' => [
        'driver' => 'redactor_wrapper_driver',

        // The provider (from this file) that receives the redacted requests.
        'base_driver' => 'openai',

        // 'mask' swaps PII for reversible placeholders like [PERSON_0].
        // 'redact' replaces it with *** for good (nothing is restored).
        'method' => 'mask',

        // Limit detection to some entities, or skip some. Use one or the other.
        'only' => [],
        'except' => [],

        // Log the raw HTTP traffic with the base provider (see below).
        'log' => env('PII_REDACTOR_LOG', false),
        'log_channel' => null,
    ],

],
```

The provider must be named `redactor`: the driver reads its settings from `ai.providers.redactor`.

`only` and `except` take entity names or `SmartPiiRedactorEntites` cases:

```php
use TheJenos\SmartPiiRedactor\SmartPiiRedactorEntites;

'except' => [SmartPiiRedactorEntites::LOCATION, 'ORGANIZATION'],
```

## Usage

Use `redactor` wherever you'd pick a provider. Everything else about your agents stays the same.

Per agent, with laravel/ai's `Provider` attribute:

```php
use Laravel\Ai\Attributes\Provider;

#[Provider('redactor')]
class SupportAgent implements Agent
{
    use Promptable;

    // ...
}
```

Or per call:

```php
$response = (new SupportAgent)->prompt(
    'Summarise the ticket from Dana Whitcombe (dana@halcyon.example.com).',
    provider: 'redactor',
);

$response->text; // Contains "Dana Whitcombe", never a placeholder.
```

Streaming works the same way:

```php
return (new SupportAgent)->stream($prompt, provider: 'redactor');
```

Or make it the default for every agent in `config/ai.php`:

```php
'default' => 'redactor',
```

### Remembered conversations

Agents that remember conversations store the **original** prompt and the restored reply. Earlier messages are masked again on every request, so history never reaches the provider unredacted either.

### Tools

Tool call arguments are restored before your tool runs, so tools receive real values. Tool results are masked before they're sent back to the model.

Provider-side tools are different: OpenAI's `web_search`, for example, runs on the provider's servers with the placeholder, so it searches for `weather in [LOCATION_0]`. The event and the stored step show the restored value, but the search results won't reflect it. If a provider tool needs a real value, either exclude that entity with `except`, or use your own tool instead.

## Using the redactor directly

You can also detect and mask text yourself, without laravel/ai:

```php
use Illuminate\Support\Str;
use TheJenos\SmartPiiRedactor\SmartPiiRedactor;
use TheJenos\SmartPiiRedactor\SmartPiiRedactorEntites;

$redactor = app(SmartPiiRedactor::class);

// Detect entities: [['text' => 'Dana Whitcombe', 'tag' => 'PERSON'], ...]
$entities = $redactor->getEntities($text);

// Or restrict detection.
$entities = $redactor->getEntities($text, onlyEntities: [SmartPiiRedactorEntites::EMAIL]);
$entities = $redactor->getEntities($text, exceptEntities: ['LOCATION']);

// Irreversible: replace every entity with ***.
$redacted = $redactor->redact($text, $entities);

// Reversible: replace entities with [TAG_N] placeholders.
$key = Str::random(10);
$masked = $redactor->mask($text, $entities, $key);

// ...send $masked somewhere, get $reply back...

$restored = SmartPiiRedactor::reapplyMaskedText($reply, $key);

// The placeholder => value map, if you need it.
$map = SmartPiiRedactor::getCacheReplacement($key);
```

The placeholder map is kept in your application's default cache store under the given key.

## Verifying what is sent

Set `'log' => true` on the `redactor` provider to record the traffic between your app and the base provider:

| Message | Level | Contents |
|---|---|---|
| `Provider request.` | debug | Method, URL, headers and the JSON body exactly as sent |
| `Provider response.` | debug | Status, headers and the JSON body (`(streamed)` for streams) |
| `Provider stream finished.` | debug | The full streamed reply and tool calls, as received |
| `Detected PII is still present in an outgoing message.` | **warning** | Message index, role and entity tag |

Messages are prefixed with `[smart-pii-redactor]`. Logs only ever contain the redacted data the provider saw, never the restored values. The leak warning names the entity type, not the value. `Authorization`, `x-api-key`, `api-key` and `x-goog-api-key` headers are replaced with `***`.

## Security notes

- **Placeholder maps contain the original PII.** They're written to your default cache store without an expiry, so use a store you'd trust with the raw data, and clear it as your retention policy requires.
- **Only what's detected is masked.** Undetected PII is sent as-is, and system instructions (`instructions()`) aren't scanned.
- **Attachments aren't scanned.** Files and images are sent to the provider unchanged.

## Testing

```bash
composer test
```

The tests use the real NER model, so run the installation commands above first.

## Changelog

See [CHANGELOG](CHANGELOG.md) for what has changed recently.

## Releasing

Releases are automated. Bump `version` in `composer.json` and push to `main`. The [release workflow](.github/workflows/release.yml) runs the tests, tags `vX.Y.Z`, publishes a GitHub release, notifies Packagist and updates the changelog. Pushes that don't change the version don't release anything.

## Security vulnerabilities

Please report security issues privately to [nadunnew@gmail.com](mailto:nadunnew@gmail.com) rather than opening a public issue.

## Credits

- [Thanura Nadun](https://github.com/TheJenos)
- [All contributors](https://github.com/TheJenos/laravel-smart-pii-redactor/graphs/contributors)
- [Stanford NER](https://nlp.stanford.edu/software/CRF-NER.html) and [PHP-Stanford-NLP](https://github.com/agentile/PHP-Stanford-NLP) for named-entity recognition

## License

The MIT License (MIT). See [LICENSE](LICENSE.md) for more information.
