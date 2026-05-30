<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Creates tables for per-credential rotation policies and rotation history.
 *
 * credential_rotation_policies: one row per resource that has a rotation policy.
 *   rotation_type: 'manual' or 'scheduled'
 *   interval_days: for scheduled type, how often to rotate
 *   next_rotation: datetime when next scheduled rotation is due
 *   last_rotated: datetime of last successful rotation
 *   notify_on_rotation: whether to email all users with access on rotation
 *
 * credential_rotation_history: immutable log of every rotation event.
 *   triggered_by: user_id (or null for system/scheduled rotations)
 *   trigger_type: 'manual' | 'scheduled'
 */
class V5130CreateCredentialRotationTables extends AbstractMigration
{
    public function change(): void
    {
        $policies = $this->table('credential_rotation_policies', ['id' => false, 'primary_key' => ['id']]);
        $policies
            ->addColumn('id', 'uuid', ['null' => false])
            ->addColumn('resource_id', 'uuid', ['null' => false])
            ->addColumn('rotation_type', 'string', ['limit' => 10, 'null' => false, 'default' => 'manual'])
            ->addColumn('interval_days', 'integer', ['null' => true, 'default' => null])
            ->addColumn('last_rotated', 'datetime', ['null' => true])
            ->addColumn('next_rotation', 'datetime', ['null' => true])
            ->addColumn('notify_on_rotation', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addColumn('created_by', 'uuid', ['null' => false])
            ->addColumn('modified_by', 'uuid', ['null' => false])
            ->addIndex(['resource_id'], ['unique' => true, 'name' => 'uq_rotation_policy_resource'])
            ->addIndex(['next_rotation'], ['name' => 'idx_rotation_policy_next'])
            ->create();

        $history = $this->table('credential_rotation_history', ['id' => false, 'primary_key' => ['id']]);
        $history
            ->addColumn('id', 'uuid', ['null' => false])
            ->addColumn('resource_id', 'uuid', ['null' => false])
            ->addColumn('secret_revision_id', 'uuid', ['null' => true])
            ->addColumn('triggered_by', 'uuid', ['null' => true])
            ->addColumn('trigger_type', 'string', ['limit' => 10, 'null' => false, 'default' => 'manual'])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['resource_id'], ['name' => 'idx_rotation_history_resource'])
            ->addIndex(['triggered_by'], ['name' => 'idx_rotation_history_user'])
            ->create();
    }
}
