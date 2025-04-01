<?php
/**
 * @license MIT
 * Copyright 2025 Dustin Wilson, et al.
 * See LICENSE and AUTHORS files for details
 */

declare(strict_types=1);
namespace MensBeam\HTTP;

use GuzzleHttp\{
    BodySummarizer,
    Client as GuzzleClient,
    Exception\RequestException,
    Exception\ConnectException,
    Handler\CurlHandler,
    Handler\MockHandler,
    HandlerStack,
    Middleware,
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
 * The retry behavior can be customized using the `retry_callback` configuration
 * option. This callback allows fine-grained control over retry logic by
 * evaluating the request, response, and exception details. This allows for
 * flexible error-handling strategies, such as retrying specific response codes
 * or dynamically modifying retry delays.
 *
 * In addition, all of these may be overwritten by supplying a custom handler in
 * the configuration.
 */
class Client implements ClientInterface {
    /**
     * @var int Used in retry callables to tell the retry handler to not retry the
     * request and return the response as is
     */
    public const REQUEST_STOP = 0;
    /**
     * @var int Used in retry callables to tell the retry handler to not retry the
     * request but throw if an exception occurred; behaves identically to
     * REQUEST_STOP if there isn't an exception
     */
    public const REQUEST_FAIL = 1;
    /**
     * @var int Used in retry callables to tell the retry handler to retry the
     * request
     */
    public const REQUEST_RETRY = 2;
    /**
     * @var int Used in retry callables to tell the retry handler to continue onto
     * its own logic
     */
    public const REQUEST_CONTINUE = 3;

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
    protected array|\Closure|string|null $retryCallback = null;



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
     *     - retry_callback: ?callable Callback for retry decision logic
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
        $this->logger = $logger;
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

        $retryCallback = $this->validateRetryCallbackOption($config['retry_callback'] ?? null);
        if ($retryCallback instanceof \Throwable) {
            throw $retryCallback;
        }
        $this->retryCallback = $retryCallback;
        unset($config['retry_callback']);

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
     *     - retry_callback: ?callable Callback for retry decision logic for this request
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
        return $this->sendRequestWithRetries($method, $uri, $options);
    }

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
     *     - retry_callback: ?callable Callback for retry decision logic for this request
     *
     * HTTP-Client will also accept the remaining Guzzle Client configuration options
     * in the request options array to be applied per request
     *
     * @throws \InvalidArgumentException
     * @throws \OutOfRangeException
     */
    public function send(RequestInterface $request, array $options = []): ResponseInterface {
        if (!isset($options['body']) && !isset($options['form_params']) && !isset($options['json']) && !isset($options['multipart'])) {
            $options['body'] = $request->getBody();
        }

        return $this->sendRequestWithRetries($request->getMethod(), $request->getUri(), $options);
    }

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

        $maxRetries = $this->validateMaxRetriesOption($options['max_retries'] ?? null);
        if ($maxRetries instanceof \Throwable) {
            return $maxRetries;
        }
        $options['max_retries'] = $maxRetries ?? 10;

        $middleware = $this->validateMiddlewareOption($options['middleware'] ?? null, $handler);
        if ($middleware instanceof \Throwable) {
            return $middleware;
        }
        unset($options['middleware']);

        $retryCallback = $this->validateRetryCallbackOption($options['retry_callback'] ?? null);
        if ($retryCallback instanceof \Throwable) {
            return $retryCallback;
        }

        if ($handler === null) {
            // Forces use of cURL so cURL's descriptive errors may be used when requests
            // fail
            // If dry run then use a mock handler instead
            $stack = HandlerStack::create(($dryRun) ? new MockHandler((is_array($dryRun)) ? $dryRun : [ $dryRun ]) : new CurlHandler());

            // This is required because Guzzle has a really absurdly low character count
            // (something like ~200) limit on messages, so naturally because of
            // overengineering the most asinine method of changing it exists.
            $stack->push(Middleware::httpErrors(new BodySummarizer(truncateAt: 32767)));

            if (($middleware ?? null) !== null) {
                $append = (!is_array($middleware)) ? [ $middleware ] : $middleware;
                foreach ($append as $m) {
                    $stack->push($m);
                }
            }

            $handler = $stack;
        }

        $config['handler'] = $handler;

        // Every request creates a new client
        return new GuzzleClient($config);
    }

    protected function sendRequestWithRetries(string $method, string|UriInterface $uri = '', array $options = []): ResponseInterface {
        $client = $this->getGuzzleClient($options);
        if ($client instanceof \Throwable) {
            throw $client;
        }

        // So the callback itself cannot be modified by the callback
        $retryCallback = $options['retry_callback'] ?? null;
        unset($options['retry_callback']);

        $delay = $retryAttempt = 0;
        while (true) {
            // So the callback itself cannot be modified by the callback
            if (($options['retry_callback'] ?? null) !== $retryCallback) {
                $options['retry_callback'] = $retryCallback;
            }

            try {
                $o = $options;
                // Don't want the remaining custom options exposed to Guzzle
                unset($o['logger']);
                unset($o['max_retries']);
                $response = $client->request($method, $uri, $o);
                if ($options['max_retries'] > 0 && ++$retryAttempt <= $options['max_retries']) {
                    $delay = 1000 * 2 ** ($retryAttempt - 1);
                    if ($this->retry($retryAttempt, $method, $uri, $options, $delay, $response, null, $retryCallback) === self::REQUEST_RETRY) {
                        usleep($delay * 1000);
                        continue;
                    }
                }
            } catch (ConnectException|RequestException $exception) {
                if ($options['max_retries'] === 0) {
                    throw $exception;
                }

                $response = ($exception instanceof RequestException && $exception->hasResponse()) ? $exception->getResponse() : null;

                if (++$retryAttempt <= $options['max_retries']) {
                    $delay = 1000 * 2 ** ($retryAttempt - 1);

                    switch ($this->retry($retryAttempt, $method, $uri, $options, $delay, $response, $exception, $retryCallback)) {
                        case self::REQUEST_RETRY:
                            usleep($delay * 1000);
                        continue 2;
                        case self::REQUEST_STOP:
                        return $response;
                    }
                }

                throw $exception;
            }

            break;
        }

        return $response;
    }

    protected function retry(int &$retryAttempt, string &$method, string|UriInterface &$uri, array &$options, int &$delay, ?ResponseInterface $response = null, ConnectException|RequestException|null $exception = null, ?callable $retryCallback = null): int {
        if ($retryCallback !== null) {
            $result = ($retryCallback)($retryAttempt, $method, $uri, $options, $delay, $response, $exception);

            if (!is_integer($result)) {
                throw new \InvalidArgumentException(sprintf('The \'retry_callback\' option\'s callable must return an integer; %s given', $result));
            }

            switch ($result) {
                case self::REQUEST_CONTINUE:
                break;
                case self::REQUEST_FAIL:
                    if ($exception !== null) {
                        throw $exception;
                    }
                case self::REQUEST_RETRY:
                case self::REQUEST_STOP:
                return $result;
                default: throw new \OutOfRangeException(sprintf('The \'retry_callback\' option\'s callable must return an integer between 0 and 3; %s given', $result));
            }
        }

        // Not sure if this can happen, but here it is just in case.
        if ($response === null && $exception === null) {
            return self::REQUEST_FAIL; // @codeCoverageIgnore
        }

        $logger = $options['logger'] ?? null;

        $code = $response?->getStatusCode() ?? 0;
        switch ($code) {
            case 400:
            case 404:
            case 410:
            return self::REQUEST_FAIL;
            case 429:
            case 503:
                // If there isn't a Retry-After header then sleep exponentially.
                if (!$response->hasHeader('Retry-After')) {
                    $logger?->debug(sprintf('%s error, retrying after %s', $code, ($delay / 1000) . 's'));
                    return self::REQUEST_RETRY;
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
                    $logger?->debug(sprintf('%s error, retrying after %s', $code, "{$retryAfter}s"));
                    $delay = $retryAfter;
                }
            return self::REQUEST_RETRY;
            default:
                if ($exception === null && $code < 400) {
                    return self::REQUEST_FAIL;
                }

                // Check for cURL errors in the exception and retry if so
                // Not sure how to test curl errors
                // @codeCoverageIgnoreStart
                if ($exception !== null) {
                    $curlErrno = $exception->getHandlerContext()['errno'] ?? null;
                    if ($curlErrno !== null) {
                        $logger?->debug(sprintf('%s error (cURL error %s), retrying after %s',  $code, $curlErrno, ($delay / 1000) . 's'));
                        return self::REQUEST_RETRY;
                    }
                }
                // @codeCoverageIgnoreEnd

                $logger?->debug(sprintf('%s error, retrying after %s', $code, ($delay / 1000) . 's'));
                return self::REQUEST_RETRY;
        }
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

            return new \InvalidArgumentException(sprintf('The \'max_retries\' option\'s callable must return an integer;  %s given', $type));
        }
        if ($option < 0) {
            return new \OutOfRangeException(sprintf('The \'max_retries\' option\'s callable must be >= 0; %s given', $option));
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

    protected function validateRetryCallbackOption(mixed $option): callable|\Throwable|null {
        if ($option === null) {
            return $this->retryCallback;
        }

        if (!is_callable($option)) {
            $type = gettype($option);
            if ($type === 'object') {
                $type = $option::class;
            }

            return new \InvalidArgumentException(sprintf('The \'retry_callback\' option needs to be a callable; %s given', $type));
        }

        return $option;
    }
}