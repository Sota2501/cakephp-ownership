# Ownership plugin for CakePHP

The Ownership Plugin is a plugin designed to introduce ownership functionality (distinct from user functionality). The key distinction lies in ensuring that the owner of an entity matches the owner of the associated entity. It is important to note, however, that guaranteeing this ownership match may result in an increased number of SELECT executions.

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

## Ownership Setup

You must implement the OwnersTableInterface in the OwnersTable (ex: UsersTable). The following is an example, but it is acceptable to implement it as is.
```php
use Ownership\Model\Table\OwnersTableInterface;

class UsersTable extends Table implements OwnersTableInterface
{
    protected $currentEntity = null;

    public function getCurrentEntity(): ?EntityInterface
    {
        return $this->currentEntity;
    }

    public function setCurrentEntity(?EntityInterface $entity): void
    {
        $this->currentEntity = $entity;
    }
}
```

At least, you must add the OwnershipBehavior to the table that introduces ownership (it is not necessary to add it to either the owner model or the model that does not introduce ownership). For options, specify the owner model name in 'owner' and the association key name to the owner model in 'parent'.
```php
// ex) ArticleItemsTable => Articles => ( ... => ) Users
class ArticleItemsTable extends Table
{
    public function initialize(array $config): void
    {
        // ...
        $this->addBehavior('Ownership.Ownership', ['owner' => 'Users', 'parent' => 'Articles']);

        $this->belongsTo('Articles', [
            'foreignKey' => 'article_id',
        ]);
        // ...
    }
}
```

You can add OwnershipBehavior without specifying any options when not introducing ownership.

## Usage

The consistency guarantee provided by this plugin is automatically performed in beforeSave event and does not provide a way to avoid this behavior.

This plugin provides a finder to get entities owned by a specific user. Additionally, it also offers a finder for entities that are not owned, even if ownership has been introduced.
```php
$article = $articles->find('owned', ['owner_id' => 1])->first();
$article = $articles->find('nonOwned')->first();
```

By utilizing methods implemented in the owner model, especially by setting the logged-in user, you can get entities owned only by the currently logged-in user without specifying the owner_id. Note that at least one of them must be specified, but if both are specified, the owner_id takes precedence.

In addition to the above functionality, the OwnershipBehavior provides two functions:
```php
$id = $articles->getOwnerId($article);
$isConsistent = $articles->isOwnerConsistent($article);
```

The first function gets the owner's ID in the form of an array `[field_name => ID]`. It returns null if the entity is not owned and false if ownership has not been introduced.

The second function is used to check the consistency of the owner.

## License

The Ownership Plugin is licensed under the MIT License.
