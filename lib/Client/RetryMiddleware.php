<?php
/**
 * @license MIT
 * Copyright 2025 Dustin Wilson, et al.
 * See LICENSE and AUTHORS files for details
 */

declare(strict_types=1);
namespace MensBeam\HTTP\Client;

use GuzzleHTTP\{
    Exception\RequestException,
    Promise as P,
    Promise\PromiseInterface
};
use Psr\Http\Message\{
    RequestInterface,
    ResponseInterface
};
use Psr\Log\LoggerInterface;


/**
 * Middleware that handles automatic request retries based on response codes,
 * exceptions, or custom retry logic.
 */
class RetryMiddleware {
    use RetryAware;

    /** @var int|null Dynamic delay time for retries (in milliseconds) */
    protected ?int $dynamicDelay = null;
    /** @var LoggerInterface|null Logger instance for debugging and tracking retries */
    protected ?LoggerInterface $logger = null;
    /** @var int Maximum number of retries allowed */
    protected int $maxRetries = 10;
    /** @var callable Next handler in the middleware stack */
    protected $nextHandler;
    /** @var callable|null Callback function for custom retry handling */
    protected $retryCallback = null;



    /**
     * Constructor for the retry middleware.
     *
     * @param callable $nextHandler  The next handler in the middleware stack.
     * @param callable|null $retryCallback Callback function that determines retry behavior.
     * @param LoggerInterface|null $logger Logger instance for debug output.
     * @param int $maxRetries Maximum number of retry attempts.
     */
    public function __construct(callable $nextHandler, ?callable $retryCallback, ?LoggerInterface $logger, int $maxRetries = 10) {
        $this->nextHandler = $nextHandler;
        $this->retryCallback = $retryCallback;
        $this->logger = $logger;
        $this->maxRetries = $maxRetries;
    }



    /**
     * Handles the middleware invocation, passing the request through the stack.
     *
     * @param RequestInterface $request The HTTP request.
     * @param array $options Request options.
     * @return PromiseInterface The promise resolving the HTTP response.
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface {
        if (!isset($options['retries'])) {
            $options['retries'] = 0;
        }

        $fn = $this->nextHandler;

        return $fn($request, $options)
            ->then(
                $this->onFulfilled($request, $options),
                $this->onRejected($request, $options)
            );
    }



    /**
     * Handles successful responses and determines if a retry is necessary.
     *
     * @param RequestInterface $request The original HTTP request.
     * @param array $options Request options.
     * @return callable Function that processes the response.
     */
    protected function onFulfilled(RequestInterface $request, array $options): callable {
        return function ($value) use ($request, $options) {
            $decision = $this->decide(
                $options['retries'],
                $request,
                $value,
                null
            );

            if ($decision === false) {
                return $value; // No retry
            }

            return $this->retryRequest(($decision instanceof RequestInterface) ? $decision : $request, $options);
        };
    }

    /**
     * Handles failed responses and determines if a retry is necessary.
     *
     * @param RequestInterface $request The original HTTP request.
     * @param array $options Request options.
     * @return callable Function that processes the rejection.
     */
    protected function onRejected(RequestInterface $request, array $options): callable {
        return function ($reason) use ($request, $options) {
            $decision = $this->decide(
                $options['retries'],
                $request,
                null,
                $reason
            );

            if ($decision === false) {
                return P\Create::rejectionFor($reason); // No retry
            }

            return $this->retryRequest(($decision instanceof RequestInterface) ? $decision : $request, $options);
        };
    }

    /**
     * Determines whether to retry a request.
     *
     * @param int $retryCount The current retry attempt count.
     * @param RequestInterface $request The original HTTP request.
     * @param ResponseInterface|null $response The HTTP response (if available).
     * @param RequestException|null $exception The exception (if an error occurred).
     * @return bool|RequestInterface `false` to stop retrying, `true` to retry, or a modified `RequestInterface` to retry with changes.
     */
    protected function decide(int $retryCount, RequestInterface $request, ?ResponseInterface $response = null, ?RequestException $exception = null): bool|RequestInterface {
        $this->dynamicDelay = null;

        if ($retryCount > $this->maxRetries) {
            return false;
        }

        if ($this->retryCallback !== null) {
            $result = ($this->retryCallback)($retryCount, $request, $response, $exception, $this->dynamicDelay);

            if (!is_integer($result)) {
                throw new \InvalidArgumentException(sprintf('The \'on_retry\' option\'s callable must return an integer; %s given', $result));
            }
            if ($result < 0 || $result > 2) {
                throw new \OutOfRangeException(sprintf('The \'on_retry\' option\'s callable must return an integer between 0 and 2; %s given', $result));
            }

            if ($result === self::REQUEST_RETRY) {
                // Return the request in case it was modified by the callback via a reference
                return $request;
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
                    if ($this->logger !== null) {
                        $this->logger->debug(sprintf('%s error, retrying after %s', $code, ($this->delay($retryCount) / 1000) . 's'));
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
                    if ($this->logger !== null) {
                        $this->logger->debug(sprintf('%s error, retrying after %s', $code, "{$retryAfter}s"));
                    }

                    $this->dynamicDelay = $retryAfter;
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
                        if ($this->logger !== null) {
                            $this->logger->debug(sprintf('%s error (cURL error %s), retrying after %s',  $code, $curlErrno, ($this->delay($retryCount) / 1000) . 's'));
                        }

                        return true;
                    }
                }
                // @codeCoverageIgnoreEnd

                if ($this->logger !== null) {
                    $this->logger->debug(sprintf('%s error, retrying after %s', $code, ($this->delay($retryCount) / 1000) . 's'));
                }
                return true;
        }

        return false;
    }

    /**
     * Calculates the delay time for retries.
     *
     * @param int $retryCount The current retry attempt count.
     * @return int The delay time in milliseconds.
     */
    protected function delay(int $retryCount): int {
        if ($this->dynamicDelay !== null) {
            return $this->dynamicDelay;
        }

        return 1000 * 2 ** $retryCount;
    }

    /**
     * Initiates a retry attempt.
     *
     * @param RequestInterface $request The HTTP request.
     * @param array $options Request options.
     * @return PromiseInterface The promise resolving the HTTP response.
     */
    protected function retryRequest(RequestInterface $request, array $options): PromiseInterface {
        $options['delay'] = $this->delay(++$options['retries']);
        return $this($request, $options);
    }
}