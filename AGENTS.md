# AstraMail Laravel Package — Agent Execution Plan

> **Goal:** Extract the custom AstraMail transport from the outbound-api project into a reusable, auto-discoverable Composer/Laravel package so any Laravel project can install and use it with minimal configuration.
>
> **Execute every numbered step in order. Verify each step before moving on.**

---

## 1. Create the Package Directory

Create a new directory (separate from the Laravel app). Recommended name and location:

```
rifkiard/
└── astramail/           ← package root
    ├── composer.json
    ├── README.md
    ├── .gitignore
    ├── config/
    │   └── astramail.php
    └── src/
        ├── AstraMailServiceProvider.php
        └── AstraMailTransport.php
```

You can keep this repo at `github.com/rifkiard/astramail` (private) or host on a self-managed VCS.

---

## 2. Create `composer.json`

File: `astramail/composer.json`

```json
{
  "name": "rifkiard/astramail",
  "description": "Custom Laravel mail transport for Astra World internal mail service.",
  "type": "library",
  "license": "proprietary",
  "keywords": ["laravel", "mail", "transport", "astraworld"],
  "authors": [
    {
      "name": "Astra World",
      "email": "dev@astraworld.com"
    }
  ],
  "require": {
    "php": "^8.1",
    "illuminate/support": "^10.0 || ^11.0 || ^12.0",
    "illuminate/http": "^10.0 || ^11.0 || ^12.0",
    "symfony/mailer": "^6.2 || ^7.0",
    "symfony/mime": "^6.2 || ^7.0"
  },
  "autoload": {
    "psr-4": {
      "Rifkiard\\AstraMail\\": "src/"
    }
  },
  "extra": {
    "laravel": {
      "providers": ["Rifkiard\\AstraMail\\AstraMailServiceProvider"]
    }
  },
  "minimum-stability": "stable",
  "prefer-stable": true
}
```

**Key points:**

- `extra.laravel.providers` enables Laravel package auto-discovery — no manual registration needed in consumer apps.
- Supports Laravel 10, 11, and 12.

---

## 3. Create `config/astramail.php`

File: `astramail/config/astramail.php`

```php
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Astra Mail API Base URL
    |--------------------------------------------------------------------------
    | The base URL of the Astra internal mail service.
    | Example: https://mail.internal.astraworld.com
    */
    'base_url' => env('MAIL_ASTRA_BASE_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Astra Mail Client Code
    |--------------------------------------------------------------------------
    | Sent as the X-Client-Code header on every request.
    */
    'client_code' => env('MAIL_ASTRA_CLIENT_CODE', ''),

    /*
    |--------------------------------------------------------------------------
    | TLS Verification
    |--------------------------------------------------------------------------
    | Set to false only in internal/dev environments.
    | Strongly recommended to set to true in production.
    */
    'verify_tls' => env('MAIL_ASTRA_VERIFY_TLS', false),

];
```

---

## 4. Create `src/AstraMailTransport.php`

File: `astramail/src/AstraMailTransport.php`

```php
<?php

namespace Rifkiard\AstraMail;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

class AstraMailTransport extends AbstractTransport
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email    = MessageConverter::toEmail($message->getOriginalMessage());
        $baseUrl  = config('astramail.base_url');
        $clientCode = config('astramail.client_code');
        $verifyTls  = config('astramail.verify_tls', false);

        $data = [
            'from'    => collect($email->getFrom())->map(fn ($a) => $a->getAddress())->join(';'),
            'to'      => collect($email->getTo())->map(fn ($a) => $a->getAddress())->join(';'),
            'subject' => $email->getSubject(),
            'content' => $email->getHtmlBody() ?? $email->getTextBody(),
        ];

        if (count($email->getCc())) {
            $data['cc'] = collect($email->getCc())->map(fn ($a) => $a->getAddress())->join(';');
        }

        if (count($email->getBcc())) {
            $data['bcc'] = collect($email->getBcc())->map(fn ($a) => $a->getAddress())->join(';');
        }

        $request = Http::withOptions(['verify' => $verifyTls])
            ->withHeaders(['X-Client-Code' => $clientCode]);

        // BUG FIX: original code discarded attach() return value (fluent/immutable).
        // Reassign $request on every iteration to accumulate attachments correctly.
        foreach ($email->getAttachments() as $index => $attachment) {
            $request = $request->attach(
                'file' . $index,
                $attachment->getBody(),
                $attachment->getFilename()
            );
        }

        $hasAttachments = count($email->getAttachments()) > 0;

        $response = $hasAttachments
            ? $request->post("{$baseUrl}/send_email", $data)
            : $request->asForm()->post("{$baseUrl}/send_email", $data);

        // Log result for observability.
        logger()->info('AstraMailTransport', [
            'status'   => $response->status(),
            'response' => $response->json(),
            'to'       => $data['to'],
            'subject'  => $data['subject'],
        ]);

        // Surface HTTP errors as exceptions so Laravel mail knows the send failed.
        if ($response->failed()) {
            throw new \RuntimeException(
                "AstraMail send failed [{$response->status()}]: " . $response->body()
            );
        }
    }

    public function __toString(): string
    {
        return 'astramail';
    }
}
```

