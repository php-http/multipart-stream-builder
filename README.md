# PSR-7 Multipart Stream Builder

[![Latest Version](https://img.shields.io/github/release/php-http/multipart-stream-builder.svg?style=flat-square)](https://github.com/php-http/multipart-stream-builder/releases)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Total Downloads](https://img.shields.io/packagist/dt/php-http/multipart-stream-builder.svg?style=flat-square)](https://packagist.org/packages/php-http/multipart-stream-builder)

**A builder for Multipart PSR-7 Streams.**


## Install

Via Composer

``` bash
$ composer require php-http/multipart-stream-builder
```

## Usage

```php
$builder = new MultipartStreamBuilder();
$builder
  ->addResource('foo', $stream)
  ->addResource('bar', fopen($filePath, 'r'), ['filename' => 'bar.png'])
  ->addResource('baz', 'string', ['headers' => ['Content-Type' => 'text/plain']]);

$multipartStream = $builder->build();
$boundary = $builder->getBoundary();

$request = MessageFactoryDiscovery::find()->createRequest(
  'POST',
  'http://example.com',
  ['Content-Type' => 'multipart/form-data; boundary='.$boundary],
  $multipartStream
);
$response = HttpClientDiscovery::find()->sendRequest($request);
```

## Documentation

Please see the [official documentation](http://php-http.readthedocs.org/en/latest/multipart-stream-builder/).


## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) and [CONDUCT](CONDUCT.md) for details.


## Security

If you discover any security related issues, please contact us at [security@php-http.org](mailto:security@php-http.org).


## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
