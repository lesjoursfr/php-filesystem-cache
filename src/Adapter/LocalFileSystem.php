<?php

namespace FileSystemCache\Adapter;

use FileSystemCache\Exception\LocalFileSystemException;

/**
 * Local file system adapter.
 */
class LocalFileSystem
{
    private string $rootLocation;
    private string $prefix = '';

    /**
     * Create a new LocalFileSystem.
     *
     * @param string $location The root location for the local file system
     */
    public function __construct(string $location)
    {
        $this->rootLocation = $location;

        $this->prefix = rtrim($location, '\\/');
        if ('' !== $this->prefix || DIRECTORY_SEPARATOR === $location) {
            $this->prefix .= DIRECTORY_SEPARATOR;
        }

        $this->ensureDirectoryExists($this->rootLocation);
    }

    /**
     * Read the given file.
     *
     * @param string $path The file to read
     *
     * @return string The content of the file
     */
    public function read(string $path): string
    {
        $location = $this->prefixPath($path);
        error_clear_last();
        $contents = @file_get_contents($location);

        if (false === $contents) {
            $errorMessage = error_get_last()['message'] ?? '';

            throw new LocalFileSystemException("Unable to read the file $path. Error: $errorMessage");
        }

        return $contents;
    }

    /**
     * Write the given content to the file.
     *
     * @param string $path     The file path
     * @param string $contents The contents to write
     */
    public function write(string $path, string $contents): void
    {
        $prefixedLocation = $this->prefixPath($path);
        $this->ensureDirectoryExists(dirname($prefixedLocation));
        error_clear_last();

        if (false === @file_put_contents($prefixedLocation, $contents, LOCK_EX)) {
            $errorMessage = error_get_last()['message'] ?? '';

            throw new LocalFileSystemException("Unable to write file $path. Error: $errorMessage");
        }

        $this->setPermissions($prefixedLocation, 0600);
    }

    /**
     * Delete the file.
     *
     * @param string $path The file
     */
    public function delete(string $path): void
    {
        $location = $this->prefixPath($path);

        if (!file_exists($location)) {
            return;
        }

        error_clear_last();

        if (!@unlink($location)) {
            $errorMessage = error_get_last()['message'] ?? '';

            throw new LocalFileSystemException("Unable to delete the file $path. Error: $errorMessage");
        }
    }

    /**
     * Check if the file exists.
     *
     * @param string $location The file
     *
     * @return bool True if the file exists
     */
    public function fileExists(string $location): bool
    {
        $location = $this->prefixPath($location);

        return is_file($location);
    }

    /**
     * Create the directory.
     *
     * @param string $path The directory
     */
    public function createDirectory(string $path): void
    {
        $location = $this->prefixPath($path);

        if (is_dir($location)) {
            $this->setPermissions($location, 0700);

            return;
        }

        error_clear_last();

        if (!@mkdir($location, 0700, true)) {
            $errorMessage = error_get_last()['message'] ?? '';

            throw new LocalFileSystemException("Unable to create the directory $path. Error: $errorMessage");
        }
    }

    /**
     * Delete the directory.
     *
     * @param string $path The directory
     */
    public function deleteDirectory(string $path): void
    {
        $location = $this->prefixPath($path);

        if (!is_dir($location)) {
            return;
        }

        $contents = $this->listDirectoryRecursively($location, \RecursiveIteratorIterator::CHILD_FIRST);

        /** @var \SplFileInfo $file */
        foreach ($contents as $file) {
            if (!$this->deleteFileInfoObject($file)) {
                throw new LocalFileSystemException("Unable to delete the file {$file->getPathname()}");
            }
        }

        unset($contents);

        if (!@rmdir($location)) {
            $errorMessage = error_get_last()['message'] ?? '';

            throw new LocalFileSystemException("Unable to delete the directory $path. Error: $errorMessage");
        }
    }

    /**
     * Check if the directory exists.
     *
     * @param string $location The directory
     *
     * @return bool True if the directory exists
     */
    public function directoryExists(string $location): bool
    {
        $location = $this->prefixPath($location);

        return is_dir($location);
    }

    /**
     * Check if the directory exists.
     *
     * @param string $dirname The dirname to check
     */
    public function ensureDirectoryExists(string $dirname): void
    {
        if (is_dir($dirname)) {
            return;
        }

        error_clear_last();

        if (!@mkdir($dirname, 0700, true)) {
            $mkdirError = error_get_last();
        }

        clearstatcache(true, $dirname);

        if (!is_dir($dirname)) {
            $errorMessage = $mkdirError['message'] ?? '';

            throw new LocalFileSystemException("Unable to create the directory $dirname. Error: $errorMessage");
        }
    }

    /**
     * Get the full path.
     *
     * @param string $path The path to prefix
     *
     * @return string The prefixed path
     */
    private function prefixPath(string $path): string
    {
        return $this->prefix.ltrim($path, '\\/');
    }

    /**
     * Remove the prefix from the full path.
     *
     * @param string $path The path to convert
     *
     * @return string The relative path
     */
    private function stripPrefix(string $path): string
    {
        return substr($path, strlen($this->prefix));
    }

    /**
     * Set the permissions for the location.
     *
     * @param string $location   The location to change
     * @param int    $visibility The new permissions
     */
    private function setPermissions(string $location, int $visibility): void
    {
        error_clear_last();
        if (!@chmod($location, $visibility)) {
            $errorMessage = error_get_last()['message'] ?? '';

            throw new LocalFileSystemException("Unable to set the visibility for {$this->stripPrefix($location)}. Error: $errorMessage");
        }
    }

    /**
     * List all files & directories recursively in the given directory.
     *
     * @param string $path the starting directory
     * @param int    $mode The iteration mode (default to \RecursiveIteratorIterator::SELF_FIRST)
     *
     * @return \Generator The directories
     */
    private function listDirectoryRecursively(string $path, int $mode = \RecursiveIteratorIterator::SELF_FIRST): \Generator
    {
        if (!is_dir($path)) {
            return;
        }

        yield from new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            $mode
        );
    }

    /**
     * Delete the file or directory.
     *
     * @param \SplFileInfo $file the file or directory
     *
     * @return bool True if the file or directory has been deleted
     */
    private function deleteFileInfoObject(\SplFileInfo $file): bool
    {
        switch ($file->getType()) {
            case 'dir':
                return @rmdir((string) $file->getRealPath());
            case 'link':
                return @unlink((string) $file->getPathname());
            default:
                return @unlink((string) $file->getRealPath());
        }
    }
}
