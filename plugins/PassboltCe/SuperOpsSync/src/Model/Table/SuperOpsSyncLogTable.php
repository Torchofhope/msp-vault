<?php
declare(strict_types=1);

namespace Passbolt\SuperOpsSync\Model\Table;

use Cake\ORM\Table;

class SuperOpsSyncLogTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('superops_sync_log');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp', ['events' => ['Model.beforeSave' => ['created' => 'new']]]);
    }
}
