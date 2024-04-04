<?php
declare(strict_types=1);

namespace Ownership\Model\Behavior;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Association;
use Cake\ORM\Association\BelongsTo;
use Cake\ORM\Association\BelongsToMany;
use Cake\ORM\Behavior;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Exception;
use Ownership\Model\Table\OwnersTableInterface;

/**
 * Ownership behavior
 */
class OwnershipBehavior extends Behavior
{
    /**
     * Default configuration.
     *
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [];

    /**
     * Owner associations.
     *
     * @var array<string, array{owner: ?string, parent: ?string}>
     */
    protected static array $_ownerAssociations = [];

    /**
     * Get the behavior instance.
     *
     * @param string $tableAlias Table alias
     * @return self|null The behavior instance.
     */
    protected static function _getBehavior(string $tableAlias): ?self
    {
        $table = TableRegistry::getTableLocator()->get($tableAlias);
        if ($table->hasBehavior('Ownership')) {
            /** @var self $behavior */
            $behavior = $table->getBehavior('Ownership');

            return $behavior;
        } else {
            return null;
        }
    }

    /**
     * Get the setting from ownerAssociations.
     *
     * @param string $tableAlias Table alias
     * @return array{owner: ?string, parent: ?string} The configuration for the Behavior.
     * @throws \Exception
     */
    protected static function _getOwnerAssocConfig(string $tableAlias): array
    {
        if (!isset(self::$_ownerAssociations[$tableAlias])) {
            $table = TableRegistry::getTableLocator()->get($tableAlias);
            $behavior = static::_getBehavior($tableAlias);

            if (isset($behavior)) {
                $owner = $behavior->getConfig('owner');
                $parent = $behavior->getConfig('parent');
                if (isset($owner) && isset($parent)) {
                    if (!(TableRegistry::getTableLocator()->get($owner) instanceof OwnersTableInterface)) {
                        throw new Exception(sprintf(
                            '%sTable must implement OwnersTableInterface.',
                            $owner
                        ));
                    }
                    if ($table->getAssociation($parent)->type() != Association::MANY_TO_ONE) {
                        throw new Exception(sprintf(
                            '%sTable must have a BelongsTo association with the \'%s\' association key.',
                            $tableAlias,
                            $parent
                        ));
                    }
                } elseif (isset($owner) || isset($parent)) {
                    throw new Exception('Both \'owner\' and \'parent\' must be either set or null.');
                }
            } else {
                $owner = null;
                $parent = null;
            }
            self::$_ownerAssociations[$tableAlias] = ['owner' => $owner, 'parent' => $parent];
        }

        return self::$_ownerAssociations[$tableAlias];
    }

    /**
     * Get the owner table.
     *
     * @return \Cake\ORM\Table&\Ownership\Model\Table\OwnersTableInterface The owner table.
     * @throws \Exception
     */
    protected function _getOwnersTable(): Table&OwnersTableInterface
    {
        $config = static::_getOwnerAssocConfig($this->table()->getAlias());
        if (isset($config['owner'])) {
            /** @var \Cake\ORM\Table&\Ownership\Model\Table\OwnersTableInterface $ownersTable */
            $ownersTable = TableRegistry::getTableLocator()->get($config['owner']);

            return $ownersTable;
        } else {
            throw new Exception('\'owner\' must be set.');
        }
    }

    /**
     * Get the entity of the account who are accessing.
     *
     * @return \Cake\Datasource\EntityInterface|null The entity of the account who are accessing.
     * @throws \Exception
     */
    protected function _getCurrentEntity(): ?EntityInterface
    {
        return $this->_getOwnersTable()->getCurrentEntity();
    }

    /**
     * Get the association path to the owner model.
     *
     * @param string|null $owner The alias of the owner table. If null, the owner of the current
     *  　table is used.
     * @return array|false The association path to the owner model.
     * @throws \Exception
     */
    protected function _getOwnerAssociation(?string $owner = null): array|false
    {
        $config = static::_getOwnerAssocConfig($this->table()->getAlias());
        if (is_null($config['parent'])) {
            return false;
        } elseif (is_null($owner)) {
            $owner = $config['owner'];
        }
        /** @var array{owner: string, parent: string} $config */

        $assoc = $this->table();
        $path = [];
        while ($config['owner'] == $owner) {
            $path[] = $config['parent'];
            $assoc = $assoc->getAssociation($config['parent']);

            /** @var array{owner: string, parent: string} $config */
            $config = static::_getOwnerAssocConfig($assoc->getAlias());
        }

        return $path;
    }

