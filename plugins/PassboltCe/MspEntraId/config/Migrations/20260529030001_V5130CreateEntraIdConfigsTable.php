<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Per-organization Entra ID (Azure AD) configuration.
 *
 * Each organization can have its own Azure AD tenant, enabling:
 *   - SSO login with that org's Microsoft 365 accounts
 *   - SCIM provisioning from that org's Entra ID directory
 *   - Per-client tenant isolation
 *
 * client_secret and scim_bearer_token are stored encrypted.
 *
 * entra_id_oidc_state: short-lived table for OIDC state/nonce validation.
 * Rows are deleted after callback processing or expiry.
 */
class V5130CreateEntraIdConfigsTable extends AbstractMigration
{
    public function change(): void
    {
        $configs = $this->table('entra_id_configs', ['id' => false, 'primary_key' => ['id']]);
        $configs
            ->addColumn('id', 'uuid', ['null' => false])
            ->addColumn('organization_id', 'uuid', ['null' => false])
            ->addColumn('tenant_id', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('client_id', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('client_secret_encrypted', 'text', ['null' => false])
            ->addColumn('client_secret_expiry', 'datetime', ['null' => true])
            ->addColumn('scim_bearer_token_encrypted', 'text', ['null' => true])
            ->addColumn('email_claim', 'string', ['limit' => 30, 'null' => false, 'default' => 'email'])
            ->addColumn('sso_enabled', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('scim_enabled', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('is_active', 'boolean', ['null' => false, 'default' => true])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addColumn('created_by', 'uuid', ['null' => false])
            ->addColumn('modified_by', 'uuid', ['null' => false])
            ->addIndex(['organization_id'], ['unique' => true, 'name' => 'uq_entra_id_config_org'])
            ->create();

        // Short-lived OIDC state tokens (state + nonce) to prevent CSRF/replay
        $state = $this->table('entra_id_oidc_state', ['id' => false, 'primary_key' => ['id']]);
        $state
            ->addColumn('id', 'uuid', ['null' => false])
            ->addColumn('organization_id', 'uuid', ['null' => false])
            ->addColumn('state', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('nonce', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('expires', 'datetime', ['null' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addIndex(['state'], ['unique' => true, 'name' => 'uq_oidc_state'])
            ->addIndex(['expires'], ['name' => 'idx_oidc_state_expires'])
            ->create();
    }
}
