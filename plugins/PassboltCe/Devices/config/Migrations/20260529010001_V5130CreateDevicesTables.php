<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Creates devices and device_credentials tables.
 *
 * Devices represent any network-connected asset (workstations, servers,
 * switches, firewalls, routers, printers, NAS, cameras, etc.).
 * Each device belongs to an organization (tenant-scoped).
 *
 * device_credentials is a join table linking a resource (credential)
 * to a device, allowing a device profile page to list all its credentials.
 */
class V5130CreateDevicesTables extends AbstractMigration
{
    public function change(): void
    {
        $devices = $this->table('devices', ['id' => false, 'primary_key' => ['id']]);
        $devices
            ->addColumn('id', 'uuid', ['null' => false])
            ->addColumn('organization_id', 'uuid', ['null' => false])
            ->addColumn('name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('device_type', 'string', ['limit' => 30, 'null' => false, 'default' => 'other'])
            ->addColumn('hostname', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('operating_system', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('manufacturer', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('model', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('serial_number', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('notes', 'text', ['null' => true])
            ->addColumn('superops_asset_id', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('deleted', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addColumn('created_by', 'uuid', ['null' => false])
            ->addColumn('modified_by', 'uuid', ['null' => false])
            ->addIndex(['organization_id'], ['name' => 'idx_devices_org_id'])
            ->addIndex(['device_type'], ['name' => 'idx_devices_type'])
            ->addIndex(['superops_asset_id'], ['name' => 'idx_devices_superops_id'])
            ->create();

        $links = $this->table('device_credentials', ['id' => false, 'primary_key' => ['id']]);
        $links
            ->addColumn('id', 'uuid', ['null' => false])
            ->addColumn('device_id', 'uuid', ['null' => false])
            ->addColumn('resource_id', 'uuid', ['null' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('created_by', 'uuid', ['null' => false])
            ->addIndex(['device_id'], ['name' => 'idx_dev_creds_device_id'])
            ->addIndex(['resource_id'], ['name' => 'idx_dev_creds_resource_id'])
            ->addIndex(['device_id', 'resource_id'], ['unique' => true, 'name' => 'uq_dev_creds_device_resource'])
            ->create();
    }
}
