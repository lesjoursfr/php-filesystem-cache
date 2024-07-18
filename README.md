# Filesystem PSR-6 Cache

This is a PSR-6 cache implementation using Filesystem.
This project is a fork of the original PHP Cache cache/filesystem-adapter project.

### Installation

```
composer install lesjoursfr/filesystem-cache
```

### Development only

To install the Symphony PHP CS you have to run the following commands (assuming you have downloaded [composer.phar](https://getcomposer.org/)) :

```
php composer.phar install
vendor/bin/phpcs --config-set installed_paths vendor/escapestudios/symfony2-coding-standard
```

Then you can check the code style with the following command

```
vendor/squizlabs/php_codesniffer/bin/phpcs --standard=./phpcs.xml --no-cache --parallel=1 ./src ./tests
```

You can analyse the project with PHPStan

```
vendor/bin/phpstan analyse
```

You can run PHPUnit Tests

```
vendor/bin/phpunit
```
