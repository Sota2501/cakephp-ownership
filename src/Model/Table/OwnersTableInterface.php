<?php
declare(strict_types=1);

namespace Ownership\Model\Table;

use Cake\Datasource\EntityInterface;

/**
 * OwnerTableInterface
 */
interface OwnersTableInterface
{
    /**
     * Get the entity of the account who are accessing.
     *
     * @return \Cake\Datasource\EntityInterface|null
     */
    public function getCurrentEntity(): ?EntityInterface;

    /**
     * Set the entity of the account who are accessing.
     *
     * @param \Cake\Datasource\EntityInterface|null $entity Entity
     * @return void
     */
    public function setCurrentEntity(?EntityInterface $entity): void;
}
