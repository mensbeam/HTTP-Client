<?php
/**
 * @license MIT
 * Copyright 2025 Dustin Wilson, et al.
 * See LICENSE and AUTHORS files for details
 */

declare(strict_types=1);
namespace MensBeam;

use GuzzleHttp\{
    BodySummarizer,
    Client,
    Exception\RequestException,
    Handler\CurlHandler,
    Handler\MockHandler,
    HandlerStack,
    Middleware,
    Psr7\Response
};
use Psr\Http\Message\{
    RequestInterface,
    ResponseInterface,
    UriInterface
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
 * evaluating the request, response, and exception details. Below is an example
 * of how a custom retry callback can be implemented:
 *
 * This allows for flexible error-handling strategies, such as retrying specific
 * response codes or dynamically modifying retry delays.
 *
 * In addition, all of these may be overwritten by supplying a custom handler in
 * the configuration.
 */
class HTTPClient {
    /**
     * @var int Used in the retry_callable option to tell the retry handler to not
     * retry the request
     */
    public const REQUEST_STOP = 0;
    /**
     * @var int Used in the retry_callable option to tell the retry handler to retry
     * the request
     */
    public const REQUEST_RETRY = 1;
    /**
     * @var int Used in the retry_callable option to tell the retry handler to
     * continue onto its own logic
     */
    public const REQUEST_CONTINUE = 2;

    /** @var array Configuration array */
    protected array $config = [];

    /**
     * @var bool|ResponseInterface|array[ResponseInterface] Use a mock request
     * handler; if a Psr\Http\Message\ResponseInterface or an array of them is
     * provided it is treated as true and will return that response when making
     * requests; defaults to a 200 code response
     */
    protected bool|ResponseInterface|array $dryRun = false;

    /** @var ?callable Guzzle handler */
    protected $handler = null;

    /** @var LoggerInterface|null Logger instance */
    protected ?LoggerInterface $logger = null;

    /** @var array[\Closure]|\Closure|null Additional Guzzle middleware to use */
    protected array|\Closure|null $middleware = null;

    /** @var int Maximum number of retries */
    protected int $maxRetries = 10;

    /** @var array|\Closure|string|null Callback function for retry logic */
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
     * HTTPClient will also accept the remaining Guzzle Client configuration options
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
    public function request(string $method, string|UriInterface $uri, array $options = []): ResponseInterface {
        $client = $this->getGuzzleClient($options);
        if ($client instanceof \Throwable) {
            throw $client;
        }

        return $client->request($method, $uri, $options);
    }

    /**
     * Send an HTTP request.
     *
     * @param RequestInterface $request The request to send
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
     * HTTPClient will also accept the remaining Guzzle Client configuration options
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


    protected function getGuzzleClient(array &$options): Client|\Throwable {
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

            $dynamicDelay = null;
            $delayCallable = function (int $attempt) use (&$dynamicDelay): int {
                if ($dynamicDelay !== null) {
                    return $dynamicDelay;
                }

                return 1000 * 2 ** $attempt;
            };

            if ($maxRetries > 0) {
                $stack->push(Middleware::retry(
                    function (int $retries, RequestInterface $request, ?ResponseInterface $response = null, ?RequestException $exception = null) use($delayCallable, &$dynamicDelay, $logger, $maxRetries, $onRetry): bool {
                        $dynamicDelay = null;

                        if ($retries > $maxRetries) {
                            return false;
                        }

                        if ($onRetry !== null) {
                            $result = $onRetry($retries, $request, $response, $exception, $dynamicDelay);

                            if (!is_integer($result)) {
                                throw new \InvalidArgumentException(sprintf('The \'on_retry\' option\'s callable must return an integer; %s given', $result));
                            }
                            if ($result < 0 || $result > 2) {
                                throw new \OutOfRangeException(sprintf('The \'on_retry\' option\'s callable must return an integer between 0 and 2; %s given', $result));
                            }

                            if ($result === self::REQUEST_RETRY) {
                                return true;
                            }
                            if ($result === self::REQUEST_STOP) {
                                return false;
                            }
                        }

                        // Not sure if this can happen, but here it is just in case.
                        if ($response === null && $exception === null) {
                            return false; // @codeCoverageIgnore
                        }

                        $code = ($response !== null) ? $response->getStatusCode() : 0;
                        switch ($code) {
                            case 400:
                            case 404:
                            case 410:
                            return false;
                            case 429:
                            case 503:
                                // If there isn't a Retry-After header then sleep exponentially.
                                if (!$response->hasHeader('Retry-After')) {
                                    if ($logger !== null) {
                                        $logger->debug(sprintf('%s error, retrying after %s', $code, ($delayCallable($retries) / 1000) . 's'));
                                    }
                                    return true;
                                }

                                // The Retry-After header is either supposed to have an HTTP date format or a
                                // delay in seconds, but some mistakenly supply a Unix timestamp instead. This
                                // requires a bit more work...
                                $retryAfter = $response->getHeaderLine('Retry-After');
                                // If the Retry-After header is a date string have PHP parse it and get the
                                // number of seconds necessary to wait.
                                if (!is_numeric($retryAfter)) {
                                    $retryAfter = ((new \DateTimeImmutable($retryAfter))->getTimestamp() - time()) * 1000;
                                } else {
                                    // Otherwise, if it is a number that is greater than the current Unix timestamp
                                    // parse it as a Unix timestamp and subtract the current timestamp from it to
                                    // get the seconds.
                                    $retryAfter = (int)$retryAfter;
                                    $now = time();
                                    if ($retryAfter >= $now) {
                                        $retryAfter = (\DateTimeImmutable::createFromFormat('U', "$retryAfter")->getTimestamp() - $now) * 1000;
                                    }
                                }

                                if ($retryAfter > 0) {
                                    if ($logger !== null) {
                                        $logger->debug(sprintf('%s error, retrying after %s', $code, "{$retryAfter}s"));
                                    }

                                    $dynamicDelay = $retryAfter;
                                }
                            return true;
                            default:
                                if ($exception === null && $code < 400) {
                                    return false;
                                }

                                // Check for cURL errors in the exception and retry if so
                                // Not sure how to test curl errors
                                // @codeCoverageIgnoreStart
                                if ($exception !== null) {
                                    $curlErrno = $exception->getHandlerContext()['errno'] ?? null;
                                    if ($curlErrno !== null) {
                                        if ($logger !== null) {
                                            $logger->debug(sprintf('%s error (cURL error %s), retrying after %s',  $code, $curlErrno, ($delayCallable($retries) / 1000) . 's'));
                                        }

                                        return true;
                                    }
                                }
                                // @codeCoverageIgnoreEnd

                                if ($logger !== null) {
                                    $logger->debug(sprintf('%s error, retrying after %s', $code, ($delayCallable($retries) / 1000) . 's'));
                                }
                                return true;
                        }

                        return false;
                    },
                    $delayCallable
                ));
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
        return new Client($config);
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