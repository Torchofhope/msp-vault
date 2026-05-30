<?php
declare(strict_types=1);

namespace Passbolt\CredentialEscrow\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;
use Passbolt\CredentialEscrow\Model\Entity\EscrowRequest;

class EscrowRequestsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('escrow_requests');
        $this->setEntityClass(EscrowRequest::class);
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
        $this->addBehavior('Uuid');

        $this->belongsTo('Resources', ['className' => 'Resources', 'foreignKey' => 'resource_id']);
        $this->belongsTo('Requester', [
            'className' => 'Users',
            'bindingKey' => 'requester_id',
            'foreignKey' => 'id',
        ]);
        $this->belongsTo('Reviewer', [
            'className' => 'Users',
            'bindingKey' => 'reviewed_by',
            'foreignKey' => 'id',
        ]);
        $this->hasOne('EscrowAccessGrants', [
            'className' => 'Passbolt/CredentialEscrow.EscrowAccessGrants',
            'foreignKey' => 'escrow_request_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->uuid('id')->requirePresence('id', 'create')->notEmptyString('id');
        $validator->uuid('resource_id')->requirePresence('resource_id', 'create')->notEmptyString('resource_id');
        $validator->uuid('requester_id')->requirePresence('requester_id', 'create')->notEmptyString('requester_id');
        $validator->uuid('organization_id')->requirePresence('organization_id', 'create')->notEmptyString('organization_id');
        $validator->scalar('reason')->requirePresence('reason', 'create')->notEmptyString('reason')->maxLength('reason', 2000);
        $validator->inList('status', EscrowRequest::VALID_STATUSES)->notEmptyString('status');
        $validator->uuid('reviewed_by')->allowEmptyString('reviewed_by');
        $validator->scalar('reviewer_note')->allowEmptyString('reviewer_note');

        return $validator;
    }

    /** Find pending requests for a given org (for admin review list). */
    public function findPendingForOrg(string $orgId): iterable
    {
        return $this->find()
            ->where(['organization_id' => $orgId, 'status' => EscrowRequest::STATUS_PENDING])
            ->contain(['Requester.Profiles', 'Resources'])
            ->orderBy(['created' => 'ASC'])
            ->all();
    }
}
