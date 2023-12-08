<?php
declare(strict_types=1);

namespace Ownership\Model\Behavior;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Association;
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
     * @var array<string, array{is_owner:bool,owner:?string,parent:?string}>
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
     * @return array{is_owner:bool,owner:?string,parent:?string}
     * @throws \Exception
     */
    protected static function _getOwnerAssocConfig(string $tableAlias): array
    {
        if(!isset(static::$_ownerAssociations[$tableAlias])){
            $table = TableRegistry::getTableLocator()->get($tableAlias);
            $behavior = static::_getBehavior($tableAlias);

            if(isset($behavior)){
                $is_owner = $table instanceof OwnersTableInterface;
                $owner = $behavior->getConfig('owner');
                $parent = $behavior->getConfig('parent');
                if(isset($owner) && isset($parent)){
                    if(!(TableRegistry::getTableLocator()->get($owner) instanceof OwnersTableInterface)){
                        throw new \Exception($owner.'Table must implement OwnersTableInterface.');
                    }
                    if(!in_array($table->getAssociation($parent)->type(), [Association::MANY_TO_ONE, Association::MANY_TO_MANY])){
                        throw new \Exception($tableAlias.'Table must have belongsTo or belongsToMany '.$parent.' Association.');
                    }
                }else if(isset($owner) || isset($parent)){
                    throw new \Exception('owner and parent must be set together.');
                }
            }else{
                throw new \Exception($tableAlias.'Table must have OwnershipBehavior.');
            }
            static::$_ownerAssociations[$tableAlias] = ['is_owner' => $is_owner, 'owner' => $owner, 'parent' => $parent];
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
            throw new \Exception('owner must be set.');
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
     * @return array|false
     * @throws \Exception
     */
    protected function _getOwnerAssociation()
    {
        $config = static::_getOwnerAssocConfig($this->table()->getAlias());
        if(is_null($config['parent'])){
            return false;
        }else{
            $assocName = $config['parent'];
        }

        $assoc = $this->table();
        $path = [];
        do{
            $assocName = static::_getOwnerAssocConfig($assoc->getAlias())['parent'];
            $assoc = $assoc->getAssociation($assocName);
            $path[] = $assocName;
        }while(!static::_getOwnerAssocConfig($assoc->getAlias())['is_owner']);
        return $path;
    }

    /**
     * Get the value of the primary key of the owner.
     * 
     * @param EntityInterface $entity
     * @param Association $assoc
     * @return array<string,mixed>|false|null
     * @throws \Exception
     */
    protected function _getOwnerId(EntityInterface $entity, Association $assoc)
    {
        $ownerAssoc = $this->_getOwnerAssociation();
        if($ownerAssoc === false){
            return false;
        }else{
            array_shift($ownerAssoc);
        }

        if(count($ownerAssoc) == 0){
            $ownerAssocName = $assoc->getAlias();
        }else{
            $ownerAssocName = $ownerAssoc[count($ownerAssoc)-1];
        }

        $ownerPrimary = [];
        foreach((array)$this->_getOwnersTable()->getPrimaryKey() as $key){
            $ownerPrimary[$key] = $ownerAssocName.'.'.$key;
        }

        $conditions = [];
        $foreignKey = (array)$assoc->getForeignKey();
        $bindingKey = (array)$assoc->getBindingKey();
        foreach(array_combine($bindingKey, $foreignKey) as $bKey => $fKey){
            if(!is_null($entity->{$fKey})){
                $conditions[$assoc->getAlias().'.'.$bKey] = $entity->{$fKey};
            }else{
                throw new \Exception('Foreign key('.preg_replace('/^.*\\\\/', '', $entity::class).'.'.$fKey.') must not be null.');
            }
        }

        $query = $assoc->getTarget()->find()->disableHydration();
        if(count($ownerAssoc) > 0){
            $query->innerJoinWith(implode('.', $ownerAssoc));
        }
        return $query
            ->select($ownerPrimary)
            ->where($conditions)
            ->first();
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
        $owner = static::_getOwnerAssocConfig($this->table()->getAlias())['owner'];
        foreach($this->table()->associations() as $assoc){
            $behavior = static::_getBehavior($assoc->getAlias());
            if(is_null($behavior)){
                continue;
            }

            $config = static::_getOwnerAssocConfig($assoc->getAlias());
            if(!($config['is_owner'] && $assoc->getAlias() == $owner || $config['owner'] == $owner)){
                continue;
            }

            if($assoc->type() == Association::MANY_TO_ONE){
                if(isset($entity->{$assoc->getProperty()}) && $entity->isDirty($assoc->getProperty())){
                    if($behavior->_isOwnerConsistent($entity->{$assoc->getProperty()}, $ownerId) === false){
                        return false;
                    }
                }else{
                    foreach((array)$assoc->getForeignKey() as $fKey){
                        if(isset($entity->{$fKey})){
                            if($this->_getOwnerId($entity, $assoc) != $ownerId){
                                return false;
                            }else{
                                break;
                            }
                        }else{
                            throw new \Exception('Foreign key('.preg_replace('/^.*\\\\/', '', $entity::class).'.'.$fKey.') must not be null.');
                        }
                    }
                }
            }else if($assoc->type() == Association::MANY_TO_MANY){
                if(isset($entity->{$assoc->getProperty()}) && $entity->isDirty($assoc->getProperty())){
                    foreach($entity->{$assoc->getProperty()} as $childEntity){
                        if($behavior->_isOwnerConsistent($childEntity, $ownerId) === false){
                            return false;
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
            throw new \Exception('Entity class do not match. ('.preg_replace('/^.*\\\\/', '', $entity::class).' entity is given.)');
        }

        $ownerAssoc = $this->_getOwnerAssociation();
        if($ownerAssoc === false){
            return false;
        }

        $assoc = $this->table();
        while(true){
            $assoc = $assoc->getAssociation(array_shift($ownerAssoc));
            if(isset($entity->{$assoc->getProperty()}) && count($ownerAssoc) > 0){
                $entity = $entity->{$assoc->getProperty()};
            }else{
                return static::_getBehavior($assoc->getSource()->getAlias())->_getOwnerId($entity, $assoc);
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

    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options)
    {
        if(!$this->isOwnerConsistent($entity)){
            $event->stopPropagation();
            return false;
        }
    }
}
