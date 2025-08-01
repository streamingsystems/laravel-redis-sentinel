<?php

declare(strict_types=1);

namespace Namoshek\Redis\Sentinel\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Namoshek\Redis\Sentinel\Exceptions\RetryRedisException;
use RedisException;
use Throwable;

class RetryManager
{
    /**
     * The following array contains all exception message parts which are interpreted as a connection loss or
     * another unavailability of Redis.
     */
    public const ERROR_MESSAGES_INDICATING_UNAVAILABILITY = [
        'connection closed',
        'connection refused',
        'connection lost',
        'failed while reconnecting',
        'is loading the dataset in memory',
        'php_network_getaddresses',
        'read error on connection',
        'socket',
        'went away',
        'loading',
        'readonly',
        "can't write against a read only replica",
    ];

    /**
     * The following array contains all exception message parts which are interpreted as an issue with name
     * resolving.
     */
    public const MESSAGES_INDICATING_NAME_RESOLUTION_ERRORS = [
        'getaddrinfo',
        'name or service not known',
    ];

    /**
     * Attempt to retry the provided operation when the client fails to connect
     * to a Redis server.
     *
     * @param callable $callback The operation to execute.
     * @param int $retryAttempts The number of times the retry is performed.
     * @param int $retryDelay The time in milliseconds to wait before retrying again.
     * @param callable|null $failureCallback The callback to execute when failure occours.
     * @return mixed The result of the first successful attempt.
     *
     * @throws RetryRedisException|RedisException
     */
    public function retryOnFailure(
        callable  $callback,
        int       $retryAttempts,
        int       $retryDelay,
        ?callable $failureCallback = null,
    ): mixed
    {
        $lastException = null;
        $debug = Str::contains(gethostname(), ['origin1', 'origin2']);

        for ($currentAttempt = 0; $currentAttempt <= $retryAttempts; $currentAttempt++) {
            try {
                // We directly return the callback on the first attempt.
                if ($currentAttempt === 0) {
                    return $callback();
                }
                // Wrap the callback to distinguish them from the first attempt.
                return $this->retry($callback);
            } catch (Throwable $exception) {
                if ($debug)
                    Log::debug("RetryManager::exception hit");
                // Check if we should retry this exception.
                if (!$this->shouldRetry($exception)) {
                    if ($debug)
                        Log::debug("Should not retry, throw exception");
                    throw $exception;
                }

                // Wait before retry.
                if ($retryAttempts !== 0) {
                    $delay = $retryDelay * 1000;
                    if ($debug)
                        Log::debug("retryAttempts ($retryAttempts) !== 0, usleep for $delay");
                    usleep($delay);
                }

                // Execute optional failure callback.
                if ($failureCallback && is_callable($failureCallback)) {
                    if ($debug)
                        Log::debug("Executing failure callback");
                    call_user_func($failureCallback);
                }
                if ($debug)
                    Log::debug("Set lastException = exception");
                $lastException = $exception;
            }
        }

        throw new RetryRedisException(
            sprintf('Reached the (re)connect limit of %d attempts.', $retryAttempts),
            0,
            $lastException,
        );
    }

    /**
     * Perform as retry when it is a second attempt. This makes testing easier.
     */
    public function retry(callable $callback): mixed
    {
        return $callback();
    }

    /**
     * We check if the Exception should be retried. This means checking for:
     * - retryable redis exceptions
     * - name resolution exceptions
     */
    public function shouldRetry(Throwable $exception): bool
    {
        $shouldRetryRedisException = $this->shouldRetryRedisException($exception);
        $instanceofRedisException = $exception instanceof RedisException;
        Log::debug("RetryManager: shouldRetry: shouldRetryRedisException: $shouldRetryRedisException, instanceofRedisException: $instanceofRedisException");
        if ($instanceofRedisException && $shouldRetryRedisException) {
            Log::debug("RetryManager: shouldRetry return true (1)");
            return true;
        } else {
            Log::debug("RetryManager: shouldRetry continue (1)");
        }

        if ($this->isNameResolutionException($exception)) {
            Log::debug("RetryManager: shouldRetry isNameResolutionException, return true");
            return true;
        } else {
            Log::debug("RetryManager: isNameResolutionException, not set");
        }

        Log::debug("RetryManager: return false");
        return false;
    }

    /**
     * Inspects the given exception and reconnects the client if the reported error indicates that the server
     * went away or is in readonly mode, which may happen in case of a Redis Sentinel failover.
     */
    public function shouldRetryRedisException(RedisException $exception): bool
    {
        // We convert the exception message to lower-case in order to perform case-insensitive comparison.
        $message = $exception->getMessage();
        $exceptionMessage = strtolower($message);
        Log::debug("RetryManager: shouldRetryRedisException, $exceptionMessage: $exceptionMessage");
        // Because we also match only partial exception messages, we cannot use in_array() at this point.
        foreach (self::ERROR_MESSAGES_INDICATING_UNAVAILABILITY as $errorMessage) {
            $contains = str_contains($exceptionMessage, $errorMessage);
            Log::debug("RetryManager: shouldRetryRedisException, $errorMessage contains: $exceptionMessage: $contains");
            if ($contains) {
                Log::debug("RetryManager: shouldRetryRedisException, return true");
                return true;
            }
        }
        Log::debug("RetryManager: shouldRetryRedisException, return false");
        return false;
    }

    /**
     * Check if the given exception is a name resolution exception.
     */
    public function isNameResolutionException(Throwable $exception): bool
    {
        // We convert the exception message to lower-case in order to perform case-insensitive comparison.
        $exceptionMessage = strtolower($exception->getMessage());

        // Because we also match only partial exception messages, we cannot use in_array() at this point.
        foreach (self::MESSAGES_INDICATING_NAME_RESOLUTION_ERRORS as $errorMessage) {
            if (str_contains($exceptionMessage, $errorMessage)) {
                return true;
            }
        }

        return false;
    }
}
