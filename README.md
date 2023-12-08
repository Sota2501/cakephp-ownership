# Ownership plugin for CakePHP

## Installation

You can install this plugin into your CakePHP application using [composer](https://getcomposer.org).

The recommended way to install composer packages is:

```
composer require sota2501/cakephp-ownership
```

Load the plugin by adding the following statement in your project's
`src/Application.php`:
```php
public function bootstrap(): void
{
    parent::bootstrap();

    $this->addPlugin('Ownership');
}
```
