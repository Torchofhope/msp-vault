<?php
declare(strict_types=1);

namespace Passbolt\MultiTenant\Model\Entity;

use Cake\ORM\Entity;

/**
 * Links a user to an organization with an MSP-level role.
 *
 * msp_role constants define what a user can do across the platform:
 *   - MSP_SUPER_ADMIN : bypasses tenant isolation, manages all orgs
 *   - CLIENT_ADMIN    : admin within their single org
 *   - TECHNICIAN      : tech who can be granted cross-client access
 *   - CLIENT_USER     : standard user scoped to their org
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $organization_id
 * @property string $msp_role
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property \Passbolt\MultiTenant\Model\Entity\Organization $organization
 */
class OrganizationUser extends Entity
{
    public const MSP_SUPER_ADMIN = 'msp_super_admin';
    public const CLIENT_ADMIN = 'client_admin';
    public const TECHNICIAN = 'technician';
    public const CLIENT_USER = 'client_user';

    public const VALID_MSP_ROLES = [
        self::MSP_SUPER_ADMIN,
        self::CLIENT_ADMIN,
        self::TECHNICIAN,
        self::CLIENT_USER,
    ];

    protected array $_accessible = [
        'msp_role' => true,
        'organization_id' => true,
        'user_id' => true,
    ];
}
