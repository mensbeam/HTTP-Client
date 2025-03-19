<?php
/**
 * @license MIT
 * Copyright 2025 Dustin Wilson, et al.
 * See LICENSE and AUTHORS files for details
 */

declare(strict_types=1);
namespace MensBeam\HTTP;

use MensBeam\HTTP\Client\{
    RetryAware,
    RetryMiddleware
};
use GuzzleHttp\{
    BodySummarizer,
    Client as GuzzleClient,
    ClientInterface as GuzzleClientInterface,
    Handler\CurlHandler,
    Handler\MockHandler,
    HandlerStack,
    Middleware,
    Promise\PromiseInterface,
    Psr7\Response
};
use Psr\Http\{
    Client\ClientInterface,
    Message\RequestInterface,
    Message\ResponseInterface,
    Message\UriInterface
};
use Psr\Log\LoggerInterface;


/**
 * Wraps Guzzle's Client to provide enhanced default settings with customizable
 * retry logic at both the class and request levels.
 *
 * Guzzle imposes an absurdly restrictive character limit on exception messages,
 * which can obscure critical debugging information
 * (https://github.com/guzzle/guzzle/issues/1722). To address this, this class
 * automatically overrides the behavior by truncating to 32767 characters.
 *
 * Additionally, this class enforces the use of cURL for all requests, as it is
 * the industry-standard tool for HTTP requests. Consistently using cURL
 * simplifies debugging by providing well-documented error messages and avoids
 * unnecessary complexity caused by switching between different request methods
 * in pursuit of negligible performance optimizations.
 *
 * By default, this class retries requests using an exponential backoff strategy
 * (e.g., 1s, 2s, 4s, etc.), based on the HTTP response code. Requests receiving
 * 400-level or higher HTTP status codes are retried, with the following
 * exceptions:
 *
 *    - **400, 404, 410** – These requests will not be retried, as they indicate
 *      client-side errors that cannot be resolved by retrying.
 *    - **429, 503** – If the response includes a `Retry-After` header, the
 *      specified delay will be automatically applied before retrying the request.
 *
 * The retry behavior can be customized using the `on_retry` configuration
 * option. This callback allows fine-grained control over retry logic by
 * evaluating the request, response, and exception details. This allows for
 * flexible error-handling strategies, such as retrying specific response codes
 * or dynamically modifying retry delays.
 *
 * In addition, all of these may be overwritten by supplying a custom handler in
 * the configuration.
 */
class Client implements ClientInterface, GuzzleClientInterface {
    use RetryAware;

    /** @var array Configuration array */
    protected array $config = [];

    /** @var array Configuration array originally passed to Client, unmodified */
    protected array $configOriginal = [];

    /**
     * @var array<int|string,ResponseInterface>|bool|ResponseInterface Use a mock request
     * handler; if a Psr\Http\Message\ResponseInterface or an array of them is
     * provided it is treated as true and will return that response when making
     * requests; defaults to a 200 code response
     */
    protected array|bool|ResponseInterface $dryRun = false;

    /** @var ?callable Guzzle handler */
    protected $handler = null;

    /** @var LoggerInterface|null Logger instance */
    protected ?LoggerInterface $logger = null;

    /** @var array<int|string,\Closure>|\Closure|null Additional Guzzle middleware to use */
    protected array|\Closure|null $middleware = null;

    /** @var int Maximum number of retries */
    protected int $maxRetries = 10;

    /** @var array<mixed,string>|\Closure|string|null Callback function for retry logic */
    protected array|\Closure|string|null $onRetry = null;



