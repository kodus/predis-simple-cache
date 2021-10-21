<?php

namespace Kodus\PredisSimpleCache;

use Closure;
use DateInterval;
use Predis\Client;
use Psr\SimpleCache\CacheInterface;
use Throwable;
use Traversable;

/**
 * Bridges PSR-16 interface to a redis database via the predis/predis client.
 *
 * Dev Note:
 * Notice the design of \Predis\Client is to simply pass function name and arguments to redis, but uses the typehints
 * from the ext-redis client \Redis. So don't trust the typehints blindly and refer to the redis documentation when
 * \Predis\Client behaves in an unexpected manner. And test rigorously!
 *
 */
class PredisSimpleCache implements CacheInterface
{
    private const VALID_KEY_REGEX = '/^[a-zA-Z0-9\.\-]+$/';

    private Client $client;

    private int $default_ttl;

    /**
     * @param Client $client      The Predis Client to use for the cached data
     * @param int    $default_ttl Default time-to-live as a unix timestamp
     */
    public function __construct(Client $client, int $default_ttl)
    {
        $this->client = $client;
        $this->default_ttl = $default_ttl;
    }

    public function get($key, $default = null)
    {
        $this->validateKey($key);

        return $this->client->exists($key) ? unserialize($this->client->get($key)) : $default;
    }

    public function set($key, $value, $ttl = null): bool
    {
        $this->validateKey($key);

        if (is_int($ttl)) {
            $expires = $ttl;
        } elseif ($ttl instanceof DateInterval) {
            $expires = $this->unixTimestampFromDateInterval($ttl) - time();
        } elseif ($ttl === null) {
            $expires = $this->default_ttl;
        } else {
            throw new InvalidArgumentException("Invalid TTL value");
        }

        if ($expires > 0) {
            $this->client->setex($key, $expires, serialize($value));
        } else {
            $this->delete($key);
        }

        return true;
    }

    public function delete($key): bool
    {
        $this->validateKey($key);

        $this->client->del($key);

        return true;
    }

    public function clear(): bool
    {
        $result = $this->client->flushall();

        return mb_strpos('OK', $result) !== false;
    }

    public function getMultiple($keys, $default = null)
    {
        $this->validateIterable($keys);

        /** @var Traversable|array $keys */
        $key_list = is_array($keys) ? $keys : iterator_to_array($keys);

        foreach ($key_list as $key) {
            if (! is_int($key)) {
                $this->validateKey($key);
            }
        }


        $result = [];
        foreach ($key_list as $key) {
            $value = $this->client->get($key);
            $result[$key] = $value ? unserialize($value) : $default;
        }

        return $result;
    }

    public function setMultiple($values, $ttl = null): bool
    {
        $this->validateIterable($values);

        //Dev. Note: Using mset does not allow TTL arguments, so it seems going through all keys is unavoidable.
        foreach ($values as $key => $value) {
            if (! is_int($key)) {
                $this->validateKey($key);
            }
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple($keys): bool
    {
        /** @var $keys array|Traversable */
        $this->validateIterable($keys);

        // Dev Note: Predis\Client claims to accept string|array, but only allows string.
        $this->client->multi();
        try {
            foreach ($keys as $key) {
                if (! is_int($key)) {
                    $this->validateKey($key);
                }
                $this->client->del($key);
            }
        } catch (Throwable $throwable) {
            $this->client->discard();

            throw $throwable;
        }

        $this->client->exec();

        return true;
    }

    public function has($key): bool
    {
        $this->validateKey($key);

        return $this->client->exists($key) === 1;
    }

    private function unixTimestampFromDateInterval(DateInterval $interval): int
    {
        $date_time = date_create_from_format('U', (string) time());
        $date_time->add($interval);

        return $date_time->getTimestamp();
    }

    private function validateKey($key): void
    {
        if (! is_string($key)) {
            $type = is_object($key) ? get_class($key) : gettype($key);

            throw new InvalidArgumentException("Invalid key type {$type}");
        }

        if (preg_match(self::VALID_KEY_REGEX, $key) !== 1) {
            throw new InvalidArgumentException("Illegal character in key '{$key}'");
        }
    }

    private function validateIterable($values): void
    {
        if (! $this->isArrayOrTraversable($values)) {
            throw new InvalidArgumentException("Values must be an array or instance of \Traversable");
        }
    }

    private function isArrayOrTraversable($value): bool
    {
        return is_array($value) || $value instanceof Traversable;
    }
}
