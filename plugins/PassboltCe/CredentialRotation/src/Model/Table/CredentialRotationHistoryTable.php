<?php
declare(strict_types=1);

namespace Passbolt\CredentialRotation\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class CredentialRotationHistoryTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('credential_rotation_history');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp', ['events' => ['Model.beforeSave' => ['created' => 'new']]]);

        $this->belongsTo('Resources', ['className' => 'Resources', 'foreignKey' => 'resource_id']);
        $this->belongsTo('TriggeredBy', [
            'className' => 'Users',
            'bindingKey' => 'triggered_by',
            'foreignKey' => 'id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->uuid('id')->requirePresence('id', 'create')->notEmptyString('id');
        $validator->uuid('resource_id')->requirePresence('resource_id', 'create')->notEmptyString('resource_id');
        $validator->uuid('triggered_by')->allowEmptyString('triggered_by');
        $validator->inList('trigger_type', ['manual', 'scheduled'])->notEmptyString('trigger_type');

        return $validator;
    }

    /**
     * Record a rotation event.
     */
    public function logRotation(string $resourceId, ?string $triggeredBy, string $triggerType, ?string $secretRevisionId = null): void
    {
        $record = $this->newEntity([
            'resource_id' => $resourceId,
            'triggered_by' => $triggeredBy,
            'trigger_type' => $triggerType,
            'secret_revision_id' => $secretRevisionId,
        ], ['accessibleFields' => ['*' => true]]);

        $this->saveOrFail($record);
    }
}
