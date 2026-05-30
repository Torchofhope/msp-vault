<?php
declare(strict_types=1);

namespace Passbolt\MultiTenant\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;
use Passbolt\MultiTenant\Model\Entity\OrganizationUser;

class OrganizationUsersTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('organization_users');
        $this->setEntityClass(OrganizationUser::class);
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');
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
        $validator->uuid('organization_id')->allowEmptyString('organization_id');
        $validator->inList('msp_role', OrganizationUser::VALID_MSP_ROLES)->notEmptyString('msp_role');

        return $validator;
    }

    /**
     * Returns the OrganizationUser record for a given user, or null if not found.
     */
    public function findForUser(string $userId): ?OrganizationUser
    {
        return $this->find()
            ->where(['user_id' => $userId])
            ->first();
    }

    /**
     * Returns true if the user is an MSP super admin (not scoped to any org).
     */
    public function isMspSuperAdmin(string $userId): bool
    {
        return $this->exists([
            'user_id' => $userId,
            'msp_role' => OrganizationUser::MSP_SUPER_ADMIN,
        ]);
    }

    /**
     * Returns all org IDs a technician has been granted access to,
     * including their home org and any cross-client grants.
     *
     * @return string[]
     */
    public function getAccessibleOrgIds(string $userId): array
    {
        $home = $this->find()
            ->select(['organization_id'])
            ->where(['user_id' => $userId])
            ->first();

        $orgIds = $home && $home->organization_id ? [$home->organization_id] : [];

        $grants = $this->getTableLocator()
            ->get('Passbolt/MultiTenant.OrganizationUserGrants')
            ->find()
            ->select(['organization_id'])
            ->where(['user_id' => $userId])
            ->where(function ($exp) {
                return $exp->or([
                    'expires IS' => null,
                    'expires >' => new \DateTime(),
                ]);
            })
            ->all()
            ->extract('organization_id')
            ->toArray();

        return array_unique(array_merge($orgIds, $grants));
    }
}
