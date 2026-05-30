<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Per-organization SuperOps integration configuration.
 *
 * Stores the SuperOps API key (encrypted) and sync preferences per org.
 * The superops_customer_id links this org to the corresponding customer
 * record in SuperOps for billing count pushes.
 *
 * superops_sync_log: immutable record of every sync run, used to
 * surface sync health in the MSP dashboard and troubleshoot failures.
 */
class V5130CreateSuperOpsSyncConfigsTable extends AbstractMigration
{
    public function change(): void
    {
        $configs = $this->table('superops_sync_configs', ['id' => false, 'primary_key' => ['id']]);
        $configs
            ->addColumn('id', 'uuid', ['null' => false])
            ->addColumn('organization_id', 'uuid', ['null' => false])
            ->addColumn('api_key_encrypted', 'text', ['null' => false])
            ->addColumn('base_url', 'string', ['limit' => 255, 'null' => false, 'default' => 'https://app.superops.com/api'])
            ->addColumn('superops_customer_id', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('sync_customers', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('sync_technicians', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('sync_assets', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('push_license_count', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('last_synced', 'datetime', ['null' => true])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addColumn('created_by', 'uuid', ['null' => false])
            ->addColumn('modified_by', 'uuid', ['null' => false])
            ->addIndex(['organization_id'], ['unique' => true, 'name' => 'uq_superops_config_org'])
            ->create();

        $log = $this->table('superops_sync_log', ['id' => false, 'primary_key' => ['id']]);
        $log
            ->addColumn('id', 'uuid', ['null' => false])
            ->addColumn('organization_id', 'uuid', ['null' => false])
            ->addColumn('sync_type', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('status', 'string', ['limit' => 10, 'null' => false])
            ->addColumn('records_created', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('records_updated', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('records_skipped', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('error_message', 'text', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['organization_id'], ['name' => 'idx_superops_log_org'])
            ->addIndex(['created'], ['name' => 'idx_superops_log_created'])
            ->create();
    }
}