    /**
     * Constructor.
     *
     * @param array $config Configuration options
     *
     * Accepts Guzzle's built-in configuration options with the following additions:
     *
     *     - dry_run: bool|array[ResponseInterface]|ResponseInterface Use a mock
     *       handler when sending requests; defaults to a 200 code response
     *     - logger: ?LoggerInterface Logger instance for debugging
     *     - max_retries: int Maximum retry attempts; 0 disables retrying
     *       (default: 10)
     *     - middleware: array[\Closure]|\Closure|null Additional Guzzle middleware to use
     *     - on_retry: ?callable Callback for retry decision logic
     *
     * @throws \InvalidArgumentException
     * @throws \OutOfRangeException
     * @see https://docs.guzzlephp.org/en/stable/quickstart.html#creating-a-client for documentation on Guzzle's Client configuration options
     */
    public function __construct(array $config = []) {
        $this->configOriginal = $config;

        $dryRun = $this->validateDryRunOption($config['dry_run'] ?? null);
        if ($dryRun instanceof \Throwable) {
            throw $dryRun;
        }
        $this->dryRun = $dryRun;
        unset($config['dry_run']);

        $handler = $this->validateHandlerOption($config['handler'] ?? null);
        if ($handler instanceof \Throwable) {
            throw $handler;
        }
        $this->handler = $handler;
        unset($config['handler']);

        $logger = $this->validateLoggerOption($config['logger'] ?? null);
        if ($logger instanceof \Throwable) {
            throw $logger;
        }
        $logger = $logger;
        unset($config['logger']);

        $maxRetries = $this->validateMaxRetriesOption($config['max_retries'] ?? null);
        if ($maxRetries instanceof \Throwable) {
            throw $maxRetries;
        }
        $this->maxRetries = $maxRetries;
        unset($config['max_retries']);

        $middleware = $this->validateMiddlewareOption($config['middleware'] ?? null, $handler);
        if ($middleware instanceof \Throwable) {
            throw $middleware;
        }
        $this->middleware = $middleware;
        unset($config['middleware']);

        $onRetry = $this->validateOnRetryOption($config['on_retry'] ?? null);
        if ($onRetry instanceof \Throwable) {
            throw $onRetry;
        }
        $this->onRetry = $onRetry;
        unset($config['on_retry']);

        $this->config = $config;
    }



    // @codeCoverageIgnoreStart
    public function getConfig(?string $option = null) {
        return $option === null
            ? $this->configOriginal
            : ($this->configOriginal[$option] ?? null);
    }
    // @codeCoverageIgnoreEnd

    /**
     * Sends an HTTP request with built-in retry logic defaults.
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string|UriInterface $uri Target URI
     * @param array $options Request options
     *
     * Accepts Guzzle's built-in request options with the following additions:
     *
     *     - dry_run: bool|array[ResponseInterface]|ResponseInterface Use a mock
     *       handler when sending requests, defaults to a 200 code response
     *     - max_retries: int Maximum retry attempts for this request; 0 disables
     *       retrying (default: 10)
     *     - middleware: array[\Closure]|\Closure|null Additional Guzzle middleware to use
     *     - on_retry: ?callable Callback for retry decision logic for this request
     *
     * HTTP-Client will also accept the remaining Guzzle Client configuration options
     * in the request options array to be applied per request
     *
     * @return ResponseInterface The HTTP response
     *
     * @throws \InvalidArgumentException
     * @throws \OutOfRangeException
     *
     * @see https://docs.guzzlephp.org/en/stable/quickstart.html#creating-a-client for documentation on Guzzle's Client configuration options
     * @see https://docs.guzzlephp.org/en/stable/request-options.html for documentation on Guzzle's request options
     */
    public function request(string $method, $uri = '', array $options = []): ResponseInterface {
        $client = $this->getGuzzleClient($options);
        if ($client instanceof \Throwable) {
            throw $client;
        }

        return $client->request($method, $uri, $options);
    }

    // @codeCoverageIgnoreStart
    public function requestAsync(string $method, $uri = '', array $options = []): PromiseInterface {
        $client = $this->getGuzzleClient($options);
        if ($client instanceof \Throwable) {
            throw $client;
        }

        return $client->requestAsync($method, $uri, $options);
    }
    // @codeCoverageIgnoreEnd

