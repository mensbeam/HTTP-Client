<?php
/**
 * @license MIT
 * Copyright 2025 Dustin Wilson, et al.
 * See LICENSE and AUTHORS files for details
 */

declare(strict_types=1);
namespace MensBeam\HTTP\Test;
use GuzzleHttp\{
    Exception\ClientException,
    Exception\RequestException,
    Handler\MockHandler,
    Middleware,
    Psr7\Request,
    Psr7\Response,
    Psr7\Uri
};
use MensBeam\HTTP\Client,
    Phake,
    Psr\Log\LoggerInterface;
use PHPUnit\Framework\{
    Attributes\CoversClass,
    Attributes\DataProvider,
    TestCase
};
use Psr\Http\Message\{
    RequestInterface,
    ResponseInterface,
    UriInterface
};


#[CoversClass('MensBeam\HTTP\Client')]
#[CoversClass('MensBeam\HTTP\Client\RetryAware')]
#[CoversClass('MensBeam\HTTP\Client\RetryMiddleware')]
class TestClient extends TestCase {
    public function testConstructor(): void {
        $logger = Phake::mock(LoggerInterface::class);
        $retryCallback = function (
            int $retries,
            RequestInterface $request,
            ?ResponseInterface $response = null,
            ?RequestException $exception = null,
            ?int &$dynamicDelay = null
        ): int {
            return Client::REQUEST_CONTINUE;
        };

        $client = new Client([
            'dry_run' => true,
            'handler' => new MockHandler(),
            'logger' => $logger,
            'max_retries' => 42,
            'retry_callback' => $retryCallback
        ]);
        $uri = Phake::mock(UriInterface::class);
        Phake::when($uri)->__toString()->thenReturn('https://ook.com');

        $response = Phake::mock(ResponseInterface::class);
        Phake::when($response)->getStatusCode()->thenReturn(200);

        $mockClient = Phake::mock(Client::class);
        Phake::when($mockClient)->request('GET', $uri)->thenReturn($response);

        $response = $mockClient->request('GET', $uri);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testRequestOptions(): void {
        $logger = Phake::mock(LoggerInterface::class);

        $retryCallback = function (
            int $retries,
            RequestInterface $request,
            ?ResponseInterface $response = null,
            ?RequestException $exception = null,
            ?int &$dynamicDelay = null
        ): int {
            return Client::REQUEST_CONTINUE;
        };

        $options = [
            'base_uri' => 'https://ook.com',
            'dry_run' => true,
            'logger' => $logger,
            'max_retries' => 42,
            'retry_callback' => $retryCallback
        ];

        $client = new Client();
        $response = $client->request('GET', '/eek', $options);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $request = new Request('GET', '/eek');
        $response = $client->send($request, $options);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testRequestMiddleware(): void {
        $count = 0;
        $client = new Client();
        $response = $client->request('GET', 'https://ook.com', [
            'dry_run' => [
                new Response(418),
                new Response(200)
            ],
            'middleware' => [
                Middleware::tap(function (RequestInterface $request) use (&$count) {
                    $count++;
                })
            ]
        ]);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(2, $count);
    }

    public function testRequestRetry_default(): void {
        // Fake logger for code coverage purposes
        $logger = Phake::mock(LoggerInterface::class);
        $client = new Client();
        $response = $client->request('GET', 'https://ook.com', [
            'dry_run' => [
                new Response(418),
                new Response(200)
            ],
            'logger' => $logger
        ]);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    #[DataProvider('provideRequestRetry_failures')]
    public function testRequestRetry_failures(string $throwableClassName, \Closure $closure): void {
        $this->expectException($throwableClassName);
        $closure();
    }

    public static function provideRequestRetry_failures(): \Generator {
        $array = [
            [
                ClientException::class,
                function () {
                    $client = new Client();
                    $client->request('GET', 'https://ook.com', [
                        'dry_run' => [ new Response(400) ]
                    ]);
                }
            ],
            [
                ClientException::class,
                function () {
                    $client = new Client();
                    $client->request('GET', 'https://ook.com', [
                        'dry_run' => [ new Response(404) ]
                    ]);
                }
            ],
            [
                ClientException::class,
                function () {
                    $client = new Client();
                    $client->request('GET', 'https://ook.com', [
                        'dry_run' => [ new Response(410) ]
                    ]);
                }
            ]
        ];

        foreach ($array as $i) {
            yield $i;
        }
    }

    public function testRequestRetry_maxRetries(): void {
        $this->expectException(ClientException::class);

        $client = new Client();
        $client->request('GET', 'https://ook.com', [
            'dry_run' => [
                new Response(418),
                new Response(418),
                new Response(418),
                new Response(418)
            ],
            'max_retries' => 2
        ]);
    }

    public function testRequestRetry_retryCallbackFail(): void {
        $this->expectException(ClientException::class);

        $retryCallback = function (
            int $retries,
            RequestInterface $request,
            ?ResponseInterface $response = null,
            ?RequestException $exception = null,
            ?int &$dynamicDelay = null
        ): int {
            if ($response->getStatusCode() === 418) {
                return Client::REQUEST_STOP;
            }

            return Client::REQUEST_CONTINUE;
        };

        $client = new Client();
        $client->request('GET', 'https://ook.com', [
            'dry_run' => [
                new Response(418)
            ],
            'retry_callback' => $retryCallback
        ]);
    }

    public function testRequestRetry_retryCallbackRetry(): void {
        $retryURI = null;
        $retryCallback = function (
            int $retries,
            RequestInterface &$request,
            ?ResponseInterface $response = null,
            ?RequestException $exception = null,
            ?int $dynamicDelay = null
        ) use (&$retryURI): int {
            $code = $response->getStatusCode();
            if ($code === 400) {
                $request = $request->withUri(new Uri('https://eek.com'));
                return Client::REQUEST_RETRY;
            }
            if ($code === 200) {
                $retryURI = (string)$request->getUri();
            }

            return Client::REQUEST_CONTINUE;
        };

        $client = new Client();
        $response = $client->request('GET', 'https://ook.com', [
            'dry_run' => [
                new Response(400),
                new Response(200)
            ],
            'retry_callback' => $retryCallback
        ]);

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertSame('https://eek.com', $retryURI);
    }

    public function testRequestRetry_retryAfter(): void {
        // Fake logger for code coverage purposes
        $logger = Phake::mock(LoggerInterface::class);
        $client = new Client();

        // No Retry-After
        $startTime = microtime(true);
        $response = $client->request('GET', 'https://ook.com', [
            'dry_run' => [
                new Response(429),
                new Response(200)
            ],
            'logger' => $logger
        ]);

        $this->assertLessThan(3, microtime(true) - $startTime);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        // RFC2616 date Retry-After
        $startTime = microtime(true);
        $response = $client->request('GET', 'https://ook.com', [
            'dry_run' => [
                new Response(429, [
                    'Retry-After' => (new \DateTimeImmutable())->modify('+2 second')->format('D, d M Y H:i:s T')
                ]),
                new Response(200)
            ],
            'logger' => $logger
        ]);

        $this->assertGreaterThanOrEqual(2, microtime(true) - $startTime);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        // Unix timestamp Retry-After
        $startTime = microtime(true);
        $response = $client->request('GET', 'https://ook.com', [
            'dry_run' => [
                new Response(429, [
                    'Retry-After' => (new \DateTimeImmutable())->modify('+2 second')->format('U')
                ]),
                new Response(200)
            ],
            'logger' => $logger
        ]);

        $this->assertGreaterThanOrEqual(2, microtime(true) - $startTime);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    #[DataProvider('provideFatalErrors')]
    public function testFatalErrors(string $throwableClassName, \Closure $closure): void {
        $this->expectException($throwableClassName);
        $closure();
    }

    public static function provideFatalErrors(): \Generator {
        $array = [
            // Invalid dry_run type, scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    new Client([ 'dry_run' => 'ook' ]);
                }
            ],
            // Invalid dry_run type, object
            [
                \InvalidArgumentException::class,
                function (): void {
                    new Client([ 'dry_run' => new \stdClass() ]);
                }
            ],
            // Invalid dry_run type (array), scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    new Client([ 'dry_run' => [ 'ook' ] ]);
                }
            ],
            // Invalid dry_run type (array), object
            [
                \InvalidArgumentException::class,
                function (): void {
                    new Client([ 'dry_run' => [ new \stdClass ] ]);
                }
            ],
            // Invalid handler type, scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    new Client([ 'handler' => 'ook' ]);
                }
            ],
            // Invalid handler type, object
            [
                \InvalidArgumentException::class,
                function (): void {
                    new Client([ 'handler' => new \stdClass() ]);
                }
            ],
            // Invalid dry_run type (array), array
            [
                \InvalidArgumentException::class,
                function (): void {
                    new Client([ 'dry_run' => [ [] ] ]);
                }
            ],
            // Invalid logger type, scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    new Client([ 'logger' => 'ook' ]);
                }
            ],
            // Invalid logger type, object
            [
                \InvalidArgumentException::class,
                function (): void {
                    new Client([ 'logger' => new \stdClass() ]);
                }
            ],
            // Invalid max_retries type, scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    new Client([ 'max_retries' => 'ook' ]);
                }
            ],
            // Invalid max_retries type, object
            [
                \InvalidArgumentException::class,
                function (): void {
                    new Client([ 'max_retries' => new \stdClass() ]);
                }
            ],
            // Invalid max_retries value
            [
                \OutOfRangeException::class,
                function (): void {
                    new Client([ 'max_retries' => -1 ]);
                }
            ],

            // Invalid middleware type, scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    new Client([ 'middleware' => 'ook' ]);
                }
            ],
            // Invalid middleware type, object
            [
                \InvalidArgumentException::class,
                function (): void {
                    new Client([ 'middleware' => new \stdClass() ]);
                }
            ],
            // Invalid middleware type (array), scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    new Client([ 'middleware' => [ 'ook' ] ]);
                }
            ],
            // Invalid middleware type (array), object
            [
                \InvalidArgumentException::class,
                function (): void {
                    new Client([ 'middleware' => [ new \stdClass ] ]);
                }
            ],
            // Invalid retry_callback type, scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    new Client([ 'retry_callback' => 'ook' ]);
                }
            ],
            // Invalid retry_callback type, object
            [
                \InvalidArgumentException::class,
                function (): void {
                    new Client([ 'retry_callback' => new \stdClass() ]);
                }
            ],
            // Invalid base_uri type, scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'base_uri' => 42 ]);
                }
            ],
            // Invalid base_uri type, object
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'base_uri' => new \stdClass ]);
                }
            ],
            // Invalid dry_run type, scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'dry_run' => 'ook' ]);
                }
            ],
            // Invalid dry_run type, object
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'dry_run' => new \stdClass ]);
                }
            ],
            // Invalid dry_run type (array), scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'dry_run' => [ 'ook' ] ]);
                }
            ],
            // Invalid dry_run type (array), object
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'dry_run' => [ new \stdClass() ] ]);
                }
            ],
            // Invalid dry_run type (array), array
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'dry_run' => [ [] ] ]);
                }
            ],
            // Invalid handler type, scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'handler' => 'ook' ]);
                }
            ],
            // Invalid handler type, object
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'handler' => new \stdClass ]);
                }
            ],
            // Invalid logger type, scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'logger' => 'ook' ]);
                }
            ],
            // Invalid logger type, object
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'logger' => new \stdClass ]);
                }
            ],
            // Invalid max_retries type, scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'max_retries' => 'ook' ]);
                }
            ],
            // Invalid max_retries type, object
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'max_retries' => new \stdClass ]);
                }
            ],
            // Invalid max_retries value
            [
                \OutOfRangeException::class,
                function (): void {
                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'max_retries' => -1 ]);
                }
            ],
            // Invalid middleware type, scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'middleware' => 'ook' ]);
                }
            ],
            // Invalid middleware type, object
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'middleware' => new \stdClass ]);
                }
            ],
            // Invalid middleware type (array), scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'middleware' => [ 'ook' ] ]);
                }
            ],
            // Invalid middleware type (array), object
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'middleware' => [ new \stdClass() ] ]);
                }
            ],
            // Invalid middleware type (array), array
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'middleware' => [ [] ] ]);
                }
            ],
            // Invalid retry_callback type, scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'retry_callback' => 'ook' ]);
                }
            ],
            // Invalid retry_callback type, object
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'retry_callback' => new \stdClass ]);
                }
            ],
            // Invalid retry_callback return value, string
            [
                \InvalidArgumentException::class,
                function (): void {
                    $retryCallback = function (
                        int $retries,
                        RequestInterface $request,
                        ?ResponseInterface $response = null,
                        ?RequestException $exception = null,
                        ?int &$dynamicDelay = null
                    ) {
                        return 'ook';
                    };

                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'retry_callback' => $retryCallback ]);
                }
            ],
            // Invalid retry_callback return value, string
            [
                \OutOfRangeException::class,
                function (): void {
                    $retryCallback = function (
                        int $retries,
                        RequestInterface $request,
                        ?ResponseInterface $response = null,
                        ?RequestException $exception = null,
                        ?int &$dynamicDelay = null
                    ): int {
                        return 42;
                    };

                    $t = new Client();
                    $t->request('GET', 'https://ook.com', [ 'retry_callback' => $retryCallback ]);
                }
            ],
            // Invalid base_uri type, scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $r = new Request('GET', 'https://ook.com');
                    $t->send($r, [ 'base_uri' => 42 ]);
                }
            ],
            // Invalid base_uri type, object
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $r = new Request('GET', 'https://ook.com');
                    $t->send($r, [ 'base_uri' => new \stdClass ]);
                }
            ],
            // Invalid dry_run type, scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $r = new Request('GET', 'https://ook.com');
                    $t->send($r, [ 'dry_run' => 'ook' ]);
                }
            ],
            // Invalid dry_run type, object
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $r = new Request('GET', 'https://ook.com');
                    $t->send($r, [ 'dry_run' => new \stdClass ]);
                }
            ],
            // Invalid dry_run type (array), scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $r = new Request('GET', 'https://ook.com');
                    $t->send($r, [ 'dry_run' => [ 'ook' ] ]);
                }
            ],
            // Invalid dry_run type (array), object
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $r = new Request('GET', 'https://ook.com');
                    $t->send($r, [ 'dry_run' => [ new \stdClass() ] ]);
                }
            ],
            // Invalid dry_run type (array), array
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $r = new Request('GET', 'https://ook.com');
                    $t->send($r, [ 'dry_run' => [ [] ] ]);
                }
            ],
            // Invalid handler type, scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $r = new Request('GET', 'https://ook.com');
                    $t->send($r, [ 'handler' => 'ook' ]);
                }
            ],
            // Invalid handler type, object
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $r = new Request('GET', 'https://ook.com');
                    $t->send($r, [ 'handler' => new \stdClass ]);
                }
            ],
            // Invalid logger type, scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $r = new Request('GET', 'https://ook.com');
                    $t->send($r, [ 'logger' => 'ook' ]);
                }
            ],
            // Invalid logger type, object
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $r = new Request('GET', 'https://ook.com');
                    $t->send($r, [ 'logger' => new \stdClass ]);
                }
            ],
            // Invalid max_retries type, scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $r = new Request('GET', 'https://ook.com');
                    $t->send($r, [ 'max_retries' => 'ook' ]);
                }
            ],
            // Invalid max_retries type, object
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $r = new Request('GET', 'https://ook.com');
                    $t->send($r, [ 'max_retries' => new \stdClass ]);
                }
            ],
            // Invalid max_retries value
            [
                \OutOfRangeException::class,
                function (): void {
                    $t = new Client();
                    $r = new Request('GET', 'https://ook.com');
                    $t->send($r, [ 'max_retries' => -1 ]);
                }
            ],
            // Invalid middleware type, scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $r = new Request('GET', 'https://ook.com');
                    $t->send($r, [ 'middleware' => 'ook' ]);
                }
            ],
            // Invalid middleware type, object
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $r = new Request('GET', 'https://ook.com');
                    $t->send($r, [ 'middleware' => new \stdClass ]);
                }
            ],
            // Invalid middleware type (array), scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $r = new Request('GET', 'https://ook.com');
                    $t->send($r, [ 'middleware' => [ 'ook' ] ]);
                }
            ],
            // Invalid middleware type (array), object
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $r = new Request('GET', 'https://ook.com');
                    $t->send($r, [ 'middleware' => [ new \stdClass() ] ]);
                }
            ],
            // Invalid middleware type (array), array
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $r = new Request('GET', 'https://ook.com');
                    $t->send($r, [ 'middleware' => [ [] ] ]);
                }
            ],
            // Invalid retry_callback type, scalar
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $r = new Request('GET', 'https://ook.com');
                    $t->send($r, [ 'retry_callback' => 'ook' ]);
                }
            ],
            // Invalid retry_callback type, object
            [
                \InvalidArgumentException::class,
                function (): void {
                    $t = new Client();
                    $r = new Request('GET', 'https://ook.com');
                    $t->send($r, [ 'retry_callback' => new \stdClass ]);
                }
            ]
        ];

        foreach ($array as $i) {
            yield $i;
        }
    }
}