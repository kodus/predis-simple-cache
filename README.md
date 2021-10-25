kodus/predis-simple-cache
=========================

A lightweight bridge from [predis/predis](https://packagist.org/packages/predis/predis) to the 
[PSR-16 simple-cache interface](https://www.php-fig.org/psr/psr-16/)

## Installation

The library is distributed as a composer package.

```
composer require kodus/predis-simple-cache
```

## Usage

Bootstrapping the cache class is very simple. The `PredisSimpleCache` constructor requires the predis client to store
the cache items and a default TTL integer value.

In the example below the cache is constructed with a client with no custom settings and a default TTL of an hour.

```php
<?php
$client = new \Predis\Client();
$cache = new Kodus\PredisSimpleCache\PredisSimpleCache($client, 60 * 60);
```
