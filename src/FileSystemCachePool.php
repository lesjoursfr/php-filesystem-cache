<?php

namespace FileSystemCache;

use FileSystemCache\Adapter\LocalFileSystem;
use FileSystemCache\Exception\CachePoolException;
use FileSystemCache\Exception\InvalidArgumentException;
use FileSystemCache\Exception\LocalFileSystemException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * File system cache pool.
 */
class FileSystemCachePool implements LoggerAwareInterface, CacheInterface, CacheItemPoolInterface
{
    public const SEPARATOR_TAG = '!';

    private LocalFileSystem $filesystem;
    private string $folder;
    private array $deferred = [];
    private ?LoggerInterface $logger = null;

    /**
     *  Create a new File system cache pool.
     *
     * @param LocalFileSystem $filesystem The local file system
     * @param string          $folder     The folder for cached files (should not begin nor end with a slash, example: path/to/cache)
     */
    public function __construct(LocalFileSystem $filesystem, string $folder = 'cache')
    {
        $this->folder = $folder;

        $this->filesystem = $filesystem;
        $this->filesystem->createDirectory($this->folder);
    }

    /**
     * Make sure to commit before we destruct.
     */
    public function __destruct()
    {
        $this->commit();
    }

    /**
     * Returns a Cache Item representing the specified key.
     *
     * This method must always return a CacheItemInterface object, even in case of
     * a cache miss. It MUST NOT return null.
     *
     * @param string $key the key for which to return the corresponding Cache Item
     *
     * @return CacheItem the corresponding Cache Item
     *
     * @throws InvalidArgumentException If the $key string is not a legal value
     */
    public function getItem(string $key): CacheItem
    {
        $this->validateKey($key);
        if (isset($this->deferred[$key])) {
            /** @var CacheItem $item */
            $item = clone $this->deferred[$key];
            $item->moveTagsToPrevious();

            return $item;
        }

        $func = function () use ($key) {
            try {
                return $this->fetchObjectFromCache($key);
            } catch (\Exception $e) {
                $this->handleException($e, __FUNCTION__);
            }
        };

        return new CacheItem($key, $func);
    }

