<?php
declare(strict_types=1);

namespace Passbolt\MspEntraId\Model\Table;

use Cake\ORM\Table;

class EntraIdOidcStateTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('entra_id_oidc_state');
        $this->setPrimaryKey('id');
        $this->addBehavior('Uuid');
        $this->addBehavior('Timestamp', ['events' => ['Model.beforeSave' => ['created' => 'new']]]);
    }
}
