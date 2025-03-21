# HTTP-Client

[a]: https://docs.guzzlephp.org/en/stable/quickstart.html#creating-a-client
[b]: https://docs.guzzlephp.org/en/stable/request-options.html
[c]: https://github.com/guzzle/guzzle/issues/1722
[d]: https://www.php-fig.org/psr/psr-18/
[e]: https://www.php-fig.org/psr/psr-7/

## Overview
_HTTP-Client_ is a [PSR-18][d]-compatible wrapper around Guzzle's `\GuzzleHttp\Client`, providing enhanced default settings with customizable retry logic. It ensures that cURL is used for all requests and offers built-in error-handling mechanisms to improve debugging and resilience.

Guzzle's built-in retry mechanism requires a lot of boilerplate code and is difficult to use in real-world scenarios. It also has a major limitation: you can't modify a request before retrying. This becomes a problem when a token expires, and you need to update an Authentication header or a form parameter with a new token. One of Guzzle’s key benefits is its ability to automatically create request bodies based on request options, like JSON encoding or assembling form parameters from an array. However, its built-in retry middleware does not support this feature. In version 1.5, this library introduced a custom retry middleware to overcome these issues. But in practice, it was still impractical — especially when modifying headers. The only way to do so was by creating a new [PSR-7][e] request object, which added complexity. Starting with version 2.0, _HTTP-Client_ simplifies this process. The retry callback now receives the method, URI, and request options directly. This makes modifying the request before retrying much easier.

## Features
- **Enforced cURL Usage**: cURL is the industry-standard tool for HTTP requests. Consistently using cURL simplifies debugging by providing well-documented error messages and avoids unnecessary complexity caused by switching between different request methods in pursuit of negligible performance optimizations
- **Exponential Backoff Retry Mechanism**: Retries failed requests with increasing delays based on HTTP code
- **Configurable Retry Behavior**: Custom retry logic via a callback. This callback allows fine-grained control over retry logic by evaluating the request, response, and exception details
- **Retry-After Header Support**: Automatically respects server-provided retry delays
- **Extended Exception Messages**: Guzzle imposes an absurdly restrictive character limit on exception messages, [which is actively harmful when debugging][c]. To address this, this class automatically overrides the behavior by increasing the limit to 32767 characters
- **Support for Mock Responses**: Allows for testing without making actual HTTP requests

## Installation
```bash
composer require mensbeam/http-client
```

## Class Synopsis

**Note**: This class does not have the async methods (`Client::requestAsync` and `Client::sendAsync`) from Guzzle. While Guzzle internally relies on promises, they introduce unnecessary complexity without providing any real multitasking capabilities. Since PHP only introduced cooperative multitasking with Fibers in PHP 8.1 — and Guzzle does not utilize them — its promise-based implementation offers no actual performance benefits. Instead, it adds misleading abstraction without true async behavior.

```php
namespace MensBeam\HTTP;

class Client {
    public const REQUEST_STOP = 0;
    public const REQUEST_RETRY = 1;
    public const REQUEST_CONTINUE = 2;

    public function __construct(array $config = []);

    public function request(string $method, string|Psr\Http\Message\UriInterface $uri = '', array $options = []): Psr\Http\Message\ResponseInterface;
    public function send(Psr\Http\Message\RequestInterface $request, array $options = []): Psr\Http\Message\ResponseInterface;
}
```

### Client::__construct
#### Description
```php
public function Client::__construct(array $config = [])
```

Returns new `Client` object.

#### Parameters
**config** - An array of configuration options

### Client::request
#### Description
```php
public function Client::request(string $method, string|Psr\Http\Message\UriInterface $uri = '', array $options = []): Psr\Http\Message\ResponseInterface
```

Creates and sends an HTTP request with built-in retry logic defaults.

#### Parameters
**method** - HTTP method
**uri** - URI object or string
**options** - Request options to apply

### Client::send
#### Description
```php
public function Client::send(Psr\Http\Message\RequestInterface $request, array $options = []): Psr\Http\Message\ResponseInterface;
```

Sends a supplied HTTP request with built-in retry logic defaults.

#### Parameters
**request** - PSR-7 Request object
**options** - Request options to apply