    /**
     * Returns a traversable set of cache items.
     *
     * @param array $keys an indexed array of keys of items to retrieve
     *
     * @return array|\Traversable A traversable collection of Cache Items keyed by the cache keys of
     *                            each item. A Cache item will be returned for each key, even if that
     *                            key is not found. However, if no keys are specified then an empty
     *                            traversable MUST be returned instead.
     *
     * @throws InvalidArgumentException If any of the keys in $keys are not a legal value
     */
    public function getItems(array $keys = []): array|\Traversable
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }

        return $items;
    }

    /**
     * Confirms if the cache contains specified cache item.
     *
     * Note: This method MAY avoid retrieving the cached value for performance reasons.
     * This could result in a race condition with CacheItemInterface::get(). To avoid
     * such situation use CacheItemInterface::isHit() instead.
     *
     * @param string $key the key for which to check existence
     *
     * @return bool true if item exists in the cache, false otherwise
     *
     * @throws InvalidArgumentException If the $key string is not a legal value
     */
    public function hasItem(string $key): bool
    {
        try {
            return $this->getItem($key)->isHit();
        } catch (\Exception $e) {
            return $this->handleException($e, __FUNCTION__);
        }
    }

    /**
     * Deletes all items in the pool.
     *
     * @return bool True if the pool was successfully cleared. False if there was an error.
     */
    public function clear(): bool
    {
        // Clear the deferred items
        $this->deferred = [];

        try {
            return $this->clearAllObjectsFromCache();
        } catch (\Exception $e) {
            return $this->handleException($e, __FUNCTION__);
        }
    }

    /**
     * Removes the item from the pool.
     *
     * @param string $key the key to delete
     *
     * @return bool True if the item was successfully removed. False if there was an error.
     *
     * @throws InvalidArgumentException If the $key string is not a legal value
     */
    public function deleteItem(string $key): bool
    {
        try {
            return $this->deleteItems([$key]);
        } catch (\Exception $e) {
            return $this->handleException($e, __FUNCTION__);
        }
    }

    /**
     * Removes multiple items from the pool.
     *
     * @param array $keys an array of keys that should be removed from the pool
     *
     * @return bool True if the items were successfully removed. False if there was an error.
     *
     * @throws InvalidArgumentException If any of the keys in $keys are not a legal value
     */
    public function deleteItems(array $keys): bool
    {
        $deleted = true;
        foreach ($keys as $key) {
            $this->validateKey($key);

            // Delete form deferred
            unset($this->deferred[$key]);

            // We have to commit here to be able to remove deferred hierarchy items
            $this->commit();
            $this->preRemoveItem($key);

            if (!$this->clearOneObjectFromCache($key)) {
                $deleted = false;
            }
        }

        return $deleted;
    }

    /**
     * Persists a cache item immediately.
     *
     * @param CacheItemInterface $item the cache item to save
     *
     * @return bool True if the item was successfully persisted. False if there was an error.
     */
    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof CacheItem) {
            $e = new InvalidArgumentException('Cache items are not transferable between pools. Item MUST implement CacheItem.');

            return $this->handleException($e, __FUNCTION__);
        }

        $this->removeTagEntries($item);
        $this->saveTags($item);
        $timeToLive = null;
        if (null !== ($timestamp = $item->getExpirationTimestamp())) {
            $timeToLive = $timestamp - time();

            if ($timeToLive < 0) {
                return $this->deleteItem($item->getKey());
            }
        }

        try {
            return $this->storeItemInCache($item, $timeToLive);
        } catch (\Exception $e) {
            return $this->handleException($e, __FUNCTION__);
        }
    }

    /**
     * Sets a cache item to be persisted later.
     *
     * @param CacheItemInterface $item the cache item to save
     *
     * @return bool False if the item could not be queued or if a commit was attempted and failed. True otherwise.
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    /**
     * Persists any deferred cache items.
     *
     * @return bool True if all not-yet-saved items were successfully saved or there were none. False otherwise.
     */
    public function commit(): bool
    {
        $saved = true;
        foreach ($this->deferred as $item) {
            if (!$this->save($item)) {
                $saved = false;
            }
        }
        $this->deferred = [];

        return $saved;
    }

    /**
     * Fetches a value from the cache.
     *
     * @param string $key     the unique key of this item in the cache
     * @param mixed  $default default value to return if the key does not exist
     *
     * @return mixed the value of the item from the cache, or $default in case of cache miss
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if the $key string is not a legal value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $item = $this->getItem($key);
        if (!$item->isHit()) {
            return $default;
        }

        return $item->get();
    }

    /**
     * Persists data in the cache, uniquely referenced by a key with an optional expiration TTL time.
     *
     * @param string                 $key   the key of the item to store
     * @param mixed                  $value The value of the item to store. Must be serializable.
     * @param int|\DateInterval|null $ttl   Optional. The TTL value of this item. If no value is sent and
     *                                      the driver supports TTL then the library may set a default value
     *                                      for it or let the driver take care of that.
     *
     * @return bool true on success and false on failure
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if the $key string is not a legal value
     */
    public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
    {
        $item = $this->getItem($key);
        $item->set($value);
        $item->expiresAfter($ttl);

        return $this->save($item);
    }

    /**
     * Delete an item from the cache by its unique key.
     *
     * @param string $key the unique cache key of the item to delete
     *
     * @return bool True if the item was successfully removed. False if there was an error.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if the $key string is not a legal value
     */
    public function delete(string $key): bool
    {
        return $this->deleteItem($key);
    }

    /**
     * Obtains multiple cache items by their unique keys.
     *
     * @param iterable $keys    a list of keys that can obtained in a single operation
     * @param mixed    $default default value to return for keys that do not exist
     *
     * @return iterable A list of key => value pairs. Cache keys that do not exist or are stale will have $default as value.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $keys is neither an array nor a Traversable,
     *                                                   or if any of the $keys are not a legal value
     */
    public function getMultiple($keys, $default = null): iterable
    {
        if (!is_array($keys)) {
            if (!$keys instanceof \Traversable) {
                throw new InvalidArgumentException('$keys is neither an array nor Traversable');
            }

            // Since we need to throw an exception if *any* key is invalid, it doesn't
            // make sense to wrap iterators or something like that.
            $keys = iterator_to_array($keys, false);
        }

        $items = $this->getItems($keys);

        return $this->generateValues($default, $items);
    }

    /**
     * Persists a set of key => value pairs in the cache, with an optional TTL.
     *
     * @param iterable               $values a list of key => value pairs for a multiple-set operation
     * @param int|\DateInterval|null $ttl    Optional. The TTL value of this item. If no value is sent and
     *                                       the driver supports TTL then the library may set a default value
     *                                       for it or let the driver take care of that.
     *
     * @return bool true on success and false on failure
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $values is neither an array nor a Traversable,
     *                                                   or if any of the $values are not a legal value
     */
    public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
    {
        if (!is_array($values)) {
            if (!$values instanceof \Traversable) {
                throw new InvalidArgumentException('$values is neither an array nor Traversable');
            }
        }

        $keys = [];
        $arrayValues = [];
        foreach ($values as $key => $value) {
            if (is_int($key)) {
                $key = (string) $key;
            }
            $this->validateKey($key);
            $keys[] = $key;
            $arrayValues[$key] = $value;
        }

        $items = $this->getItems($keys);
        $itemSuccess = true;
        foreach ($items as $key => $item) {
            $item->set($arrayValues[$key]);

            try {
                $item->expiresAfter($ttl);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException($e->getMessage(), $e->getCode(), $e);
            }

            $itemSuccess = $itemSuccess && $this->saveDeferred($item);
        }

        return $itemSuccess && $this->commit();
    }

    /**
     * Deletes multiple cache items in a single operation.
     *
     * @param iterable $keys a list of string-based keys to be deleted
     *
     * @return bool True if the items were successfully removed. False if there was an error.
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if $keys is neither an array nor a Traversable,
     *                                                   or if any of the $keys are not a legal value
     */
    public function deleteMultiple(iterable $keys): bool
    {
        if (!is_array($keys)) {
            if (!$keys instanceof \Traversable) {
                throw new InvalidArgumentException('$keys is neither an array nor Traversable');
            }

            // Since we need to throw an exception if *any* key is invalid, it doesn't
            // make sense to wrap iterators or something like that.
            $keys = iterator_to_array($keys, false);
        }

        return $this->deleteItems($keys);
    }

    /**
     * Determines whether an item is present in the cache.
     *
     * NOTE: It is recommended that has() is only to be used for cache warming type purposes
     * and not to be used within your live applications operations for get/set, as this method
     * is subject to a race condition where your has() will return true and immediately after,
     * another script can remove it, making the state of your app out of date.
     *
     * @param string $key the cache item key
     *
     * @return bool True if the item is present in the cache
     *
     * @throws \Psr\SimpleCache\InvalidArgumentException if the $key string is not a legal value
     */
    public function has(string $key): bool
    {
        return $this->hasItem($key);
    }

    /**
     * Sets a logger instance on the object.
     *
     * @param LoggerInterface $logger The new logger
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Set the root folder for the cache.
     *
     * @param string $folder The root
     */
    public function setFolder($folder): void
    {
        $this->folder = $folder;
    }

    /**
     * Invalidate an array of tags.
     *
     * @param array $tags The tags to invalidate
     *
     * @return bool True if all items & tags have been deleted
     */
    public function invalidateTags(array $tags): bool
    {
        $itemIds = [];
        foreach ($tags as $tag) {
            $itemIds = array_merge($itemIds, $this->getList($this->getTagKey($tag)));
        }

        // Remove all items with the tag
        $success = $this->deleteItems($itemIds);

        if ($success) {
            // Remove the tag list
            foreach ($tags as $tag) {
                $this->removeList($this->getTagKey($tag));
                $l = $this->getList($this->getTagKey($tag));
            }
        }

        return $success;
    }

    /**
     * Invalidate a tag.
     *
     * @param string $tag The tag to invalidate
     *
     * @return bool True if all items & the tag have been deleted
     */
    public function invalidateTag(string $tag): bool
    {
        return $this->invalidateTags([$tag]);
    }

    /**
     * Check if the key is valid.
     *
     * @param string $key The key to validate
     *
     * @throws InvalidArgumentException If the key is not valid
     */
    private function validateKey(string $key): void
    {
        if (!isset($key[0])) {
            $e = new InvalidArgumentException('Cache key cannot be an empty string');
            $this->handleException($e, __FUNCTION__);
        }
        if (preg_match('|[\{\}\(\)/\\\@\:]|', $key)) {
            $e = new InvalidArgumentException(sprintf(
                'Invalid key: "%s". The key contains one or more characters reserved for future extension: {}()/\@:',
                $key
            ));
            $this->handleException($e, __FUNCTION__);
        }
    }

    /**
     * Save the Cache Item in all tag lists.
     *
     * @param CacheItem $item The item
     */
    private function saveTags(CacheItem $item): void
    {
        $tags = $item->getTags();
        foreach ($tags as $tag) {
            $this->appendListItem($this->getTagKey($tag), $item->getKey());
        }
    }

    /**
     * Removes the key form all tag lists. When an item with tags is removed
     * we MUST remove the tags. If we fail to remove the tags a new item with
     * the same key will automatically get the previous tags.
     *
     * @param string $key The key to remove
     *
     * @return FileSystemCachePool This item
     */
    private function preRemoveItem($key): FileSystemCachePool
    {
        $item = $this->getItem($key);
        $this->removeTagEntries($item);

        return $this;
    }

    /**
     * Remove the Cache Item from all tag lists.
     *
     * @param CacheItem $item The item
     */
    private function removeTagEntries(CacheItem $item): void
    {
        $tags = $item->getPreviousTags();
        foreach ($tags as $tag) {
            $this->removeListItem($this->getTagKey($tag), $item->getKey());
        }
    }

    /**
     * Get the cache key for the tag.
     *
     * @param string $tag The tag
     *
     * @return string The cache key
     */
    private function getTagKey(string $tag): string
    {
        return 'tag'.self::SEPARATOR_TAG.$tag;
    }

    /**
     * Get the file path for the cache key.
     *
     * @param string $key The cache key
     *
     * @return string The file path
     *
     * @throws InvalidArgumentException
     */
    private function getFilePath(string $key): string
    {
        if (!preg_match('|^[a-zA-Z0-9_\.! ]+$|', $key)) {
            throw new InvalidArgumentException(sprintf('Invalid key "%s". Valid filenames must match [a-zA-Z0-9_\.! ].', $key));
        }

        return sprintf('%s/%s', $this->folder, $key);
    }

    /**
     * Save a Cache Item.
     *
     * @param CacheItem $item The Cache Item to save
     * @param int|null  $ttl  seconds from now
     *
     * @return bool True if saved
     */
    private function storeItemInCache(CacheItem $item, ?int $ttl): bool
    {
        $data = serialize(
            [
                $item->get(),
                $item->getTags(),
                $item->getExpirationTimestamp(),
            ]
        );

        $file = $this->getFilePath($item->getKey());

        // Update file if it exists
        $this->filesystem->write($file, $data);

        return true;
    }

    /**
     * Fetch an object from the cache implementation.
     *
     * If it is a cache miss, it MUST return [false, null, [], null]
     *
     * @param string $key The key to fetch
     *
     * @return array with [isHit, value, tags[], expirationTimestamp]
     */
    private function fetchObjectFromCache(string $key): array
    {
        $empty = [false, null, [], null];
        $file = $this->getFilePath($key);

        try {
            $data = @unserialize($this->filesystem->read($file));
            if (false === $data) {
                return $empty;
            }
        } catch (LocalFileSystemException $e) {
            return $empty;
        }

        // Determine expirationTimestamp from data, remove items if expired
        $expirationTimestamp = $data[2] ?: null;
        if (null !== $expirationTimestamp && time() > $expirationTimestamp) {
            foreach ($data[1] as $tag) {
                $this->removeListItem($this->getTagKey($tag), $key);
            }
            $this->forceClear($key);

            return $empty;
        }

        return [true, $data[0], $data[1], $expirationTimestamp];
    }

    /**
     * Clear all objects from cache.
     *
     * @return bool True if all objects have been deleted
     */
    private function clearAllObjectsFromCache(): bool
    {
        $this->filesystem->deleteDirectory($this->folder);
        $this->filesystem->createDirectory($this->folder);

        return true;
    }

    /**
     * Remove one object from cache.
     *
     * @param string $key The cache key to remove
     *
     * @return bool True if the object has been deleted
     */
    private function clearOneObjectFromCache(string $key): bool
    {
        return $this->forceClear($key);
    }

    /**
     * Get an array with all the values in the list.
     *
     * @param string $name The name of the list
     *
     * @return array The values
     */
    private function getList($name): array
    {
        $file = $this->getFilePath($name);

        if (!$this->filesystem->fileExists($file)) {
            $this->filesystem->write($file, serialize([]));
        }

        return unserialize($this->filesystem->read($file));
    }

    /**
     * Remove the list.
     *
     * @param string $name The name of the list
     */
    private function removeList($name): void
    {
        $file = $this->getFilePath($name);
        $this->filesystem->delete($file);
    }

    /**
     * Add a item key on a list named $name.
     *
     * @param string $name The name of the list
     * @param string $key  The item key
     */
    private function appendListItem(string $name, string $key): void
    {
        $list = $this->getList($name);
        $list[] = $key;

        $this->filesystem->write($this->getFilePath($name), serialize($list));
    }

    /**
     * Remove an item from the list.
     *
     * @param string $name The name of the list
     * @param string $key  The key to remove
     */
    private function removeListItem(string $name, string $key): void
    {
        $list = $this->getList($name);
        foreach ($list as $i => $item) {
            if ($item === $key) {
                unset($list[$i]);
            }
        }

        $this->filesystem->write($this->getFilePath($name), serialize($list));
    }

    /**
     * Log exception and rethrow it.
     *
     * @param \Exception $e        The exception
     * @param string     $function The function name
     *
     * @throws CachePoolException
     */
    private function handleException(\Exception $e, string $function): false
    {
        $level = 'alert';
        if ($e instanceof InvalidArgumentException) {
            $level = 'warning';
        }

        $this->log($level, $e->getMessage(), ['exception' => $e]);

        if ($e instanceof CachePoolException || $e instanceof InvalidArgumentException) {
            throw $e;
        }

        throw new CachePoolException(sprintf('Exception thrown when executing "%s". ', $function), 0, $e);
    }

    /**
     * Remove the key from the cache.
     *
     * @param string $key the key to remove
     *
     * @return bool True if the key has been deleted
     */
    private function forceClear(string $key): bool
    {
        $this->filesystem->delete($this->getFilePath($key));

        return true;
    }

    /**
     * Get values for multiple cache items.
     *
     * @param mixed              $default default value to return for keys that do not exist
     * @param array|\Traversable $items   A traversable collection of Cache Items
     *
     * @return iterable a list of key => value pairs
     */
    private function generateValues(mixed $default, array|\Traversable $items): iterable
    {
        foreach ($items as $key => $item) {
            /** @var $item CacheItemInterface */
            if (!$item->isHit()) {
                yield $key => $default;
            } else {
                yield $key => $item->get();
            }
        }
    }

    /**
     * Logs with an arbitrary level if the logger exists.
     *
     * @param mixed  $level   The level
     * @param string $message The message
     * @param array  $context The context
     */
    private function log(mixed $level, string $message, array $context = []): void
    {
        if (null !== $this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }
}
