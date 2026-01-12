# sir-cli

A command-line application built with [Laravel Zero](https://laravel-zero.com/).

## Installation

Download the application:

```bash
git clone https://github.com/sir-sh/sir-cli.git
cd sir-cli
composer install
```

## Usage

Run the application:

```bash
php application
```

To see all available commands:

```bash
php application list
```

## Testing

Run the test suite using Pest:

```bash
./vendor/bin/pest
```

## Code Style

Check code style using Laravel Pint:

```bash
./vendor/bin/pint --test
```

Fix code style automatically:

```bash
./vendor/bin/pint
```

## Building

Build a standalone executable using Box:

```bash
php application app:build
```

## CI/CD

This project includes GitHub Actions workflows for automated testing across multiple PHP versions and operating systems.