<?php

namespace FileSystemCache;

use FileSystemCache\Exception\InvalidArgumentException;
use Psr\Cache\CacheItemInterface;

/**
 * Item in the cache.
 */
class CacheItem implements CacheItemInterface
{
    private array $prevTags = [];
    private array $tags = [];
    private ?\Closure $callable = null;
    private string $key;
    private mixed $value = null;
    /**
     * The expiration timestamp is the source of truth. This is the UTC timestamp
     * when the cache item expire. A value of zero means it never expires. A nullvalue
     * means that no expiration is set.
     */
    private ?int $expirationTimestamp = null;
    private bool $hasValue = false;

    /**
     * @param string        $key      The key
     * @param \Closure|bool $callable A callable or the boolean hasValue
     * @param mixed         $value    The value
     */
    public function __construct(string $key, \Closure|bool|null $callable = null, mixed $value = null)
    {
        $this->key = $key;

        if (true === $callable) {
            $this->hasValue = true;
            $this->value = $value;
        } elseif (false !== $callable) {
            // This must be a callable or null
            $this->callable = $callable;
        }
    }

    /**
     * Returns the key for the current cache item.
     *
     * The key is loaded by the Implementing Library, but should be available to
     * the higher level callers when needed.
     *
     * @return string The key string for this cache item
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Sets the value represented by this cache item.
     *
     * The $value argument may be any item that can be serialized by PHP,
     * although the method of serialization is left up to the Implementing
     * Library.
     *
     * @param mixed $value the serializable value to be stored
     *
     * @return static the invoked object
     */
    public function set(mixed $value): static
    {
        $this->value = $value;
        $this->hasValue = true;
        $this->callable = null;

        return $this;
    }

    /**
     * Retrieves the value of the item from the cache associated with this object's key.
     *
     * The value returned must be identical to the value originally stored by set().
     *
     * If isHit() returns false, this method MUST return null. Note that null
     * is a legitimate cached value, so the isHit() method SHOULD be used to
     * differentiate between "null value was found" and "no value was found."
     *
     * @return mixed the value corresponding to this cache item's key, or null if not found
     */
    public function get(): mixed
    {
        if (!$this->isHit()) {
            return null;
        }

        return $this->value;
    }

    /**
     * Confirms if the cache item lookup resulted in a cache hit.
     *
     * Note: This method MUST NOT have a race condition between calling isHit()
     * and calling get().
     *
     * @return bool True if the request resulted in a cache hit. False otherwise.
     */
    public function isHit(): bool
    {
        $this->initialize();

        if (!$this->hasValue) {
            return false;
        }

        if (null !== $this->expirationTimestamp) {
            return $this->expirationTimestamp > time();
        }

        return true;
    }

    /**
     * The timestamp when the object expires.
     *
     * @return int|null The timestamp
     */
    public function getExpirationTimestamp(): ?int
    {
        return $this->expirationTimestamp;
    }

    /**
     * Sets the expiration time for this cache item.
     *
     * @param int|\DateTimeInterface|null $expiration The point in time after which the item MUST be considered expired.
     *                                                If null is passed explicitly, a default value MAY be used. If none is set,
     *                                                the value should be stored permanently or for as long as the
     *                                                implementation allows.
     *
     * @return static the called object
     *
     * @throws InvalidArgumentException when the expiration is not valid
     */
    public function expiresAt(\DateTimeInterface|int|null $expiration): static
    {
        if ($expiration instanceof \DateTimeInterface) {
            $this->expirationTimestamp = $expiration->getTimestamp();
        } elseif (is_int($expiration) || null === $expiration) {
            $this->expirationTimestamp = $expiration;
        } else {
            throw new InvalidArgumentException('Cache item ttl/expiresAt must be of type integer or \DateTimeInterface.');
        }

        return $this;
    }

    /**
     * Sets the expiration time for this cache item.
     *
     * @param int|\DateInterval|null $time The period of time from the present after which the item MUST be considered
     *                                     expired. An integer parameter is understood to be the time in seconds until
     *                                     expiration. If null is passed explicitly, a default value MAY be used.
     *                                     If none is set, the value should be stored permanently or for as long as the
     *                                     implementation allows.
     *
     * @return static the called object
     *
     * @throws InvalidArgumentException when the time is not valid
     */
    public function expiresAfter(\DateInterval|int|null $time): static
    {
        if (null === $time) {
            $this->expirationTimestamp = null;
        } elseif ($time instanceof \DateInterval) {
            $date = new \DateTime();
            $date->add($time);
            $this->expirationTimestamp = $date->getTimestamp();
        } elseif (is_int($time)) {
            $this->expirationTimestamp = time() + $time;
        } else {
            throw new InvalidArgumentException('Cache item ttl/expiresAfter must be of type integer or \DateInterval.');
        }

        return $this;
    }

    /**
     * Get all existing tags. These are the tags the item has when the item is
     * returned from the pool.
     *
     * @return array All existing tags
     */
    public function getPreviousTags(): array
    {
        $this->initialize();

        return $this->prevTags;
    }

    /**
     * Get the current tags. These are not the same tags as getPrevious tags. This
     * is the tags that has been added to the item after the item was fetched from
     * the cache storage.
     *
     * WARNING: This is generally not the function you want to use. Please see
     * `getPreviousTags`.
     *
     * @return array The current tags
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Overwrite all tags with a new set of tags.
     *
     * @param array $tags An array of tags
     *
     * @return CacheItem This item
     *
     * @throws InvalidArgumentException when a tag is not valid
     */
    public function setTags(array $tags): CacheItem
    {
        $this->tags = [];
        $this->tag($tags);

        return $this;
    }

    /**
     * This function should never be used and considered private.
     * Move tags from $tags to $prevTags.
     */
    public function moveTagsToPrevious()
    {
        $this->prevTags = $this->tags;
        $this->tags = [];
    }

    /**
     * Adds a tag to a cache item.
     *
     * @param string|array $tags A tag or array of tags
     *
     * @return CacheItem This item
     *
     * @throws InvalidArgumentException when $tag is not valid
     */
    private function tag(string|array $tags): CacheItem
    {
        $this->initialize();

        if (!is_array($tags)) {
            $tags = [$tags];
        }
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                throw new InvalidArgumentException(sprintf('Cache tag must be string, "%s" given', is_object($tag) ? get_class($tag) : gettype($tag)));
            }
            if (isset($this->tags[$tag])) {
                continue;
            }
            if (!isset($tag[0])) {
                throw new InvalidArgumentException('Cache tag length must be greater than zero');
            }
            if (isset($tag[strcspn($tag, '{}()/\@:')])) {
                throw new InvalidArgumentException(sprintf('Cache tag "%s" contains reserved characters {}()/\@:', $tag));
            }
            $this->tags[$tag] = $tag;
        }

        return $this;
    }

    /**
     * If callable is not null, execute it an populate this object with values.
     */
    private function initialize()
    {
        if (null !== $this->callable) {
            // $func will be $adapter->fetchObjectFromCache();
            $func = $this->callable;
            $result = $func();
            $this->hasValue = $result[0];
            $this->value = $result[1];
            $this->prevTags = isset($result[2]) ? $result[2] : [];
            $this->expirationTimestamp = null;

            if (isset($result[3]) && is_int($result[3])) {
                $this->expirationTimestamp = $result[3];
            }

            $this->callable = null;
        }
    }
}
