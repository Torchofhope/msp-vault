<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Creates the organizations table for multi-tenant client vault isolation.
 *
 * Each organization represents one isolated vault — either the MSP itself
 * (plan_type = 'internal') or a client/reseller tenant.
 */
class V5130CreateOrganizationsTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('organizations', ['id' => false, 'primary_key' => ['id']]);
        $table
            ->addColumn('id', 'uuid', ['null' => false])
            ->addColumn('name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('slug', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('plan_type', 'string', ['limit' => 20, 'null' => false, 'default' => 'client'])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addColumn('created_by', 'uuid', ['null' => true])
            ->addColumn('modified_by', 'uuid', ['null' => true])
            ->addIndex(['slug'], ['unique' => true, 'name' => 'idx_organizations_slug'])
            ->addIndex(['is_active'], ['name' => 'idx_organizations_is_active'])
            ->create();
    }
}
