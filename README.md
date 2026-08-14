<div align="center">
    <h1>Outpost</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/zacksmash/outpost"><img src="https://img.shields.io/packagist/v/zacksmash/outpost.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/zacksmash/outpost"><img src="https://img.shields.io/packagist/php-v/zacksmash/outpost.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/zacksmash/outpost"><img src="https://badge.laravel.cloud/badge/zacksmash/outpost?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/zacksmash/outpost/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/zacksmash/outpost/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/zacksmash/outpost"><img src="https://img.shields.io/packagist/dt/zacksmash/outpost.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Spin up any branch of your Laravel app as an isolated instance with its own services and URL.

## Installation

You can install the package via Composer:

```bash
composer require zacksmash/outpost
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="outpost"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="outpost-config"
```

### Publishing and Running the Migrations

```bash
php artisan vendor:publish --tag="outpost-migrations"
php artisan migrate
```

### Publishing the Views

```bash
php artisan vendor:publish --tag="outpost-views"
```

### Publishing the Translations

```bash
php artisan vendor:publish --tag="outpost-lang"
```

### Publishing the Public Assets

```bash
php artisan vendor:publish --tag="outpost-assets"
```

## Usage

<!-- Add a basic usage example here. -->

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Outpost! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Zack Warren](https://github.com/zacksmash)
- [All Contributors](../../contributors)

## License

Outpost is open-sourced software licensed under the [MIT license](LICENSE.md).
