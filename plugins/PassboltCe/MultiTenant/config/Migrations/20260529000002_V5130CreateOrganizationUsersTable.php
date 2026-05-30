<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Tracks which users belong to which organization and their MSP-level role.
 *
 * msp_role values:
 *   - msp_super_admin : cross-tenant access, manages all orgs
 *   - client_admin    : admin of their own org only
 *   - technician      : tech who can be granted access to other client orgs
 *   - client_user     : standard user scoped to their org
 *
 * A user with msp_role = 'msp_super_admin' has organization_id = NULL,
 * meaning they are not scoped to any single org.
 *
 * Cross-client grants are stored in organization_user_grants.
 */
class V5130CreateOrganizationUsersTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('organization_users', ['id' => false, 'primary_key' => ['id']]);
        $table
            ->addColumn('id', 'uuid', ['null' => false])
            ->addColumn('user_id', 'uuid', ['null' => false])
            ->addColumn('organization_id', 'uuid', ['null' => true])
            ->addColumn('msp_role', 'string', ['limit' => 30, 'null' => false, 'default' => 'client_user'])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['user_id'], ['name' => 'idx_org_users_user_id'])
            ->addIndex(['organization_id'], ['name' => 'idx_org_users_org_id'])
            ->addIndex(['user_id', 'organization_id'], ['unique' => true, 'name' => 'uq_org_users_user_org'])
            ->create();

        // Cross-client access grants: allows a technician to access another client's vault
        $grants = $this->table('organization_user_grants', ['id' => false, 'primary_key' => ['id']]);
        $grants
            ->addColumn('id', 'uuid', ['null' => false])
            ->addColumn('user_id', 'uuid', ['null' => false])
            ->addColumn('organization_id', 'uuid', ['null' => false])
            ->addColumn('granted_by', 'uuid', ['null' => false])
            ->addColumn('expires', 'datetime', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['user_id'], ['name' => 'idx_org_grants_user_id'])
            ->addIndex(['organization_id'], ['name' => 'idx_org_grants_org_id'])
            ->addIndex(['user_id', 'organization_id'], ['unique' => true, 'name' => 'uq_org_grants_user_org'])
            ->create();
    }
}
