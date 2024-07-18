<?php

namespace FileSystemCache\Tests\Trait;

use FileSystemCache\Adapter\LocalFileSystem;
use FileSystemCache\FileSystemCachePool;

trait CreatePoolTrait
{
    private ?LocalFileSystem $filesystem = null;

    public function createCachePool(): FileSystemCachePool
    {
        return new FileSystemCachePool($this->getFilesystem());
    }

    public function createSimpleCache(): FileSystemCachePool
    {
        return $this->createCachePool();
    }

    private function getFilesystem(): LocalFileSystem
    {
        if (null === $this->filesystem) {
            $this->filesystem = new LocalFileSystem(__DIR__.'/../tmp/cache'.rand(1, 100000));
        }

        return $this->filesystem;
    }
}