    /**
     * Send an HTTP request.
     *
     * @param RequestInterface $request The request to send
     * @param array<string,mixed> $options Request options
     *
     * Accepts Guzzle's built-in request options with the following additions:
     *
     *     - dry_run: bool|array[ResponseInterface]|ResponseInterface Use a mock
     *       handler when sending requests, defaults to a 200 code response
     *     - max_retries: int Maximum retry attempts for this request; 0 disables
     *       retrying (default: 10)
     *     - middleware: array[\Closure]|\Closure|null Additional Guzzle middleware to use
     *     - on_retry: ?callable Callback for retry decision logic for this request
     *
     * HTTP-Client will also accept the remaining Guzzle Client configuration options
     * in the request options array to be applied per request
     *
     * @throws \InvalidArgumentException
     * @throws \OutOfRangeException
     */
    public function send(RequestInterface $request, array $options = []): ResponseInterface {
        $client = $this->getGuzzleClient($options);
        if ($client instanceof \Throwable) {
            throw $client;
        }

        return $client->send($request, $options);
    }

    // @codeCoverageIgnoreStart
    public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface {
        $client = $this->getGuzzleClient($options);
        if ($client instanceof \Throwable) {
            throw $client;
        }

        return $client->sendAsync($request, $options);
    }
    // @codeCoverageIgnoreEnd

    // @codeCoverageIgnoreStart
    public function sendRequest(RequestInterface $request): ResponseInterface {
        return $this->send($request);
    }
    // @codeCoverageIgnoreEnd


    protected function getGuzzleClient(array &$options): GuzzleClient|\Throwable {
        $config = $this->config;

        $baseURI = $this->validateBaseURIOption($options['base_uri'] ?? null);
        if ($baseURI instanceof \Throwable) {
            return $baseURI;
        } elseif ($baseURI !== null) {
            $config['base_uri'] = $baseURI;
        }
        unset($options['base_uri']);

        $dryRun = $this->validateDryRunOption($options['dry_run'] ?? null);
        if ($dryRun instanceof \Throwable) {
            return $dryRun;
        }
        unset($options['dry_run']);

        $handler = $this->validateHandlerOption($options['handler'] ?? null);
        if ($handler instanceof \Throwable) {
            return $handler;
        }
        unset($options['handler']);

        $logger = $this->validateLoggerOption($options['logger'] ?? null);
        if ($logger instanceof \Throwable) {
            return $logger;
        }
        unset($options['logger']);

        $maxRetries = $this->validateMaxRetriesOption($options['max_retries'] ?? null);
        if ($maxRetries instanceof \Throwable) {
            return $maxRetries;
        }
        unset($options['max_retries']);

        $middleware = $this->validateMiddlewareOption($options['middleware'] ?? null, $handler);
        if ($middleware instanceof \Throwable) {
            return $middleware;
        }
        unset($options['middleware']);

        $onRetry = $this->validateOnRetryOption($options['on_retry'] ?? null);
        if ($onRetry instanceof \Throwable) {
            return $onRetry;
        }
        unset($options['on_retry']);

        if ($handler === null) {
            // Forces use of cURL so cURL's descriptive errors may be used when requests
            // fail
            // If dry run then use a mock handler instead
            $stack = HandlerStack::create(($dryRun) ? new MockHandler((is_array($dryRun)) ? $dryRun : [ $dryRun ]) : new CurlHandler());

            // This is required because Guzzle has a really absurdly low character count
            // (something like ~200) limit on messages, so naturally because of
            // overengineering the most asinine method of changing it exists.
            $stack->push(Middleware::httpErrors(new BodySummarizer(truncateAt: 32767)));

            // Set default error handling behavior; this can be extended using the on_retry option in the request
            if ($maxRetries > 0) {
                $stack->push(function (callable $handler) use ($logger, $maxRetries, $onRetry) {
                    return new RetryMiddleware(
                        nextHandler: $handler,
                        retryCallback: $onRetry,
                        logger: $logger,
                        maxRetries: $maxRetries
                    );
                });
            }

            if (($middleware ?? null) !== null) {
                $append = (!is_array($middleware)) ? [ $middleware ] : $middleware;
                foreach ($append as $m) {
                    $stack->push($m);
                }
            }

            $handler = $stack;
        }

        $config['handler'] = $handler;

        // Every request creates a new client because in Guzzle itself a RetryHandler
        // cannot be modified post client creation.
        return new GuzzleClient($config);
    }

