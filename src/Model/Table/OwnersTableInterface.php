<?php
declare(strict_types=1);

namespace Ownership\Model\Table;

use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * OwnerTableInterface
 */
interface OwnersTableInterface
{
    /**
     * Get the entity of the account who are accessing.
     *
     * @return EntityInterface|null
     */
    public function getCurrentEntity(): ?EntityInterface;

    /**
     * Set the entity of the account who are accessing.
     * 
     * @param EntityInterface|null $entity
     * @return void
     */
    public function setCurrentEntity(?EntityInterface $entity): void;
}