## Usage
### Basic Example
```php
use MensBeam\HTTP\Client;

$client = new Client();
$response = $client->request('GET', 'https://api.example.com/data');
echo (string)$response->getBody();
```

### Custom Retry Logic
```php
use MensBeam\HTTP\Client;

$callback = function (
    int $retryAttempt,
    string $method,
    string|UriInterface $uri,
    array $options,
    int $delay,
    ?ResponseInterface $response = null,
    ConnectException|RequestException|null $exception = null
): int {
    if ($response?->getStatusCode() === 400) {
        return Client::REQUEST_RETRY;
    }
    return Client::REQUEST_CONTINUE;
};

$client = new Client('GET', 'https://ook.com', [ 'retry_callback' => $callback ]);
```

### Custom Retry Logic with Modified Request
```php
use MensBeam\HTTP\Client;

$callback = function (
    int $retryAttempt,
    string $method,
    string|UriInterface &$uri,
    array $options,
    int $delay,
    ?ResponseInterface $response = null,
    ConnectException|RequestException|null $exception = null
): int {
    if ($response?->getStatusCode() === 400) {
        $uri = 'https://eek.com';
        return Client::REQUEST_RETRY;
    }
    return Client::REQUEST_CONTINUE;
};

$client = new Client([ 'retry_callback' => $callback ]);
```

### Using a Logger
```php
use MensBeam\{
    HTTP\Client,
    Logger,
    Logger\StreamHandler
};

$logger = new Logger('http_logger', new StreamHandler('php://stdout', range(0, 7)));
$client = new Client(['logger' => $logger]);
$response = $client->request('GET', 'https://api.example.com/data');
```

### Mocking Responses for Testing
```php
use MensBeam\HTTP\Client,
    GuzzleHttp\Psr7\Response;

$client = new Client([
    'dry_run' => [
        new Response(418, [], 'Short and stout?'),
        new Response(200, [], 'Mock response data')
    ],
    'retry_callback' => function (
        int $retryAttempt,
        string $method,
        string|UriInterface $uri,
        array $options,
        int $delay,
        ?ResponseInterface $response = null,
        ConnectException|RequestException|null $exception = null
    ): int {
        $code = $response?->getStatusCode();

        if ($code === 418) {
            return Client::REQUEST_RETRY;
        }
        return Client::REQUEST_CONTINUE;
    }
]);
$response = $client->request('GET', 'https://api.example.com/data');
echo (string)$response->getBody();
```

### Adding a middleware to the stack
```php
use MensBeam\HTTP\Client;
use GuzzleHttp\Middleware;

$tapMiddleware = Middleware::tap(function (RequestInterface $request) {
    echo 'Sending request to: ' . $request->getUri() . PHP_EOL;
});

$client = new Client([ 'middleware' => $tapMiddleware ]);
$response = $client->request('GET', 'https://api.example.com/data');
echo (string)$response->getBody();
```

## Configuration Options
| Option              | Type                                               | Default | Description                                             |
|---------------------|----------------------------------------------------|---------|---------------------------------------------------------|
| `dry_run`           | `bool\|\Psr\Http\Message\ResponseInterface\|array` | `false` | Enables mock responses                                  |
| `logger`            | `Psr\Log\LoggerInterface\|null`                    | `null`  | Logs debugging information                              |
| `max_retries`       | `int`                                              | `10`    | Maximum number of retries                               |
| `middleware`        | `array\|Closure`                                   | `null`  | Middleware stack configuration                          |
| `retry_callback`    | `?callable`                                        | `null`  | Custom retry logic callback; fires after every response |

**NOTE**: Earlier versions of _HTTP-Client_ had the `on_retry` option. It was replaced with `retry_callback` in version 1.5. This is because the original name didn't accurately reflect when it was called. It fires after every response regardless of outcome, and its return value determines if it is retried.

Configuration may be applied both in the constructor and in `Client::request` and `Client::send`. All configuration on the request overrides any configuration on the class but only for that request. _HTTP-Client_ will also accept the remaining Guzzle [Client configuration][a] and [request options][b].

## License
This project is licensed under the MIT License. See the `LICENSE` file for details.