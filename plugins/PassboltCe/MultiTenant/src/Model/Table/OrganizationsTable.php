<?php
declare(strict_types=1);

namespace Passbolt\MultiTenant\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use Passbolt\MultiTenant\Model\Entity\Organization;

class OrganizationsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('organizations');
        $this->setEntityClass(Organization::class);
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');
        $this->addBehavior('Uuid');

        $this->hasMany('OrganizationUsers', [
            'className' => 'Passbolt/MultiTenant.OrganizationUsers',
            'foreignKey' => 'organization_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->uuid('id')
            ->requirePresence('id', 'create')
            ->notEmptyString('id');

        $validator
            ->scalar('name')
            ->maxLength('name', 255)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->scalar('slug')
            ->maxLength('slug', 100)
            ->requirePresence('slug', 'create')
            ->notEmptyString('slug')
            ->regex('slug', '/^[a-z0-9-]+$/', 'Slug must be lowercase alphanumeric with dashes only.');

        $validator
            ->inList('plan_type', Organization::VALID_PLAN_TYPES)
            ->requirePresence('plan_type', 'create')
            ->notEmptyString('plan_type');

        $validator
            ->boolean('is_active');

        return $validator;
    }

    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['slug']), 'uniqueSlug', [
            'message' => 'This slug is already taken.',
        ]);

        return $rules;
    }

    /**
     * Find an organization by its slug (used for subdomain-based tenant resolution).
     */
    public function findBySlug(string $slug): ?Organization
    {
        return $this->find()
            ->where(['slug' => $slug, 'is_active' => true])
            ->first();
    }
}
