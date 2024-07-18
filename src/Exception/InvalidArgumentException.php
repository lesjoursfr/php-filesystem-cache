<?php

namespace FileSystemCache\Exception;

use Psr\Cache\InvalidArgumentException as CacheInvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as SimpleCacheInvalidArgumentException;

/**
 * Exception for invalid cache arguments.
 */
class InvalidArgumentException extends \RuntimeException implements CacheInvalidArgumentException, SimpleCacheInvalidArgumentException
{
}