    /**
     * Retrieves the owner ID for the given entity and belongsTo association.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity for which to retrieve the owner ID.
     * @param \Cake\ORM\Association\BelongsTo $belongsTo The belongsTo association.
     * @return array<string,mixed>|false|null If false, the owner ID could not be retrieved.
     * @throws \Exception
     */
    protected function _getOwnerId(EntityInterface $entity, BelongsTo $belongsTo): array|false|null
    {
        $config = static::_getOwnerAssocConfig($this->table()->getAlias());
        if (is_null($config['owner'])) {
            return false;
        }

        if ($belongsTo->getAlias() == $config['owner'] && $belongsTo->getName() == $config['parent']) {
            $ownerAssoc = [];
            $ownerAssocName = $belongsTo->getName();
        } else {
            $behavior = static::_getBehavior($belongsTo->getAlias());
            if (is_null($behavior)) {
                return false;
            }

            $ownerAssoc = $behavior->_getOwnerAssociation($config['owner']);
            if ($ownerAssoc === false || count($ownerAssoc) == 0) {
                return false;
            }
            $ownerAssocName = $ownerAssoc[count($ownerAssoc) - 1];
        }

        $ownerPrimary = [];
        foreach ((array)$this->_getOwnersTable()->getPrimaryKey() as $key) {
            $ownerPrimary[$key] = $ownerAssocName . '.' . $key;
        }

        $conditions = [];
        $nullFKey = false;
        $foreignKey = (array)$belongsTo->getForeignKey();
        $bindingKey = (array)$belongsTo->getBindingKey();
        foreach ((array)array_combine($bindingKey, $foreignKey) as $bKey => $fKey) {
            if (!is_null($entity->{$fKey})) {
                $conditions[$belongsTo->getAlias() . '.' . $bKey] = $entity->{$fKey};
            } elseif ($nullFKey === false) {
                $nullFKey = $fKey;
            }
            if (count($conditions) > 0 && $nullFKey !== false) {
                throw new Exception(sprintf(
                    'Foreign key(%s.%s) must not be null, or all foreign keys need to be set to null.',
                    preg_replace('/^.*\\\\/', '', get_class($entity)),
                    $nullFKey
                ));
            }
        }
        if ($nullFKey !== false) {
            if ($belongsTo->getName() == $config['parent']) {
                return null;
            } else {
                return false;
            }
        }

        $query = $belongsTo->getTarget()->find()->disableHydration();
        if (count($ownerAssoc) > 0) {
            $query->innerJoinWith(implode('.', $ownerAssoc));
        }

        /** @var array<string,mixed>|null $ownerId */
        $ownerId = $query
            ->select($ownerPrimary)
            ->where($conditions)
            ->first();

        return $ownerId;
    }

    /**
     * Retrieves the owner IDs for a given entity and BelongsToMany association.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity for which to retrieve owner IDs.
     * @param \Cake\ORM\Association\BelongsToMany $belongsToMany The BelongsToMany association.
     * @return array<array<string, mixed>>|false If false, the owner IDs could not be retrieved.
     * @throws \Exception
     */
    protected function _getOwnerIds(EntityInterface $entity, BelongsToMany $belongsToMany): array|false
    {
        if ($entity->isNew()) {
            return false;
        }

        $config = static::_getOwnerAssocConfig($this->table()->getAlias());
        if (is_null($config['owner'])) {
            return false;
        }

        $behavior = static::_getBehavior($belongsToMany->getAlias());
        if (is_null($behavior)) {
            return false;
        }

        $ownerAssoc = $behavior->_getOwnerAssociation($config['owner']);
        if ($ownerAssoc === false || count($ownerAssoc) == 0) {
            return false;
        }
        array_unshift($ownerAssoc, $belongsToMany->getName());
        $ownerAssocName = $ownerAssoc[count($ownerAssoc) - 1];

        $ownerPrimary = [];
        foreach ((array)$this->_getOwnersTable()->getPrimaryKey() as $key) {
            $ownerPrimary[$key] = $ownerAssocName . '.' . $key;
        }

        $conditions = [];
        $junction = $belongsToMany->junction();
        $foreignKey = (array)$belongsToMany->getForeignKey();
        $bindingKey = (array)$belongsToMany->getBindingKey();
        foreach ((array)array_combine($bindingKey, $foreignKey) as $bKey => $fKey) {
            if (!is_null($entity->{$bKey})) {
                $conditions[$junction->getAlias() . '.' . $fKey] = $entity->{$bKey};
            } else {
                throw new Exception(sprintf(
                    'Binding key(%s.%s) must not be null.',
                    preg_replace('/^.*\\\\/', '', get_class($entity)),
                    $bKey
                ));
            }
        }

        return $junction->find()
            ->disableHydration()
            ->innerJoinWith(implode('.', $ownerAssoc))
            ->select($ownerPrimary)
            ->where($conditions)
            ->toArray();
    }

