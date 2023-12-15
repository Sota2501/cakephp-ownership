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
use Cake\ORM\Query;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
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
    protected $_defaultConfig = [];

    /**
     * Owner associations.
     *
     * @var array<string, array{owner:?string,parent:?string}>
     */
    protected static $_ownerAssociations = [];

    /**
     * Get the behavior object.
     * 
     * @param string $tableAlias
     * @return static|null
     */
    protected static function _getBehavior(string $tableAlias): ?static
    {
        $table = TableRegistry::getTableLocator()->get($tableAlias);
        if($table->hasBehavior('Ownership')){
            return $table->getBehavior('Ownership');
        }else{
            return null;
        }
    }

    /**
     * Get the setting from ownerAssociations.
     * 
     * @param string $tableAlias
     * @return array{owner:?string,parent:?string}
     * @throws \Exception
     */
    protected static function _getOwnerAssocConfig(string $tableAlias): array
    {
        if(!isset(static::$_ownerAssociations[$tableAlias])){
            $table = TableRegistry::getTableLocator()->get($tableAlias);
            $behavior = static::_getBehavior($tableAlias);

            if(isset($behavior)){
                $owner = $behavior->getConfig('owner');
                $parent = $behavior->getConfig('parent');
                if(isset($owner) && isset($parent)){
                    if(!(TableRegistry::getTableLocator()->get($owner) instanceof OwnersTableInterface)){
                        throw new \Exception($owner.'Table must implement OwnersTableInterface.');
                    }
                    if($table->getAssociation($parent)->type() != Association::MANY_TO_ONE){
                        throw new \Exception($tableAlias.'Table must have a BelongsTo association with the \''.$parent.'\' association key.');
                    }
                }else if(isset($owner) || isset($parent)){
                    throw new \Exception('Both \'owner\' and \'parent\' must be either set or null.');
                }
            }else{
                $owner = null;
                $parent = null;
            }
            static::$_ownerAssociations[$tableAlias] = ['owner' => $owner, 'parent' => $parent];
        }
        return static::$_ownerAssociations[$tableAlias];
    }

    /**
     * Get the owner table.
     *
     * @return Table
     * @throws \Exception
     */
    protected function _getOwnersTable(): Table
    {
        $config = static::_getOwnerAssocConfig($this->table()->getAlias());
        if(isset($config['owner'])){
            return TableRegistry::getTableLocator()->get($config['owner']);
        }else{
            throw new \Exception('\'owner\' must be set.');
        }
    }

    /**
     * Get the entity of the account who are accessing.
     *
     * @return EntityInterface|null
     * @throws \Exception
     */
    protected function _getCurrentEntity(): ?EntityInterface
    {
        return $this->_getOwnersTable()->getCurrentEntity();
    }

    /**
     * Get the association path to the owner model.
     *
     * @param string|null $owner
     * @return array|false
     * @throws \Exception
     */
    protected function _getOwnerAssociation(string $owner = null)
    {
        $config = static::_getOwnerAssocConfig($this->table()->getAlias());
        if(is_null($config['parent'])){
            return false;
        }else if(is_null($owner)){
            $owner = $config['owner'];
        }

        $assoc = $this->table();
        $path = [];
        while($config['owner'] == $owner){
            $path[] = $config['parent'];
            $assoc = $assoc->getAssociation($config['parent']);
            $config = static::_getOwnerAssocConfig($assoc->getAlias());
        }
        return $path;
    }

    /**
     * Get the value of the primary key of the owner.
     * 
     * @param EntityInterface $entity
     * @param BelongsTo $belongsTo
     * @return array<string,mixed>|false|null
     * @throws \Exception
     */
    protected function _getOwnerId(EntityInterface $entity, BelongsTo $belongsTo)
    {
        $config = static::_getOwnerAssocConfig($this->table()->getAlias());
        if(is_null($config['owner'])){
            return false;
        }

        if($belongsTo->getAlias() == $config['owner'] && $belongsTo->getName() == $config['parent']){
            $ownerAssoc = [];
            $ownerAssocName = $belongsTo->getName();
        }else{
            $behavior = static::_getBehavior($belongsTo->getAlias());
            if(is_null($behavior)){
                return false;
            }

            $ownerAssoc = $behavior->_getOwnerAssociation($config['owner']);
            if($ownerAssoc === false || count($ownerAssoc) == 0){
                return false;
            }
            $ownerAssocName = $ownerAssoc[count($ownerAssoc)-1];
        }

        $ownerPrimary = [];
        foreach((array)$this->_getOwnersTable()->getPrimaryKey() as $key){
            $ownerPrimary[$key] = $ownerAssocName.'.'.$key;
        }

        $conditions = [];
        $nullFKey = false;
        $foreignKey = (array)$belongsTo->getForeignKey();
        $bindingKey = (array)$belongsTo->getBindingKey();
        foreach(array_combine($bindingKey, $foreignKey) as $bKey => $fKey){
            if(!is_null($entity->{$fKey})){
                $conditions[$belongsTo->getAlias().'.'.$bKey] = $entity->{$fKey};
            }else if($nullFKey === false){
                $nullFKey = $fKey;
            }
            if(count($conditions) > 0 && $nullFKey !== false){
                throw new \Exception('Foreign key('.preg_replace('/^.*\\\\/', '', $entity::class).'.'.$nullFKey.') must not be null, or all foreign keys need to be set to null.');
            }
        }
        if($nullFKey !== false){
            if($belongsTo->getName() == $config['parent']){
                return null;
            }else{
                return false;
            }
        }

        $query = $belongsTo->getTarget()->find()->disableHydration();
        if(count($ownerAssoc) > 0){
            $query->innerJoinWith(implode('.', $ownerAssoc));
        }
        return $query
            ->select($ownerPrimary)
            ->where($conditions)
            ->first();
    }

    /**
     * Get the value of the primary key of the owner.
     *
     * @param EntityInterface $entity
     * @param BelongsToMany $belongsToMany
     * @return array<string,mixed>[]|false
     */
    protected function _getOwnerIds(EntityInterface $entity, BelongsToMany $belongsToMany)
    {
        if($entity->isNew()){
            return false;
        }

        $config = static::_getOwnerAssocConfig($this->table()->getAlias());
        if(is_null($config['owner'])){
            return false;
        }
        
        $behavior = static::_getBehavior($belongsToMany->getAlias());
        if(is_null($behavior)){
            return false;
        }

        $ownerAssoc = $behavior->_getOwnerAssociation($config['owner']);
        if($ownerAssoc === false || count($ownerAssoc) == 0){
            return false;
        }
        array_unshift($ownerAssoc, $belongsToMany->getName());
        $ownerAssocName = $ownerAssoc[count($ownerAssoc)-1];

        $ownerPrimary = [];
        foreach((array)$this->_getOwnersTable()->getPrimaryKey() as $key){
            $ownerPrimary[$key] = $ownerAssocName.'.'.$key;
        }

        $conditions = [];
        $junction = $belongsToMany->junction();
        $foreignKey = (array)$belongsToMany->getForeignKey();
        $bindingKey = (array)$belongsToMany->getBindingKey();
        foreach(array_combine($bindingKey, $foreignKey) as $bKey => $fKey){
            if(!is_null($entity->{$bKey})){
                $conditions[$junction->getAlias().'.'.$fKey] = $entity->{$bKey};
            }else{
                throw new \Exception('Binding key('.preg_replace('/^.*\\\\/', '', $entity::class).'.'.$bKey.') must not be null.');
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
     * Get whether the ownership is consistent.
     * 
     * @param EntityInterface $entity
     * @param array<string,mixed>|null $ownerId
     * @return bool
     * @throws \Exception
     */
    protected function _isOwnerConsistent(EntityInterface $entity, ?array $ownerId): bool
    {
        $config = static::_getOwnerAssocConfig($this->table()->getAlias());
        foreach($this->table()->associations() as $assoc){
            $isOwnerAssoc = false;
            if($config['owner'] == $assoc->getAlias() && $config['parent'] == $assoc->getName()){
                $isOwnerAssoc = true;
            }else if($config['owner'] != static::_getOwnerAssocConfig($assoc->getAlias())['owner']){
                continue;
            }

            $behavior = static::_getBehavior($assoc->getAlias());
            if($assoc->type() == Association::MANY_TO_ONE){
                if(isset($entity->{$assoc->getProperty()}) && $entity->isDirty($assoc->getProperty()) && !$isOwnerAssoc){
                    if($behavior->_isOwnerConsistent($entity->{$assoc->getProperty()}, $ownerId) === false){
                        return false;
                    }
                }else{
                    if(!in_array($this->_getOwnerId($entity, $assoc), [false, $ownerId])){
                        return false;
                    }
                }
            }else if($assoc->type() == Association::MANY_TO_MANY){
                if(isset($entity->{$assoc->getProperty()}) && $entity->isDirty($assoc->getProperty())){
                    foreach($entity->{$assoc->getProperty()} as $childEntity){
                        if($behavior->_isOwnerConsistent($childEntity, $ownerId) === false){
                            return false;
                        }
                    }
                }else{
                    $btmOwnerIds = $this->_getOwnerIds($entity, $assoc);
                    if($btmOwnerIds !== false){
                        foreach($btmOwnerIds as $btmOwnerId){
                            if($btmOwnerId != $ownerId){
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
     * Get the value of the primary key of the owner from entity.
     * 
     * @param EntityInterface $entity
     * @return array<string,mixed>|false|null
     * @throws \Exception
     */
    public function getOwnerId(EntityInterface $entity)
    {
        if($this->table()->getEntityClass() != $entity::class){
            $thisEntityClass = preg_replace('/^.*\\\\/', '', $this->table()->getEntityClass());
            $entityClass = preg_replace('/^.*\\\\/', '', $entity::class);
            throw new \Exception('Entity class does not match '.$thisEntityClass.'. ('.$entityClass.' entity was given.)');
        }

        $ownerAssoc = $this->_getOwnerAssociation();
        if($ownerAssoc === false){
            return false;
        }

        $assoc = $this->table();
        while(true){
            $assoc = $assoc->getAssociation(array_shift($ownerAssoc));
            if(isset($entity->{$assoc->getProperty()}) && $entity->isDirty($assoc->getProperty()) && count($ownerAssoc) > 0){
                $entity = $entity->{$assoc->getProperty()};
            }else{
                $ownerId = static::_getBehavior($assoc->getSource()->getAlias())->_getOwnerId($entity, $assoc);
                return $ownerId;
            }
        }
    }

    /**
     * Get whether the ownership is consistent.
     * 
     * @param EntityInterface $entity
     * @return bool
     * @throws \Exception
     */
    public function isOwnerConsistent(EntityInterface $entity): bool
    {
        $ownerId = $this->getOwnerId($entity);
        if($ownerId === false){
            return true;
        }

        return $this->_isOwnerConsistent($entity, $ownerId);
    }

    /**
     * Get the entity that has ownership (assuming that the ownership of the association is consistent).
     *
     * @param Query $query
     * @param array $options
     * @return Query
     * @throws \Exception
     */
    public function findOwned(Query $query, array $options): Query
    {
        $primaryKey = (array)$this->_getOwnersTable()->getPrimaryKey();

        $currentEntity = $this->_getCurrentEntity();
        if(isset($options['owner_id'])){
            $ownerId = $options['owner_id'];
            if(!is_array($ownerId)){
                $ownerId = [$ownerId];
            }
        }else if(!is_null($currentEntity)){
            $ownerId = [];
            foreach($primaryKey as $key){
                if(!is_null($currentEntity->{$key})){
                    $ownerId[] = $currentEntity->{$key};
                }else{
                    throw new \Exception('Primary key of current entity('.$key.') must not be null.');
                }
            }
        }else{
            return $query;
        }

        $ownerAssoc = $this->_getOwnerAssociation();
        if($ownerAssoc === false){
            return $query;
        }
        $ownerAssocName = $ownerAssoc[count($ownerAssoc)-1];

        $ownerKey = [];
        foreach($primaryKey as $key){
            $ownerKey[] = $ownerAssocName.'.'.$key;
        }
        if(count($ownerKey) != count($ownerId)){
            throw new \Exception('Primary key must be same length. (owner_id length must be '.count($ownerKey).')');
        }
        $conditions = array_combine($ownerKey, $ownerId);

        return $query
            ->innerJoinWith(implode('.', $ownerAssoc))
            ->where($conditions);
    }

    /**
     * Get the entity that does not have ownership (assuming that the ownership of the association is consistent).
     *
     * @param Query $query
     * @param array $options
     * @return Query
     * @throws \Exception
     */
    public function findNonOwned(Query $query, array $options): Query
    {
        $primaryKey = (array)$this->_getOwnersTable()->getPrimaryKey();

        $ownerAssoc = $this->_getOwnerAssociation();
        if($ownerAssoc === false){
            return $query;
        }
        $ownerAssocName = $ownerAssoc[count($ownerAssoc)-1];

        $conditions = [];
        foreach($primaryKey as $key){
            $conditions[$ownerAssocName.'.'.$key.' IS'] = null;
        }

        return $query
            ->leftJoinWith(implode('.', $ownerAssoc))
            ->where($conditions);
    }

    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options)
    {
        if(!$this->isOwnerConsistent($entity)){
            $event->stopPropagation();
            return false;
        }
    }
}
