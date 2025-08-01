<?php

/* @noinspection PhpRedundantCatchClauseInspection */

declare(strict_types=1);

namespace Namoshek\Redis\Sentinel\Connections;

use Closure;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Namoshek\Redis\Sentinel\Exceptions\RetryRedisException;
use Namoshek\Redis\Sentinel\Services\RetryContext;
use Redis;
use RedisException;
use Throwable;

/**
 * The connection to Redis after connecting through a Sentinel using the PhpRedis extension.
 */
class PhpRedisSentinelConnection extends PhpRedisConnection
{
    /**
     * Create a new PhpRedis connection.
     *
     * @param  \Redis  $client
     */
    public function __construct(
        $client,
        ?callable $connector,
        array $config,
        protected RetryContext $retryContext,
    ) {
        parent::__construct($client, $connector, $config);
    }

    /**
     * {@inheritdoc}
     *
     * @throws RetryRedisException
     */
    public function scan($cursor, $options = []): mixed
    {
        return $this->retryOnFailure(fn () => parent::scan($cursor, $options));
    }

    /**
     * {@inheritdoc}
     *
     * @throws RetryRedisException
     */
    public function zscan($key, $cursor, $options = []): mixed
    {
        return $this->retryOnFailure(fn () => parent::zscan($key, $cursor, $options));
    }

    /**
     * {@inheritdoc}
     *
     * @throws RetryRedisException
     */
    public function hscan($key, $cursor, $options = []): mixed
    {
        return $this->retryOnFailure(fn () => parent::hscan($key, $cursor, $options));
    }

    /**
     * {@inheritdoc}
     *
     * @throws RetryRedisException
     */
    public function sscan($key, $cursor, $options = []): mixed
    {
        return $this->retryOnFailure(fn () => parent::sscan($key, $cursor, $options));
    }

    /**
     * {@inheritdoc}
     *
     * @throws RetryRedisException
     */
    public function pipeline(
        ?callable $callback = null,
        ?int $retryAttempts = null,
    ): Redis|array {
        return $this->retryOnFailure(
            fn () => parent::pipeline($callback),
            $retryAttempts,
        );
    }

    /**
     * {@inheritdoc}
     *
     * @throws RetryRedisException
     */
    public function transaction(
        ?callable $callback = null,
        ?int $retryAttempts = null,
    ): Redis|array {
        return $this->retryOnFailure(
            fn () => parent::transaction($callback),
            $retryAttempts,
        );
    }

    /**
     * {@inheritdoc}
     *
     * @throws RetryRedisException
     */
    public function evalsha($script, $numkeys, ...$arguments): mixed
    {
        return $this->retryOnFailure(fn () => parent::evalsha($script, $numkeys, ...$arguments));
    }

    /**
     * {@inheritdoc}
     *
     * @throws RetryRedisException
     */
    public function subscribe($channels, Closure $callback): void
    {
        $this->retryOnFailure(fn () => parent::subscribe($channels, $callback));
    }

    /**
     * {@inheritdoc}
     *
     * @throws RetryRedisException
     */
    public function psubscribe($channels, Closure $callback): void
    {
        $this->retryOnFailure(fn () => parent::psubscribe($channels, $callback));
    }

    /**
     * {@inheritdoc}
     *
     * @throws RetryRedisException
     */
    public function flushdb(): void
    {
        $this->retryOnFailure(fn () => parent::flushdb());
    }

    /**
     * {@inheritdoc}
     *
     * @throws RetryRedisException
     */
    public function command($method, array $parameters = []): mixed
    {
        return $this->retryOnFailure(fn () => parent::command($method, $parameters));
    }

    /**
     * {@inheritdoc}
     *
     * @throws RetryRedisException
     */
    public function __call($method, $parameters): mixed
    {
        return $this->retryOnFailure(fn () => parent::__call(strtolower($method), $parameters));
    }

    /**
     * Attempt to retry the provided operation when the client fails to connect
     * to a Redis server.
     *
     * @param  callable  $callback  The operation to execute.
     * @param  ?int  $retryAttempts  The number of times the retry is performed.
     * @param  ?int  $retryDelay  The time in milliseconds to wait before retrying again.
     * @return mixed The result of the first successful attempt.
     *
     * @throws RetryRedisException|RedisException
     */
    protected function retryOnFailure(
        callable $callback,
        ?int $retryAttempts = null,
        ?int $retryDelay = null,
    ): mixed {
        return $this->retryContext->retryOnFailure(
            $callback,
            $retryAttempts,
            $retryDelay,
            failureCallback: fn () => $this->forceReconnect(),
        );
    }

    /**
     * Force a reconnect, we ignore naming resolution exceptions.
     */
    protected function forceReconnect(): void
    {
        try {
            $this->disconnect();
        } catch (RedisException $e) {
            // Ignore when the creation of a new client gets an exception.
            // If this exception isn't caught the retry will stop.
        } catch (Throwable $e) {
            if (! $this->retryContext->manager()->isNameResolutionException($e)) {
                throw $e;
            }
        }

        // Here we reconnect through Redis Sentinel if we lost connection to the server or if another unavailability occurred.
        // We may actually reconnect to the same, broken server. But after a failover occured, we should be ok.
        // It may take a moment until the Sentinel returns the new master, so this may be triggered multiple times.
        try {
            $this->reconnect();
        } catch (RedisException $e) {
            // Ignore when the creation of a new client gets an exception.
            // If this exception isn't caught the retry will stop.
        } catch (Throwable $e) {
            if (! $this->retryContext->manager()->isNameResolutionException($e)) {
                throw $e;
            }
        }
    }

    /**
     * Reconnects to the Redis server by overriding the current connection.
     */
    private function reconnect(): void
    {
        $this->client = $this->connector ? call_user_func($this->connector) : $this->client;
    }
}