    protected function validateBaseURIOption(mixed $option): mixed {
        if ($option === null) {
            // There's no class property for this option
            return null;
        }

        if (!is_string($option)) {
            $type = gettype($option);
            if ($type === 'object') {
                $type = $option::class;
            }

            return new \InvalidArgumentException(sprintf('The \'base_uri\' option needs to be a string; %s given', HandlerStack::class, $type));
        }

        return $option;
    }

    protected function validateDryRunOption(mixed $option): array|bool|ResponseInterface|\Throwable {
        if ($option === null) {
            return $this->dryRun;
        }

        if (!is_bool($option) && !is_array($option) && !$option instanceof ResponseInterface) {
            $type = gettype($option);
            if ($type === 'object') {
                $type = $option::class;
            }

            return new \InvalidArgumentException(sprintf('The \'dry_run\' option needs to be a boolean, an instance of %s, or an array of %s instances; %s given', ResponseInterface::class, ResponseInterface::class, $type));
        } elseif (is_array($option)) {
            foreach ($option as $k => $o) {
                if ($o instanceof ResponseInterface) {
                    continue;
                }

                $type = gettype($o);
                if ($type === 'object') {
                    $type = $o::class;
                }

                return new \InvalidArgumentException(sprintf('All values in a \'dry_run\' option\' array need to be an instance of %s; %s given at index %s', ResponseInterface::class, $type, $k));
            }
        }

        if ($option === true) {
            return new Response(200);
        }

        return $option;
    }

    protected function validateHandlerOption(mixed $option): callable|\Throwable|null {
        if ($option === null) {
            return $this->handler;
        }

        if (!is_callable($option)) {
            $type = gettype($option);
            if ($type === 'object') {
                $type = $option::class;
            }

            return new \InvalidArgumentException(sprintf('The \'handler\' option needs to be a callable; %s given', $type));
        }

        return $option;
    }

    protected function validateLoggerOption(mixed $option): LoggerInterface|\Throwable|null {
        if ($option === null) {
            return $this->logger;
        }

        if (!$option instanceof LoggerInterface) {
            $type = gettype($option);
            if ($type === 'object') {
                $type = $option::class;
            }

            return new \InvalidArgumentException(sprintf('The \'logger\' option needs to be a callable or an instance of %s; %s given', LoggerInterface::class, $type));
        }

        return $option;
    }

    protected function validateMaxRetriesOption(mixed $option): int|\Throwable {
        if ($option === null) {
            return $this->maxRetries;
        }

        if (!is_integer($option)) {
            $type = gettype($option);
            if ($type === 'object') {
                $type = $option::class;
            }

            return new \InvalidArgumentException(sprintf('The \'on_retry\' option\'s callable must return an integer;  %s given', $type));
        }
        if ($option < 1) {
            return new \OutOfRangeException(sprintf('The \'on_retry\' option\'s callable must be >= 0; %s given', $option));
        }

        return $option;
    }

    protected function validateMiddlewareOption(mixed $option, ?callable $handler = null): array|\Closure|\Throwable|null {
        // if there is a provided handler ignore all middleware
        if ($handler !== null) {
            return null;
        }
        if ($option === null) {
            return $this->middleware;
        }

        if (!is_array($option) && !$option instanceof \Closure) {
            $type = gettype($option);
            if ($type === 'object') {
                $type = $option::class;
            }

            return new \InvalidArgumentException(sprintf('The \'middleware\' option needs to be an array of %s or a %s; %s given', \Closure::class, \Closure::class, $type));
        } elseif (is_array($option)) {
            foreach ($option as $k => $o) {
                if ($o instanceof \Closure) {
                    continue;
                }

                $type = gettype($o);
                if ($type === 'object') {
                    $type = $o::class;
                }

                return new \InvalidArgumentException(sprintf('All values in a \'middleware\' option array need to be a %s; %s given at index %s', \Closure::class, $type, $k));
            }
        }

        return $option;
    }

    protected function validateOnRetryOption(mixed $option): callable|\Throwable|null {
        if ($option === null) {
            return $this->onRetry;
        }

        if (!is_callable($option)) {
            $type = gettype($option);
            if ($type === 'object') {
                $type = $option::class;
            }

            return new \InvalidArgumentException(sprintf('The \'on_retry\' option needs to be a callable; %s given', $type));
        }

        return $option;
    }
}