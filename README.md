# API Commerce Layer Integration

A PHP library that streamlines communication between the [Commerce Layer](https://commercelayer.io) platform and the [`garethhudson07/api`](https://github.com/garethhudson07/api) toolkit. It packages a configured HTTP client, a JSON:API aware query builder, repository abstractions, caching helpers, and optional Laravel bindings so you can focus on modelling your business logic instead of wiring requests.

## Table of contents
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Quick start](#quick-start)
- [Core components](#core-components)
  - [CommerceLayer client](#commercelayer-client)
  - [Query builder](#query-builder)
  - [Repositories](#repositories)
  - [Record helpers](#record-helpers)
  - [Schema meta properties](#schema-meta-properties)
  - [Caching](#caching)
  - [Laravel integration](#laravel-integration)
- [Error handling](#error-handling)
- [Testing](#testing)
- [Contributing](#contributing)
- [License](#license)

## Features
- **Dedicated HTTP client** that encapsulates authentication headers, JSON:API defaults, logging hooks, and request level options tailored to Commerce Layer endpoints.
- **Fluent query builder** for composing Commerce Layer filters, includes, sparse fieldsets, pagination, and sorting using a familiar chainable API.
- **Repository abstraction** that wraps CRUD access with default constraints, eager loading, cache integration, and payload preparation.
- **Cache handler** with PSR-16 support that can memoise both direct client calls and higher level queries.
- **Laravel service provider** offering container bindings, configuration publishing, automatic token management, and an artisan cache management command.

## Requirements
- PHP 8.1 or higher.
- Composer with the ability to install `garethhudson07/api`, `guzzlehttp/guzzle`, `nesbot/carbon`, and PSR logging & caching interfaces.
- (Optional) Laravel 11+ for the provided service provider and artisan command.

## Installation
Install the package via Composer:

```bash
composer require oilstone/api-commerce-layer-integration
```

### Laravel
When using Laravel the service provider will be auto-discovered. Publish the configuration file if you need to customise connection settings:

```bash
php artisan vendor:publish --provider="Oilstone\ApiCommerceLayerIntegration\Integrations\Laravel\ServiceProvider" --tag=config
```

This will place `config/commerce-layer.php` in your application so you can set environment variables such as API URLs, OAuth client credentials, scopes, and cache defaults.

## Configuration
Create a Commerce Layer integration by providing:

- **API base URL** (e.g. `https://{organisation}.commercelayer.io/api`).
- **OAuth access token** or, in Laravel, the client credentials used to fetch one automatically.
- **Optional logging** channel and cache handler for diagnostics and performance.

When not using Laravel you are responsible for managing token lifetimes and instantiating the cache handler if desired. The examples below demonstrate the manual approach.

## Quick start
```php
use GuzzleHttp\Client as HttpClient;
use Oilstone\ApiCommerceLayerIntegration\Clients\CommerceLayer;
use Oilstone\ApiCommerceLayerIntegration\Repository;

$http = new HttpClient();
$client = new CommerceLayer(
    $http,
    'https://your-organisation.commercelayer.io/api',
    'your-access-token'
);

$orders = (new Repository('orders'))
    ->setClient($client)
    ->setDefaultIncludes(['customer', 'line_items'])
    ->get([
        ['status', '=', 'placed'],
        ['number', 'like', 'UK%'],
    ]);

$firstOrder = (new Repository('orders'))
    ->setClient($client)
    ->find('order-id-123');
```

## Core components
### CommerceLayer client
The `Clients\CommerceLayer` class wraps a `GuzzleHttp\Client` instance and automatically:

- Applies Commerce Layer JSON:API headers and bearer token authentication.
- Exposes CRUD helpers (`listResources`, `retrieve`, `create`, `update`, `delete`).
- Provides optional request logging and integrates with the query cache handler.

Each call accepts request-specific options (headers, query overrides, or logging flags) which are merged with sensible defaults.

### Query builder
`Query` offers a fluent interface for constructing JSON:API compliant requests:

- `where`, `orWhere`, and collection helpers (`whereIn`, `whereNotIn`, etc.) compile into Commerce Layer filter parameters.
- `with` registers relationship includes; `select` applies sparse fieldsets for the primary resource.
- Pagination via `limit`/`offset` is converted into `page[size]` and `page[number]` values.
- Supports retrieving the first record, fetching by id, and generating cache keys for PSR-16 integration.

### Repositories
`Repository` encapsulates common patterns when working with a specific Commerce Layer resource:

- Configure defaults for constraints, includes, identifier field, and payload values when instantiating or via setters.
- Generate new `Query` instances with defaults applied and layer in ad-hoc options (conditions, includes, sorting, pagination, cache options).
- Convenience methods for `find`, `findOrFail`, `get`, `getById`, `create`, `update`, and `delete`.
- Automatic cache handler propagation to the underlying client when provided.

### Record helpers
Use `Record::make()` or `RecordCollection::make()` to wrap JSON:API payloads in lightweight value objects with helper accessors for attributes, relationships, and metadata.

### Schema meta properties
When loading schema definitions through the bundled API integration you can mark individual properties with `meta` flags to tailor how payloads are transformed before requests are sent or after responses are received. The table below summarises the supported keys and the behaviour they unlock:

| Meta key | Effect |
| --- | --- |
| `isMetadata` | Treats the property as part of the Commerce Layer `metadata` object so it is automatically included when building sparse fieldsets and skipped when preparing write payloads. |
| `validationOnly` | Excludes the property from transformed payloads entirely so it can exist purely for local validation rules. |
| `isRelation` | Signals a relationship field that should be ignored when building attribute payloads and defaults. |
| `calculated` | Prevents the property from being sent back to the API because its value is computed by Commerce Layer. |
| `readonly` / `isReadonly` | Skips the property during reverse transformation unless values are forced (e.g. via `forceReverse`). |
| `default` | Supplies a fallback value (or resolver callback) that is merged into outgoing payloads when no explicit value is provided. |
| `fixed` | Always injects the provided value (or resolver callback result), overriding request data and bypassing `readonly` checks. |
| `delimited` | Converts between delimited strings and arrays when transforming responses and requests. |
| `beforeTransform` / `afterTransform` | Callbacks that run before or after a value is normalised during response transformation. |
| `beforeReverse` / `afterReverse` | Callbacks that run before or after a value is converted back to request format. |

Callbacks receive the property definition and, when applicable, the source attributes so you can derive contextual values. Combining these meta flags allows you to model complex behaviours such as computed defaults, coercing list fields, or ensuring metadata is always available without leaking into write operations.

### Caching
`Cache\QueryCacheHandler` implements query-level caching using a PSR-16 cache store:

- Generates namespaced cache keys and supports TTL configuration.
- Offers opt-in request logging and the ability to skip retrieval (useful for console operations).
- Integrates seamlessly with both `CommerceLayer` and `Query` to reduce duplicate API calls.

### Laravel integration
If you are building a Laravel application:

- The service provider binds the query cache handler and client into the container, handling token retrieval and caching automatically.
- Publish the configuration to adjust URLs, credentials, logging, and cache TTLs.
- Use the `commerce-layer:cache:clear` command to flush cached query results or invalidate entries for specific records.

## Error handling
All client requests raise a `CommerceLayerException` when the API responds with a non-2xx status. The exception surfaces the HTTP status code and any JSON:API error payloads for richer debugging.

Additional exceptions (such as `ObjectNotSpecifiedException` or `RecordNotFoundException`) help identify misconfigured repositories or missing records.

## Testing
Validate the package using the provided Composer scripts:

```bash
composer validate
find src -name "*.php" -print0 | xargs -0 -n1 php -l
```

These commands ensure the manifest is consistent and that all PHP files pass syntax checks.

## Contributing
Pull requests are welcome! Please ensure that new features include appropriate documentation and remain consistent with the existing architecture. Run the testing commands above before submitting changes.

## License
This package is open-sourced software licensed under the [MIT license](LICENSE).
