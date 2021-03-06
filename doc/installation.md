# Installation

## Requirements
Before installing **Coveralls for PHP**, you need to make sure you have [PHP](https://www.php.net)
and [Composer](https://getcomposer.org), the PHP package manager, up and running.

!!! warning
    Coveralls for PHP requires PHP >= **7.4.0**.

You can verify if you're already good to go with the following commands:

```shell
php --version
# PHP 7.4.1 (cli) (built: Dec 17 2019 19:23:59) ( NTS Visual C++ 2017 x64 )

composer --version
# Composer version 1.9.1 2019-11-01 17:20:17
```

!!! info
    If you plan to play with the package sources, you will also need
    [Robo](https://robo.li) and [Material for MkDocs](https://squidfunk.github.io/mkdocs-material).

## Installing with Composer package manager

### 1. Install it
From a command prompt, run:

```shell
composer require cedx/coveralls
```

### 2. Import it
Now in your [PHP](https://www.php.net) code, you can use:

```php
<?php
use Coveralls\Http\{Client, ClientException};
```
