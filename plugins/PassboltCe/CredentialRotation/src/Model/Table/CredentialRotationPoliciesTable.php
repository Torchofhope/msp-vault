<?php
declare(strict_types=1);

namespace Passbolt\CredentialRotation\Model\Table;

use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use Passbolt\CredentialRotation\Model\Entity\CredentialRotationPolicy;

class CredentialRotationPoliciesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('credential_rotation_policies');
        $this->setEntityClass(CredentialRotationPolicy::class);
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Resources', [
            'className' => 'Resources',
            'foreignKey' => 'resource_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->uuid('id')->requirePresence('id', 'create')->notEmptyString('id');
        $validator->uuid('resource_id')->requirePresence('resource_id', 'create')->notEmptyString('resource_id');
        $validator->inList('rotation_type', CredentialRotationPolicy::VALID_TYPES)->notEmptyString('rotation_type');
        $validator->integer('interval_days')->greaterThan('interval_days', 0)->allowEmptyString('interval_days');
        $validator->boolean('notify_on_rotation');

        return $validator;
    }

    /**
     * Find all scheduled policies that are due for rotation.
     */
    public function findDueForRotation(): iterable
    {
        return $this->find()
            ->where([
                'rotation_type' => CredentialRotationPolicy::TYPE_SCHEDULED,
                'OR' => [
                    'next_rotation IS' => null,
                    'next_rotation <=' => new DateTime(),
                ],
            ])
            ->contain(['Resources'])
            ->all();
    }

    /**
     * Update policy after a successful rotation.
     */
    public function stampRotation(string $resourceId): void
    {
        $policy = $this->find()->where(['resource_id' => $resourceId])->first();
        if ($policy === null) {
            return;
        }

        $now = new DateTime();
        $policy->set('last_rotated', $now);

        if ($policy->rotation_type === CredentialRotationPolicy::TYPE_SCHEDULED && $policy->interval_days) {
            $policy->set('next_rotation', $now->modify("+{$policy->interval_days} days"));
        }

        $this->saveOrFail($policy);
    }
}
