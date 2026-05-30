<?php
declare(strict_types=1);

namespace Passbolt\MultiTenant\Model\Entity;

use Cake\ORM\Entity;

/**
 * Represents a single isolated tenant vault.
 *
 * plan_type:
 *   - 'internal'  : the MSP's own vault
 *   - 'client'    : a managed client's vault
 *   - 'reseller'  : another MSP using the platform
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $plan_type
 * @property bool $is_active
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property string|null $created_by
 * @property string|null $modified_by
 * @property \Passbolt\MultiTenant\Model\Entity\OrganizationUser[] $organization_users
 */
class Organization extends Entity
{
    public const PLAN_INTERNAL = 'internal';
    public const PLAN_CLIENT = 'client';
    public const PLAN_RESELLER = 'reseller';

    public const VALID_PLAN_TYPES = [
        self::PLAN_INTERNAL,
        self::PLAN_CLIENT,
        self::PLAN_RESELLER,
    ];

    protected array $_accessible = [
        'name' => true,
        'slug' => true,
        'plan_type' => true,
        'is_active' => true,
    ];
}
