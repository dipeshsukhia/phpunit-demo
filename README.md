# phpunit-demo

A demo project to explain **PHPUnit** — unit testing in a modern PHP 8.3 environment, fully containerized with Docker.

This guide walks you through the project from an empty directory to a green test suite:

1. [Prerequisites](#prerequisites)
2. [Project layout](#project-layout)
3. [Docker setup](#1-docker-setup)
4. [Composer: init, autoload & install](#2-composer-init-autoload--install)
5. [PHPUnit: install & configure](#3-phpunit-install--configure)
6. [Running the tests](#4-running-the-tests)
7. [Troubleshooting](#troubleshooting)

---

## Prerequisites

- [Docker](https://docs.docker.com/get-docker/) and Docker Compose v2 (`docker compose`, not the legacy `docker-compose`)
- Git

Everything else (PHP, Composer, PHPUnit, extensions) lives **inside the container** — you do not need PHP installed on your host.

---

## Project layout

```text
phpunit-demo/
├── Dockerfile              # PHP 8.3 CLI image + extensions + Composer
├── docker-compose.yml      # Defines the `php` service
├── composer.json           # Package metadata, autoloading, dev dependencies
├── phpunit.xml             # PHPUnit configuration
├── src/                    # Application code (namespace: Demo\App)
│   ├── MathHelper.php
│   └── StringUtilities.php
└── tests/                  # Test suite (mirrors src/)
    ├── MathHelperTest.php
    └── StringUtilitiesTest.php
```

The PSR-4 mapping is `Demo\App\ => src/`, so a class `Demo\App\MathHelper` must live at `src/MathHelper.php`.

---

## 1. Docker setup

The environment is defined by two files.

**`Dockerfile`** — builds a PHP 8.3 CLI image with the `pdo`/`pdo_mysql` extensions, the [`pcov`](https://github.com/krakjoe/pcov) coverage driver, and Composer:

```dockerfile
FROM php:8.3-cli-bookworm

RUN apt-get update && apt-get install -y \
    zip unzip git \
    $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_mysql \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app
COPY . /app
```

**`docker-compose.yml`** — defines a single long-running `php` service. The bind mount (`.:/app`) means edits on your host are instantly reflected inside the container, so you don't rebuild to run tests:

```yaml
services:
  php:
    container_name: phpunit_demo
    build: .
    volumes:
      - .:/app
    command: tail -f /dev/null
```

### Build & start

```bash
# Build the image and start the container in the background
docker compose up -d --build

# Confirm the runtime
docker compose exec php php --version   # => PHP 8.3.x
```

### Working inside the container

All subsequent commands run **inside** the `php` service. You can either prefix each command with `docker compose exec php ...`, or open an interactive shell into the running container by its name (`phpunit_demo`, as defined by `container_name` in `docker-compose.yml`):

```bash
docker exec -it phpunit_demo bash
```

### Tearing down

```bash
docker compose down --volumes --remove-orphans
```

---

## 2. Composer: init, autoload & install

[Composer](https://getcomposer.org/) is PHP's dependency manager. It also generates the PSR-4 autoloader that maps namespaces to files.

### Creating `composer.json` from scratch (`composer init`)

If you were starting a brand-new project, you'd run the interactive generator inside the container:

```bash
docker compose exec php composer init
```

It prompts for the package name, description, license, and dependencies, then writes a `composer.json`. This repo already ships one:

```json
{
    "name": "entrata/phpunit-demo",
    "description": "phpunit demo",
    "type": "project",
    "license": "MIT",
    "autoload": {
        "psr-4": {
            "Demo\\App\\": "src/"
        }
    },
    "authors": [
        {
            "name": "Manish Choudhary"
        }
    ],
    "minimum-stability": "dev",
    "require-dev": {
        "phpunit/phpunit": "12.5.x-dev"
    }
}
```

Key sections:

- **`autoload.psr-4`** — maps the `Demo\App\` namespace prefix to the `src/` directory. This is why `new Demo\App\MathHelper()` resolves to `src/MathHelper.php` with no manual `require`.
- **`require-dev`** — development-only dependencies (PHPUnit), excluded from production installs run with `--no-dev`.

### Installing dependencies

```bash
# Install everything defined in composer.json / composer.lock
docker compose exec php composer install
```

This creates the `vendor/` directory (including `vendor/autoload.php` and `vendor/bin/phpunit`).

Useful related commands:

```bash
# Regenerate the autoloader after changing namespaces or the psr-4 map
docker compose exec php composer dump-autoload

# Add a new dev dependency later
docker compose exec php composer require --dev <vendor/package>
```

> **Tip:** `vendor/` should be git-ignored. Commit `composer.json` **and** `composer.lock` so installs are reproducible.

---

## 3. PHPUnit: install & configure

### Installing the PHPUnit package

PHPUnit is declared under `require-dev`, so `composer install` (above) already pulls it in. To add it to a fresh project yourself:

```bash
docker compose exec php composer require --dev phpunit/phpunit
```

Verify the binary:

```bash
docker compose exec php ./vendor/bin/phpunit --version
```

### Configuration (`phpunit.xml`)

PHPUnit reads its configuration from `phpunit.xml` in the project root:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         cacheDirectory=".phpunit.cache"
         executionOrder="depends,defects"
         requireCoverageMetadata="true"
         beStrictAboutCoverageMetadata="true"
         beStrictAboutOutputDuringTests="true"
         displayDetailsOnPhpunitDeprecations="true"
         failOnPhpunitDeprecation="true"
         failOnRisky="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="default">
            <directory>tests</directory>
        </testsuite>
    </testsuites>

    <source ignoreIndirectDeprecations="true" restrictNotices="true" restrictWarnings="true">
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

What the important options do:

| Option | Purpose |
| --- | --- |
| `bootstrap="vendor/autoload.php"` | Loads Composer's autoloader before any test runs. |
| `<testsuites>` | Discovers every `*Test.php` file under `tests/`. |
| `<source>` | Defines which code counts for coverage (the `src/` directory). |
| `requireCoverageMetadata="true"` | Every test **must** declare what it covers, or it's flagged **risky**. |
| `failOnRisky` / `failOnWarning` / `failOnPhpunitDeprecation` | Strict mode — the suite fails on risky tests, warnings, or deprecations. |

At a glance, the configuration breaks down into three concerns — the root-level **configuration options**, the **test location**, and the **source location** used for coverage:

![Annotated phpunit.xml showing configuration options, test location, and source location](docs/images/phpunit-xml-annotated.png)

### Declaring coverage metadata (PHPUnit 10+/12)

Because `requireCoverageMetadata` is enabled, each test class must declare its coverage target. **PHPUnit 12 ignores the legacy `/** @covers */` doc-block** — use the attribute API instead:

```php
<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Demo\App\MathHelper;

#[CoversClass(MathHelper::class)]
class MathHelperTest extends TestCase
{
    public function testMultiply()
    {
        $result = (new MathHelper())->multiply(3, 4);
        $this->assertEquals(12, $result);
    }
}
```

---

## 4. Running the tests

Run the full suite:

```bash
docker compose exec php ./vendor/bin/phpunit
```

Expected output:

```text
PHPUnit 12.5.x by Sebastian Bergmann and contributors.

Runtime:       PHP 8.3.x
Configuration: /app/phpunit.xml

.......                                                             7 / 7 (100%)

Time: 00:00.002, Memory: 14.00 MB

OK (7 tests, 7 assertions)
```

Other common invocations:

```bash
# Run a single test file
docker compose exec php ./vendor/bin/phpunit tests/MathHelperTest.php

# Run one test method by name
docker compose exec php ./vendor/bin/phpunit --filter testDivideByZero

# Verbose test names (testdox)
docker compose exec php ./vendor/bin/phpunit --testdox

# Generate a code-coverage report (pcov is pre-installed in the image)
docker compose exec php ./vendor/bin/phpunit --coverage-text
docker compose exec php ./vendor/bin/phpunit --coverage-html coverage/
```

### Command-line options reference

The most useful PHPUnit CLI flags. Prefix each with `docker compose exec php ./vendor/bin/phpunit` to run it in this project.

| Option | Description | Example |
| --- | --- | --- |
| `--filter` | Runs tests that match the provided filter pattern. | `phpunit --filter testMethodName` |
| `--group` | Runs tests from the specified group(s). | `phpunit --group groupName` |
| `--testdox` | Prints the test names and their statuses in a readable format. | `phpunit --testdox` |
| `--coverage-text` | Generates a text-based code coverage report. | `phpunit --coverage-text` |
| `--coverage-html` | Generates an HTML code coverage report in the specified directory. | `phpunit --coverage-html coverage/` |
| `--configuration` (`-c`) | Specifies a PHPUnit XML configuration file to use. | `phpunit -c phpunit.xml` |
| `--log-junit` | Logs test execution in JUnit XML format. | `phpunit --log-junit log.xml` |
| `--bootstrap` | Specifies a PHP script to include before running tests. | `phpunit --bootstrap bootstrap.php` |
| `--colors` | Adds color to the output for better readability. | `phpunit --colors` |
| `--debug` | Displays debugging information during test execution. | `phpunit --debug` |
| `--stop-on-failure` | Stops the test execution upon the first failure. | `phpunit --stop-on-failure` |
| `--test-suffix` | Only executes test files with the specified suffix. | `phpunit --test-suffix=Test.php` |

![PHPUnit command-line options reference table](docs/images/phpunit-cli-options.png)

> **Note:** `--group` pairs with the `#[Group('name')]` attribute on a test, and `--colors` is most useful locally — CI logs usually strip ANSI codes, so pass `--colors=never` there.

---

## Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| `Class "Demo\App\..." not found` | Namespace doesn't match the PSR-4 map, or autoloader is stale. | Ensure the class namespace matches `composer.json`, then run `composer dump-autoload`. |
| `This test does not define a code coverage target` (risky) | Missing coverage metadata under strict mode. | Add `#[CoversClass(...)]` to the test class (the old `@covers` docblock is ignored in PHPUnit 12). |
| `phpunit: not found` | Dependencies not installed in the container. | Run `docker compose exec php composer install`. |
| Changes not reflected | Editing a stale copy / container not mounting source. | Confirm the `.:/app` volume in `docker-compose.yml`; no rebuild is needed for code changes. |
