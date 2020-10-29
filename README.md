<h1 align="center">Flysystem QCloud COS</h1>

<p align="center">:floppy_disk: Flysystem adapter for the Qcloud COS storage.</p>

<p align="center">
<a href="https://travis-ci.org/overtrue/flysystem-cos"><img src="https://travis-ci.org/overtrue/flysystem-cos.svg?branch=master" alt="Build Status"></a>
<a href="https://packagist.org/packages/overtrue/flysystem-cos"><img src="https://poser.pugx.org/overtrue/flysystem-cos/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/overtrue/flysystem-cos"><img src="https://poser.pugx.org/overtrue/flysystem-cos/v/unstable.svg" alt="Latest Unstable Version"></a>
<a href="https://scrutinizer-ci.com/g/overtrue/flysystem-cos/?branch=master"><img src="https://scrutinizer-ci.com/g/overtrue/flysystem-cos/badges/quality-score.png?b=master" alt="Scrutinizer Code Quality"></a>
<a href="https://scrutinizer-ci.com/g/overtrue/flysystem-cos/?branch=master"><img src="https://scrutinizer-ci.com/g/overtrue/flysystem-cos/badges/coverage.png?b=master" alt="Code Coverage"></a>
<a href="https://packagist.org/packages/overtrue/flysystem-cos"><img src="https://poser.pugx.org/overtrue/flysystem-cos/downloads" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/overtrue/flysystem-cos"><img src="https://poser.pugx.org/overtrue/flysystem-cos/license" alt="License"></a>
</p>


## Requirement

* PHP >= 7.4

## Installation

```shell
$ composer require overtrue/flysystem-cos -vvv
```

## Usage

```php
use League\Flysystem\Filesystem;
use Overtrue\Flysystem\Cos\CosAdapter;

$config = [
    // 必填，app_id、secret_id、secret_key 
    // 可在个人秘钥管理页查看：https://console.cloud.tencent.com/capi
    'app_id' => 10020201024, 
    'secret_id' => 'AKIDsiQzQla780mQxLLU2GJCxxxxxxxxxxx', 
    'secret_key' => 'b0GMH2c2NXWKxPhy77xhHgwxxxxxxxxxxx',

    'region' => 'ap-guangzhou', 
    'bucket' => 'example',
];

$adapter = new CosAdapter($config);

$flysystem = new League\Flysystem\Filesystem($adapter);

```
## API

```php

bool $flysystem->write('file.md', 'contents');

bool $flysystem->write('file.md', 'http://httpbin.org/robots.txt', ['mime' => 'application/redirect302']);

bool $flysystem->writeStream('file.md', fopen('path/to/your/local/file.jpg', 'r'));

bool $flysystem->update('file.md', 'new contents');

bool $flysystem->updateStream('file.md', fopen('path/to/your/local/file.jpg', 'r'));

bool $flysystem->rename('foo.md', 'bar.md');

bool $flysystem->copy('foo.md', 'foo2.md');

bool $flysystem->delete('file.md');

bool $flysystem->has('file.md');

string|mixed|false $flysystem->read('file.md');

array $flysystem->listContents();

array|false $flysystem->getMetadata('file.md');

int $flysystem->getSize('file.md');

string $flysystem->getMimetype('file.md');

int $flysystem->getTimestamp('file.md');
```

## Plugins

TODO

## License

MIT
