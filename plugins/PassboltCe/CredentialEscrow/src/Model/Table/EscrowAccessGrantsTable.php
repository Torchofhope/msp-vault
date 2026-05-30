<?php
declare(strict_types=1);

namespace Passbolt\CredentialEscrow\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class EscrowAccessGrantsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('escrow_access_grants');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp', ['events' => ['Model.beforeSave' => ['created' => 'new']]]);

        $this->belongsTo('EscrowRequests', [
            'className' => 'Passbolt/CredentialEscrow.EscrowRequests',
            'foreignKey' => 'escrow_request_id',
        ]);
        $this->belongsTo('Users', ['className' => 'Users', 'foreignKey' => 'user_id']);
        $this->belongsTo('Resources', ['className' => 'Resources', 'foreignKey' => 'resource_id']);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->uuid('id')->requirePresence('id', 'create')->notEmptyString('id');
        $validator->uuid('escrow_request_id')->requirePresence('escrow_request_id', 'create')->notEmptyString('escrow_request_id');
        $validator->uuid('user_id')->requirePresence('user_id', 'create')->notEmptyString('user_id');
        $validator->uuid('resource_id')->requirePresence('resource_id', 'create')->notEmptyString('resource_id');
        $validator->dateTime('access_expires')->requirePresence('access_expires', 'create')->notEmptyDateTime('access_expires');

        return $validator;
    }

    /**
     * Check whether a user has an active (non-expired) escrow grant for a resource.
     */
    public function hasActiveGrant(string $userId, string $resourceId): bool
    {
        return $this->exists([
            'user_id' => $userId,
            'resource_id' => $resourceId,
            'access_expires >' => new \Cake\I18n\DateTime(),
        ]);
    }
}
