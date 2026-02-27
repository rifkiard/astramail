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
