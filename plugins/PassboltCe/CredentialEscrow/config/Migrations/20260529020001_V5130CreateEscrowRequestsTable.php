<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Creates the escrow_requests table for the break-glass workflow.
 *
 * Workflow:
 *   1. Tech submits request (status = 'pending')
 *   2. All org admins are notified
 *   3. An admin approves or denies (status = 'approved' | 'denied')
 *   4. On approval: a time-limited permission grant is created and the
 *      tech is notified. The grant expires at 'access_expires'.
 *   5. Every state change is immutable in this table — no updates, only inserts.
 *
 * escrow_access_grants: tracks the temporary permission window granted
 * after an escrow request is approved.
 */
class V5130CreateEscrowRequestsTable extends AbstractMigration
{
    public function change(): void
    {
        $requests = $this->table('escrow_requests', ['id' => false, 'primary_key' => ['id']]);
        $requests
            ->addColumn('id', 'uuid', ['null' => false])
            ->addColumn('resource_id', 'uuid', ['null' => false])
            ->addColumn('requester_id', 'uuid', ['null' => false])
            ->addColumn('organization_id', 'uuid', ['null' => false])
            ->addColumn('reason', 'text', ['null' => false])
            ->addColumn('status', 'string', ['limit' => 10, 'null' => false, 'default' => 'pending'])
            ->addColumn('reviewed_by', 'uuid', ['null' => true])
            ->addColumn('reviewed_at', 'datetime', ['null' => true])
            ->addColumn('reviewer_note', 'text', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['resource_id'], ['name' => 'idx_escrow_resource'])
            ->addIndex(['requester_id'], ['name' => 'idx_escrow_requester'])
            ->addIndex(['organization_id'], ['name' => 'idx_escrow_org'])
            ->addIndex(['status'], ['name' => 'idx_escrow_status'])
            ->create();

        $grants = $this->table('escrow_access_grants', ['id' => false, 'primary_key' => ['id']]);
        $grants
            ->addColumn('id', 'uuid', ['null' => false])
            ->addColumn('escrow_request_id', 'uuid', ['null' => false])
            ->addColumn('user_id', 'uuid', ['null' => false])
            ->addColumn('resource_id', 'uuid', ['null' => false])
            ->addColumn('access_expires', 'datetime', ['null' => false])
            ->addColumn('accessed_at', 'datetime', ['null' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['escrow_request_id'], ['name' => 'idx_escrow_grants_request'])
            ->addIndex(['user_id', 'resource_id'], ['name' => 'idx_escrow_grants_user_resource'])
            ->addIndex(['access_expires'], ['name' => 'idx_escrow_grants_expires'])
            ->create();
    }
}
