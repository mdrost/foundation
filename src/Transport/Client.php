<?php declare(strict_types=1);

namespace ApiClients\Foundation\Transport;

use ApiClients\Foundation\Hydrator\Factory as HydratorFactory;
use ApiClients\Foundation\Hydrator\Hydrator;
use ApiClients\Foundation\Hydrator\Options as HydratorOptions;
use ApiClients\Foundation\Transport\CommandBus;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\RequestOptions;
use League\Tactician\CommandBus as Tactician;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use React\Cache\CacheInterface;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use function React\Promise\reject;
use React\Promise\RejectedPromise;
use function React\Promise\resolve;
use function WyriHaximus\React\futureFunctionPromise;

class Client
{
    const DEFAULT_OPTIONS = [
        Options::SCHEMA => 'https',
        Options::PATH => '/',
        Options::USER_AGENT => 'WyriHaximus/php-api-client',
        Options::HEADERS => [],
    ];

    /**
     * @var GuzzleClient
     */
    protected $handler;

    /**
     * @var LoopInterface
     */
    protected $loop;

    /**
     * @var array
     */
    protected $options = [];

    /**
     * @var Hydrator
     */
    protected $hydrator;

    /**
     * @var Tactician
     */
    protected $commandBus;

    /**
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @param LoopInterface $loop
     * @param GuzzleClient $handler
     * @param array $options
     */
    public function __construct(LoopInterface $loop, GuzzleClient $handler, array $options = [])
    {
        $this->loop = $loop;
        $this->handler = $handler;
        $this->options = $options + self::DEFAULT_OPTIONS;

        if (isset($this->options[Options::CACHE]) && $this->options[Options::CACHE] instanceof CacheInterface) {
            $this->cache = $this->options[Options::CACHE];
        }

        $this->hydrator = $this->determineHydrator();
        $this->commandBus = $this->hydrator->getCommandBus();
    }

    /**
     * @return Hydrator
     * @throws \Exception
     */
    protected function determineHydrator(): Hydrator
    {
        if (isset($this->options[Options::HYDRATOR]) && $this->options[Options::HYDRATOR] instanceof Hydrator) {
            return $this->options[Options::HYDRATOR];
        }

        if (!isset($this->options[Options::HYDRATOR_OPTIONS])) {
            throw new \Exception('Missing Hydrator options');
        }

        $this->ensureCommandHandlers();

        return HydratorFactory::create($this->options[Options::HYDRATOR_OPTIONS]);
    }

    protected function ensureCommandHandlers()
    {
        $mapping = [];
        if (isset($this->options[Options::HYDRATOR_OPTIONS][HydratorOptions::COMMAND_BUS])) {
            $mapping = $this->options[Options::HYDRATOR_OPTIONS][HydratorOptions::COMMAND_BUS];
        }

        $requestHandler = new CommandBus\Handler\RequestHandler($this);
        $mapping[CommandBus\Command\RequestCommand::class] = $requestHandler;
        $mapping[CommandBus\Command\SimpleRequestCommand::class] = $requestHandler;
        $mapping[CommandBus\Command\JsonEncodeCommand::class] = new CommandBus\Handler\JsonEncodeHandler($this->loop);
        $mapping[CommandBus\Command\JsonDecodeCommand::class] = new CommandBus\Handler\JsonDecodeHandler($this->loop);

        $this->options[Options::HYDRATOR_OPTIONS][HydratorOptions::COMMAND_BUS] = $mapping;
    }

    /**
     * @param UriInterface $uri
     * @return PromiseInterface
     */
    protected function checkCache(UriInterface $uri): PromiseInterface
    {
        if (!($this->cache instanceof CacheInterface)) {
            return reject();
        }

        $key = $this->determineCacheKey($uri);
        return $this->cache->get($key)->then(function ($document) {
            $document = json_decode($document, true);
            $response = new Psr7Response(
                $document['status_code'],
                $document['headers'],
                $document['body'],
                $document['protocol_version'],
                $document['reason_phrase']
            );

            return resolve($response);
        });
    }

    /**
     * @param RequestInterface $request
     * @param ResponseInterface $response
     */
    protected function storeCache(RequestInterface $request, ResponseInterface $response)
    {
        if (!($this->cache instanceof CacheInterface)) {
            return;
        }

        $document = [
            'body' => $response->getBody()->getContents(),
            'headers' => $response->getHeaders(),
            'protocol_version' => $response->getProtocolVersion(),
            'reason_phrase' => $response->getReasonPhrase(),
            'status_code' => $response->getStatusCode(),
        ];

        $key = $this->determineCacheKey($request->getUri());

        $this->cache->set($key, json_encode($document));
    }

    /**
     * @param UriInterface $uri
     * @return string
     */
    protected function determineCacheKey(UriInterface $uri): string
    {
        return $this->stripExtraSlashes(
            implode(
                '/',
                [
                    $uri->getScheme(),
                    $uri->getHost(),
                    $uri->getPort(),
                    $uri->getPath(),
                    md5($uri->getQuery()),
                ]
            )
        );
    }

    /**
     * @param string $string
     * @return string
     */
    protected function stripExtraSlashes(string $string): string
    {
        return preg_replace('#/+#', '/', $string);
    }

    /**
     * @param RequestInterface $request
     * @param bool $refresh
     * @param array $options
     * @return PromiseInterface
     */
    public function request(RequestInterface $request, array $options = [], bool $refresh = false): PromiseInterface
    {
        $promise = new RejectedPromise();

        $request = $this->applyApiSettingsToRequest($request);

        if ((!isset($options[RequestOptions::STREAM]) || !$options[RequestOptions::STREAM]) && !$refresh) {
            $promise = $this->checkCache($request->getUri());
        }

        return $promise->otherwise(function () use ($request, $options) {
            return resolve($this->handler->sendAsync(
                $request,
                $options
            ));
        })->then(function (ResponseInterface $response) use ($request, $options, $refresh) {
            if (isset($options[RequestOptions::STREAM]) && $options[RequestOptions::STREAM] === true) {
                return resolve(
                    new Response(
                        '',
                        $response
                    )
                );
            }

            $contents = $response->getBody()->getContents();

            $this->storeCache(
                $request,
                new Psr7Response(
                    $response->getStatusCode(),
                    $response->getHeaders(),
                    $contents,
                    $response->getProtocolVersion(),
                    $response->getReasonPhrase()
                )
            );

            return resolve(
                new Response(
                    $contents,
                    new Psr7Response(
                        $response->getStatusCode(),
                        $response->getHeaders(),
                        $contents,
                        $response->getProtocolVersion(),
                        $response->getReasonPhrase()
                    )
                )
            );
        });
    }

    protected function applyApiSettingsToRequest(RequestInterface $request): RequestInterface
    {
        return new Psr7Request(
            $request->getMethod(),
            $this->getBaseURL() . $request->getUri(),
            $this->getHeaders() + $request->getHeaders(),
            $request->getBody(),
            $request->getProtocolVersion()
        );
    }

    /**
     * @return array
     */
    public function getHeaders(): array
    {
        $headers = [
            'User-Agent' => $this->options[Options::USER_AGENT],
        ];
        $headers += $this->options[Options::HEADERS];
        return $headers;
    }

    /**
     * @return Hydrator
     */
    public function getHydrator(): Hydrator
    {
        return $this->hydrator;
    }

    /**
     * @return LoopInterface
     */
    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }

    /**
     * @return string
     */
    public function getBaseURL(): string
    {
        return $this->options[Options::SCHEMA] . '://' . $this->options[Options::HOST] . $this->options[Options::PATH];
    }
}