    /**
     * Checks if the owner of the entity is consistent with the provided owner ID.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to check the owner for.
     * @param array<string,mixed>|null $ownerId The owner ID to compare with the entity's owner.
     * @return bool Returns true if the owner is consistent, false otherwise.
     * @throws \Exception
     */
    protected function _isOwnerConsistent(EntityInterface $entity, ?array $ownerId): bool
    {
        $config = static::_getOwnerAssocConfig($this->table()->getAlias());
        foreach ($this->table()->associations() as $assoc) {
            $isOwnerAssoc = false;
            if ($config['owner'] == $assoc->getAlias() && $config['parent'] == $assoc->getName()) {
                $isOwnerAssoc = true;
            } elseif ($config['owner'] != static::_getOwnerAssocConfig($assoc->getAlias())['owner']) {
                continue;
            }

            /** @var self $behavior */
            $behavior = static::_getBehavior($assoc->getAlias());
            if ($assoc->type() == Association::MANY_TO_ONE) {
                /** @var \Cake\ORM\Association\BelongsTo $assoc */
                if (
                    isset($entity->{$assoc->getProperty()}) &&
                    $entity->isDirty($assoc->getProperty()) &&
                    !$isOwnerAssoc
                ) {
                    if ($behavior->_isOwnerConsistent($entity->{$assoc->getProperty()}, $ownerId) === false) {
                        return false;
                    }
                } else {
                    if (!in_array($this->_getOwnerId($entity, $assoc), [false, $ownerId])) {
                        return false;
                    }
                }
            } elseif ($assoc->type() == Association::MANY_TO_MANY) {
                /** @var \Cake\ORM\Association\BelongsToMany $assoc */
                if (isset($entity->{$assoc->getProperty()}) && $entity->isDirty($assoc->getProperty())) {
                    foreach ($entity->{$assoc->getProperty()} as $childEntity) {
                        if ($behavior->_isOwnerConsistent($childEntity, $ownerId) === false) {
                            return false;
                        }
                    }
                } else {
                    $btmOwnerIds = $this->_getOwnerIds($entity, $assoc);
                    if ($btmOwnerIds !== false) {
                        foreach ($btmOwnerIds as $btmOwnerId) {
                            if ($btmOwnerId != $ownerId) {
                                return false;
                            }
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * Returns the owner ID of the given entity.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to retrieve the owner ID from.
     * @return array<string,mixed>|false|null If false, the owner IDs could not be retrieved.
     * @throws \Exception
     */
    public function getOwnerId(EntityInterface $entity): array|false|null
    {
        if ($this->table()->getEntityClass() != get_class($entity)) {
            throw new Exception(sprintf(
                'Entity class does not match %s. (%s entity was given.)',
                preg_replace('/^.*\\\\/', '', $this->table()->getEntityClass()),
                preg_replace('/^.*\\\\/', '', get_class($entity))
            ));
        }

        $ownerAssoc = $this->_getOwnerAssociation();
        if ($ownerAssoc === false) {
            return false;
        }

        $assoc = $this->table();
        while (true) {
            /** @var \Cake\ORM\Association\BelongsTo $assoc */
            $assoc = $assoc->getAssociation(array_shift($ownerAssoc));

            if (
                isset($entity->{$assoc->getProperty()}) &&
                $entity->isDirty($assoc->getProperty()) &&
                count($ownerAssoc) > 0
            ) {
                $entity = $entity->{$assoc->getProperty()};
            } else {
                /** @var self $behavior */
                $behavior = static::_getBehavior($assoc->getSource()->getAlias());

                $ownerId = $behavior->_getOwnerId($entity, $assoc);

                return $ownerId;
            }
        }
    }

    /**
     * Checks if the owner of the entity is consistent.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity to check.
     * @return bool Returns true if the owner is consistent, false otherwise.
     * @throws \Exception
     */
    public function isOwnerConsistent(EntityInterface $entity): bool
    {
        $ownerId = $this->getOwnerId($entity);
        if ($ownerId === false) {
            return true;
        }

        return $this->_isOwnerConsistent($entity, $ownerId);
    }

    /**
     * Returns the query with the owner condition.
     *
     * ### Options
     * - `owner_id` The owner ID to find.
     *   If the owner ID is not set, the owner ID of the current entity is used.
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query to find with the owner condition.
     * @param array $options The options for the query.
     * @return \Cake\ORM\Query\SelectQuery The query with the owner condition.
     * @throws \Exception
     */
    public function findOwned(SelectQuery $query, array $options): SelectQuery
    {
        $ownerAssoc = $this->_getOwnerAssociation();
        if ($ownerAssoc === false) {
            return $query;
        }
        $ownerAssocName = $ownerAssoc[count($ownerAssoc) - 1];

        $primaryKey = (array)$this->_getOwnersTable()->getPrimaryKey();
        $currentEntity = $this->_getCurrentEntity();
        if (isset($options['owner_id'])) {
            $ownerId = $options['owner_id'];
            if (!is_array($ownerId)) {
                $ownerId = [$ownerId];
            }
        } elseif (!is_null($currentEntity)) {
            $ownerId = [];
            foreach ($primaryKey as $key) {
                if (!is_null($currentEntity->{$key})) {
                    $ownerId[] = $currentEntity->{$key};
                } else {
                    throw new Exception(sprintf(
                        'Primary key of current entity(%s) must not be null.',
                        $key
                    ));
                }
            }
        } else {
            return $query;
        }

        $ownerKey = [];
        foreach ($primaryKey as $key) {
            $ownerKey[] = $ownerAssocName . '.' . $key;
        }
        if (count($ownerKey) != count($ownerId)) {
            throw new Exception(sprintf(
                'Primary key must be same length. (owner_id length must be %d)',
                count($ownerKey)
            ));
        }
        $conditions = (array)array_combine($ownerKey, $ownerId);

        return $query
            ->innerJoinWith(implode('.', $ownerAssoc))
            ->where($conditions);
    }

    /**
     * Returns the query with the non-owned condition.
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query to find with the non-owned condition.
     * @param array $options The options for the query.
     * @return \Cake\ORM\Query\SelectQuery The query with the non-owned condition.
     * @throws \Exception
     */
    public function findNonOwned(SelectQuery $query, array $options): SelectQuery
    {
        $ownerAssoc = $this->_getOwnerAssociation();
        if ($ownerAssoc === false) {
            return $query;
        }
        $ownerAssocName = $ownerAssoc[count($ownerAssoc) - 1];

        $primaryKey = (array)$this->_getOwnersTable()->getPrimaryKey();
        $conditions = [];
        foreach ($primaryKey as $key) {
            $conditions[$ownerAssocName . '.' . $key . ' IS'] = null;
        }

        return $query
            ->leftJoinWith(implode('.', $ownerAssoc))
            ->where($conditions);
    }

    /**
     * Event handler called before an entity is saved.
     * If the owner is inconsistent, the event is stopped.
     *
     * @param \Cake\Event\EventInterface $event The event.
     * @param \Cake\Datasource\EntityInterface $entity The entity to save.
     * @param \ArrayObject $options The options for the save.
     * @return false|null Returns false if the owner is inconsistent, null otherwise.
     * @throws \Exception
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options)
    {
        if (!$this->isOwnerConsistent($entity)) {
            $event->stopPropagation();

            return false;
        }

        return null;
    }
}
