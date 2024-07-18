<?php

namespace FileSystemCache\Exception;

use Psr\Cache\CacheException as CacheExceptionInterface;

/**
 * Exception for all exceptions thrown by an Implementing Library.
 */
class CachePoolException extends \RuntimeException implements CacheExceptionInterface
{
}
