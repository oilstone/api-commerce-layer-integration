# API Commerce Layer Integration

This package provides the foundations for integrating the [`garethhudson07/api`](https://github.com/garethhudson07/api) toolkit with the [Commerce Layer](https://commercelayer.io) platform. It includes an HTTP client, query builder, and repository abstraction tailored to Commerce Layer's JSON:API compliant endpoints.

## Highlights

- A dedicated HTTP client (`Clients\\CommerceLayer`) that encapsulates authentication, logging, and error handling for Commerce Layer requests.
- A fluent query builder (`Query`) capable of compiling Commerce Layer filter, include, sorting, and pagination parameters.
- A repository abstraction (`Repository`) that applies default constraints, manages caching, and exposes CRUD helpers for Commerce Layer resources.
- Lightweight record and collection helpers for working with JSON:API responses.
- Optional PSR-16 caching support via the `Cache\\QueryCacheHandler` utility.

The package is intentionally lightweight so it can act as the starting point for bespoke integrations. Additional bridges (for example to the API pipeline or resource loader packages) can be layered on using the same methodology throughout this codebase.

## Installation

Install the package via Composer:

```bash
composer require oilstone/api-commerce-layer-integration
```

## Usage

```php
use GuzzleHttp\Client as HttpClient;
use Oilstone\ApiCommerceLayerIntegration\Clients\CommerceLayer;
use Oilstone\ApiCommerceLayerIntegration\Repository;

$http = new HttpClient();
$client = new CommerceLayer($http, 'https://your-organization.commercelayer.io/api', 'your-access-token');

$repository = (new Repository('orders'))
    ->setClient($client)
    ->setDefaultIncludes(['customer', 'line_items']);

$orders = $repository->get([
    ['status', '=', 'placed'],
    ['number', 'like', 'UK%'],
]);

$firstOrder = $repository->find('order-id-123');
```

## Testing

```bash
composer validate
find src -name "*.php" -print0 | xargs -0 -n1 php -l
```

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
