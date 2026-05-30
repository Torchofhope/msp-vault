<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Adds organization_id to users, groups, and resources tables.
 *
 * NULL organization_id on users = MSP super admin (not scoped to any org).
 * All other records must belong to an org for tenant isolation to work.
 */
class V5130AddOrganizationIdToCoreTables extends AbstractMigration
{
    public function change(): void
    {
        // users
        $this->table('users')
            ->addColumn('organization_id', 'uuid', [
                'null' => true,
                'default' => null,
                'after' => 'role_id',
            ])
            ->addIndex(['organization_id'], ['name' => 'idx_users_organization_id'])
            ->update();

        // groups
        $this->table('groups')
            ->addColumn('organization_id', 'uuid', [
                'null' => true,
                'default' => null,
                'after' => 'name',
            ])
            ->addIndex(['organization_id'], ['name' => 'idx_groups_organization_id'])
            ->update();

        // resources
        $this->table('resources')
            ->addColumn('organization_id', 'uuid', [
                'null' => true,
                'default' => null,
                'after' => 'resource_type_id',
            ])
            ->addIndex(['organization_id'], ['name' => 'idx_resources_organization_id'])
            ->update();
    }
}