**Improvements over original:**
| Issue | Fix |
|---|---|
| `$request->attach()` return value was discarded | Reassigned `$request = $request->attach(...)` in loop |
| No failure detection | Added `if ($response->failed()) throw` |
| TLS hard-coded `false` | Configurable via `MAIL_ASTRA_VERIFY_TLS` |
| Config read from `mail.astra.*` | Now reads from dedicated `astramail.*` config key |

---

## 5. Create `src/AstraMailServiceProvider.php`

File: `astramail/src/AstraMailServiceProvider.php`

```php
<?php

namespace Rifkiard\AstraMail;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class AstraMailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Merge package config so consumers don't need to publish it.
        $this->mergeConfigFrom(
            __DIR__ . '/../config/astramail.php',
            'astramail'
        );
    }

    public function boot(): void
    {
        // Allow consumers to publish the config for customisation.
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/astramail.php' => config_path('astramail.php'),
            ], 'astramail-config');
        }

        // Register the custom transport driver with Laravel's Mail manager.
        Mail::extend('astramail', function (array $config = []) {
            return new AstraMailTransport();
        });
    }
}
```

---

## 6. Create `README.md`

File: `astramail/README.md`

````markdown
# rifkiard/astramail

Custom Laravel mail transport for the Astra World internal mail service.

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12

## Installation

```bash
composer require rifkiard/astramail
```

Auto-discovery registers the service provider automatically.

## Environment Variables

Add to your `.env`:

```env
MAIL_MAILER=astramail
MAIL_ASTRA_BASE_URL=https://mail.internal.astraworld.com
MAIL_ASTRA_CLIENT_CODE=your-client-code
MAIL_ASTRA_VERIFY_TLS=true   # set false only for internal/dev
```

## Mail Config

Add the `astramail` mailer entry to `config/mail.php`:

```php
'mailers' => [
    // ... other mailers
    'astramail' => [
        'transport' => 'astramail',
    ],
],
```

Set the default:

```php
'default' => env('MAIL_MAILER', 'astramail'),
```

## Publish Config (optional)

```bash
php artisan vendor:publish --tag=astramail-config
```

This publishes `config/astramail.php` to your application for customisation.
````

---

## 7. Initialize Git and Tag

```bash
cd astramail/
git init
git add .
git commit -m "feat: initial AstraMail Laravel package"
git tag v1.0.0
git remote add origin https://github.com/rifkiard/astramail.git
git push -u origin main --tags
```

### Release History

| Tag | Commit | Description |
|---|---|---|
| `v1.0.0` | `2748a28` | feat: initial AstraMail Laravel package |
| `v1.0.1` | `125dd2e` | chore: add .gitignore |
| `v1.0.2` | `556592d` | feat: add Laravel 12 support (`^12.0` on illuminate/*) |
| `v1.0.3` | `3d43c74` | refactor: rename namespace `AstraWorld` → `Rifkiard` |

---

## 8. Consuming the Package in Any Laravel Project

### Option A — Private GitHub repo via `composer.json`

In the consumer project's `composer.json`, add a VCS repository entry:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/rifkiard/astramail.git"
    }
],
```

Then install:

```bash
composer require rifkiard/astramail
```

### Option B — Private Packagist / Satis

Set up a [Satis](https://github.com/composer/satis) server or use [Private Packagist](https://packagist.com/) and point `repositories` there.

### Option C — Path repository (local monorepo)

```json
"repositories": [
    {
        "type": "path",
        "url": "../astramail"
    }
],
```

---

## 9. Consumer Project Checklist (after `composer require`)

- [ ] `.env` — add `MAIL_MAILER`, `MAIL_ASTRA_BASE_URL`, `MAIL_ASTRA_CLIENT_CODE`, `MAIL_ASTRA_VERIFY_TLS`
- [ ] `config/mail.php` — add `astramail` entry under `mailers`, set `default`
- [ ] _(Optional)_ `php artisan vendor:publish --tag=astramail-config`
- [ ] Send a test email and verify `astramail` log entries appear

---

## 10. Future Improvements (Backlog)

| Idea               | Notes                                                            |
| ------------------ | ---------------------------------------------------------------- |
| Retry logic        | Add configurable retry count/backoff on HTTP failure             |
| Queue-aware send   | Hook into `ShouldQueue` jobs seamlessly                          |
| Test fake          | Provide `AstraMailFake` similar to `Mail::fake()` for unit tests |
| Multiple endpoints | Support environment-scoped URLs (prod vs staging)                |
| Laravel 12 support | ~~Bump `illuminate/*` constraints when released~~ **Done in v1.0.2** |
