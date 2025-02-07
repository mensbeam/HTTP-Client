# HTTP-Client

[a]: https://docs.guzzlephp.org/en/stable/quickstart.html#creating-a-client
[b]: https://docs.guzzlephp.org/en/stable/request-options.html
[c]: https://github.com/guzzle/guzzle/issues/1722
[d]: https://www.php-fig.org/psr/psr-18/

## Overview
_HTTP-Client_ is a [PSR-18][d]-compatible and `\GuzzleHttp\ClientInterface`-compatible wrapper around Guzzle's `\GuzzleHttp\Client`, providing enhanced default settings with customizable retry logic. It ensures that cURL is used for all requests and offers built-in error-handling mechanisms to improve debugging and resilience.

Guzzle's built-in retry mechanism is powerful but often overly complex. In practical scenarios, retrying requests is a common need and shouldn't require excessive boilerplate. This class aims to rectify that.

## Features
- **Enforced cURL Usage**: cURL is the industry-standard tool for HTTP requests. Consistently using cURL simplifies debugging by providing well-documented error messages and avoids unnecessary complexity caused by switching between different request methods in pursuit of negligible performance optimizations
- **Exponential Backoff Retry Mechanism**: Retries failed requests with increasing delays based on HTTP code
- **Configurable Retry Behavior**: Custom retry logic via a callback. This callback allows fine-grained control over retry logic by evaluating the request, response, and exception details
- **Retry-After Header Support**: Automatically respects server-provided retry delays
- **Extended Exception Messages**: Guzzle imposes an absurdly restrictive character limit on exception messages, [which is actively harmful when debugging][c]. To address this, this class automatically overrides the behavior by increasing the limit to 32767 characters
- **Support for Mock Responses**: Allows testing without making actual HTTP requests

## Installation
```bash
composer require mensbeam/http-client
```

## Class Synopsis

**Note**: This does not document the async methods (`Client::requestAsync` and `Client::sendAsync`). While Guzzle internally relies on promises, they introduce unnecessary complexity without providing any real multitasking capabilities. Since PHP only introduced cooperative multitasking with Fibers in PHP 8.1 — and Guzzle does not utilize them — its promise-based implementation offers no actual performance benefits. Instead, it adds misleading abstraction without true async behavior. We have implemented these methods to maintain compatibility with `\GuzzleHttp\ClientInterface` so this class may be used in place of `\GuzzleHttp\Client` in Guzzle's other classes if need arises, but we do not support them and suggest not using them.

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

Returns new _HTTP-Client_ object.

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
    int $retries,
    RequestInterface $request,
    ?ResponseInterface $response = null,
    ?RequestException $exception = null,
    ?int &$dynamicDelay = null
): int {
    if ($response && $response->getStatusCode() === 400) {
        return Client::REQUEST_RETRY;
    }
    return Client::REQUEST_CONTINUE;
};

$client = new Client([ 'on_retry' => $callback ]);
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

$mockResponse = new Response(200, [], 'Mock response data');
$client = new Client(['dry_run' => $mockResponse]);
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
| Option        | Type                                               | Default | Description                     |
|---------------|----------------------------------------------------|---------|---------------------------------|
| `dry_run`     | `bool\|\Psr\Http\Message\ResponseInterface\|array` | `false` | Enables mock responses          |
| `logger`      | `Psr\Log\LoggerInterface\|null`                    | `null`  | Logs debugging information      |
| `max_retries` | `int`                                              | `10`    | Maximum number of retries       |
| `middleware`  | `array\|Closure`                                   | `null`  | Middleware stack configuration  |
| `on_retry`    | `?callable`                                        | `null`  | Custom retry logic callback     |

Configuration may be applied both in the constructor and in `Client::request` and `Client::send`. All configuration on the request overrides any configuration on the class but only for that request. _HTTP-Client_ will also accept the remaining Guzzle [Client configuration][a] and [request options][b].

## License
This project is licensed under the MIT License. See the `LICENSE` file for details.