<?php
declare(strict_types=1);

namespace Passbolt\MultiTenant\Model\Table;

use Cake\ORM\Entity;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class OrganizationUserGrantsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('organization_user_grants');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', ['events' => ['Model.beforeSave' => ['created' => 'new']]]);
        $this->addBehavior('Uuid');

        $this->belongsTo('Organizations', [
            'className' => 'Passbolt/MultiTenant.Organizations',
            'foreignKey' => 'organization_id',
        ]);

        $this->belongsTo('Users', [
            'className' => 'Users',
            'foreignKey' => 'user_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->uuid('id')->requirePresence('id', 'create')->notEmptyString('id');
        $validator->uuid('user_id')->requirePresence('user_id', 'create')->notEmptyString('user_id');
        $validator->uuid('organization_id')->requirePresence('organization_id', 'create')->notEmptyString('organization_id');
        $validator->uuid('granted_by')->requirePresence('granted_by', 'create')->notEmptyString('granted_by');
        $validator->dateTime('expires')->allowEmptyDateTime('expires');

        return $validator;
    }
}
