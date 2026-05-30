<?php
declare(strict_types=1);

namespace Passbolt\MultiTenant\Model\Behavior;

use Cake\ORM\Behavior;
use Cake\ORM\Query\SelectQuery;

/**
 * Automatically scopes all find queries to the current organization.
 *
 * Applied to UsersTable, GroupsTable, and ResourcesTable.
 *
 * The current organization ID is stored in a static registry set by
 * TenantIsolationMiddleware at the start of each request. MSP super
 * admins bypass scoping entirely (organization_id = null on their record).
 *
 * Usage:
 *   $this->addBehavior('Passbolt/MultiTenant.TenantScope');
 */
class TenantScopeBehavior extends Behavior
{
    /** @var string|null The organization ID active for this request. */
    private static ?string $currentOrganizationId = null;

    /** @var bool When true, scoping is disabled (used for MSP super admin). */
    private static bool $bypass = false;

    /**
     * Set the active organization for the current request.
     */
    public static function setCurrentOrganization(?string $organizationId): void
    {
        self::$currentOrganizationId = $organizationId;
        self::$bypass = false;
    }

    /**
     * Disable tenant scoping (MSP super admin cross-tenant access).
     */
    public static function bypassScoping(): void
    {
        self::$bypass = true;
        self::$currentOrganizationId = null;
    }

    /**
     * Reset state between requests (test isolation).
     */
    public static function reset(): void
    {
        self::$currentOrganizationId = null;
        self::$bypass = false;
    }

    /**
     * Returns the active organization ID, or null if bypassed.
     */
    public static function getCurrentOrganizationId(): ?string
    {
        return self::$currentOrganizationId;
    }

    /**
     * Inject organization_id condition before every find.
     */
    public function beforeFind(mixed $event, SelectQuery $query, mixed $options): void
    {
        if (self::$bypass || self::$currentOrganizationId === null) {
            return;
        }

        $alias = $this->table()->getAlias();
        $query->where(["{$alias}.organization_id" => self::$currentOrganizationId]);
    }

    /**
     * Stamp organization_id on new records before save.
     */
    public function beforeSave(mixed $event, mixed $entity, mixed $options): void
    {
        if (self::$bypass || self::$currentOrganizationId === null) {
            return;
        }

        if (empty($entity->organization_id)) {
            $entity->set('organization_id', self::$currentOrganizationId);
        }
    }
}
