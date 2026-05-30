<?php
declare(strict_types=1);

namespace Passbolt\SuperOpsSync\Service;

use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Passbolt\MultiTenant\Model\Behavior\TenantScopeBehavior;
use Passbolt\MultiTenant\Model\Entity\Organization;
use Passbolt\MultiTenant\Model\Entity\OrganizationUser;
use Passbolt\SuperOpsSync\Model\Entity\SuperOpsSyncConfig;

/**
 * Bidirectional sync between SuperOps and MSP-Vault.
 *
 * INBOUND (SuperOps → MSP-Vault):
 *   - syncCustomers(): new SuperOps clients → create organizations
 *   - syncTechnicians(): SuperOps techs → create/update MSP-Vault users
 *   - syncAssets(): SuperOps assets → create/update devices
 *
 * OUTBOUND (MSP-Vault → SuperOps):
 *   - pushLicenseCount(): active user count per org → SuperOps custom field
 *     This drives per-client billing in SuperOps automatically.
 *
 * Each sync operation writes a record to superops_sync_log.
 */
class SuperOpsSyncService
{
    /**
     * Run the full sync for one org config.
     *
     * @return array{customers: array, technicians: array, assets: array, license_count: bool}
     */
    public function syncForConfig(SuperOpsSyncConfig $config): array
    {
        $configsTable = TableRegistry::getTableLocator()->get('Passbolt/SuperOpsSync.SuperOpsSyncConfigs');
        $apiKey = $configsTable->decryptApiKey($config->api_key_encrypted);
        $client = new SuperOpsApiClient($apiKey, $config->base_url);

        $results = [];

        if ($config->sync_customers) {
            $results['customers'] = $this->syncCustomers($client, $config);
        }

        if ($config->sync_technicians) {
            $results['technicians'] = $this->syncTechnicians($client, $config);
        }

        if ($config->sync_assets) {
            $results['assets'] = $this->syncAssets($client, $config);
        }

        if ($config->push_license_count && $config->superops_customer_id) {
            $results['license_count'] = $this->pushLicenseCount($client, $config);
        }

        // Update last_synced
        $config->set('last_synced', new DateTime());
        $configsTable->save($config);

        return $results;
    }

    /**
     * Sync SuperOps clients → MSP-Vault organizations.
     * Creates a new org for each SuperOps client that doesn't exist yet.
     */
    private function syncCustomers(SuperOpsApiClient $client, SuperOpsSyncConfig $config): array
    {
        $customers = $client->getCustomers();
        $orgsTable = TableRegistry::getTableLocator()->get('Passbolt/MultiTenant.Organizations');
        $counts = ['created' => 0, 'skipped' => 0];

        foreach ($customers as $customer) {
            $name = $customer['name'] ?? $customer['companyName'] ?? null;
            $externalId = (string)($customer['id'] ?? '');

            if (empty($name) || empty($externalId)) {
                $counts['skipped']++;
                continue;
            }

            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));

            // Check if org already exists by checking slug or matching superops_asset_id in devices
            $exists = $orgsTable->find()->where(['slug' => $slug])->first();
            if ($exists) {
                $counts['skipped']++;
                continue;
            }

            $org = $orgsTable->newEntity([
                'name' => $name,
                'slug' => $slug,
                'plan_type' => Organization::PLAN_CLIENT,
                'is_active' => true,
            ], ['accessibleFields' => ['*' => true]]);
            $orgsTable->save($org);
            $counts['created']++;
        }

        $this->logSync($config->organization_id, 'customers', 'success', $counts);

        return $counts;
    }

    /**
     * Sync SuperOps technicians → MSP-Vault users in the MSP's own org.
     */
    private function syncTechnicians(SuperOpsApiClient $client, SuperOpsSyncConfig $config): array
    {
        $technicians = $client->getTechnicians();
        $usersTable = TableRegistry::getTableLocator()->get('Users');
        $rolesTable = TableRegistry::getTableLocator()->get('Roles');
        $userRoleId = $rolesTable->getIdByName('user');
        $orgUsersTable = TableRegistry::getTableLocator()->get('Passbolt/MultiTenant.OrganizationUsers');

        TenantScopeBehavior::setCurrentOrganization($config->organization_id);
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($technicians as $tech) {
            $email = strtolower(trim($tech['email'] ?? ''));
            if (empty($email)) {
                $counts['skipped']++;
                continue;
            }

            $existing = $usersTable->find()->where(['username' => $email, 'deleted' => false])->first();

            if ($existing) {
                $counts['updated']++;
                continue;
            }

            $user = $usersTable->newEmptyEntity();
            $user->set('username', $email);
            $user->set('role_id', $userRoleId);
            $user->set('active', false);
            $user->set('deleted', false);
            $user->set('organization_id', $config->organization_id);

            $profilesTable = TableRegistry::getTableLocator()->get('Profiles');
            $profile = $profilesTable->newEmptyEntity();
            $profile->set('first_name', $tech['firstName'] ?? $tech['first_name'] ?? 'Tech');
            $profile->set('last_name', $tech['lastName'] ?? $tech['last_name'] ?? $email);
            $user->set('profile', $profile);

            if ($usersTable->save($user, ['associated' => ['Profiles']])) {
                // Mark as technician role in MSP-Vault
                $orgUser = $orgUsersTable->newEntity([
                    'user_id' => $user->id,
                    'organization_id' => $config->organization_id,
                    'msp_role' => OrganizationUser::TECHNICIAN,
                ], ['accessibleFields' => ['*' => true]]);
                $orgUsersTable->save($orgUser);
                $counts['created']++;
            }
        }

        $this->logSync($config->organization_id, 'technicians', 'success', $counts);

        return $counts;
    }

    /**
     * Sync SuperOps assets → MSP-Vault devices for a given org.
     */
    private function syncAssets(SuperOpsApiClient $client, SuperOpsSyncConfig $config): array
    {
        if (empty($config->superops_customer_id)) {
            return ['skipped' => 'no superops_customer_id configured'];
        }

        $assets = $client->getAssets($config->superops_customer_id);
        $devicesTable = TableRegistry::getTableLocator()->get('Passbolt/Devices.Devices');
        TenantScopeBehavior::setCurrentOrganization($config->organization_id);

        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($assets as $asset) {
            $assetId = (string)($asset['id'] ?? '');
            $name = $asset['name'] ?? $asset['assetName'] ?? null;

            if (empty($assetId) || empty($name)) {
                $counts['skipped']++;
                continue;
            }

            $existing = $devicesTable->findBySuperopsAssetId($assetId);

            $deviceType = $this->mapAssetTypeToDeviceType($asset['type'] ?? $asset['assetType'] ?? '');

            if ($existing) {
                $existing->set('hostname', $asset['hostname'] ?? $asset['computerName'] ?? $existing->hostname);
                $existing->set('ip_address', $asset['ipAddress'] ?? $asset['ip'] ?? $existing->ip_address);
                $devicesTable->save($existing);
                $counts['updated']++;
            } else {
                $systemUser = TableRegistry::getTableLocator()->get('Users')
                    ->find()->where(['organization_id' => $config->organization_id, 'deleted' => false])->first();
                $systemUserId = $systemUser?->id ?? '00000000-0000-0000-0000-000000000000';

                $device = $devicesTable->newEntity([
                    'name' => $name,
                    'device_type' => $deviceType,
                    'hostname' => $asset['hostname'] ?? $asset['computerName'] ?? null,
                    'ip_address' => $asset['ipAddress'] ?? $asset['ip'] ?? null,
                    'operating_system' => $asset['os'] ?? $asset['operatingSystem'] ?? null,
                    'manufacturer' => $asset['manufacturer'] ?? null,
                    'model' => $asset['model'] ?? null,
                    'serial_number' => $asset['serialNumber'] ?? null,
                    'superops_asset_id' => $assetId,
                    'deleted' => false,
                    'created_by' => $systemUserId,
                    'modified_by' => $systemUserId,
                ], ['accessibleFields' => ['*' => true]]);

                $devicesTable->save($device);
                $counts['created']++;
            }
        }

        $this->logSync($config->organization_id, 'assets', 'success', $counts);

        return $counts;
    }

    /**
     * Push the current active user count back to SuperOps for billing.
     */
    private function pushLicenseCount(SuperOpsApiClient $client, SuperOpsSyncConfig $config): bool
    {
        TenantScopeBehavior::setCurrentOrganization($config->organization_id);

        $userCount = TableRegistry::getTableLocator()->get('Users')
            ->find()
            ->where(['organization_id' => $config->organization_id, 'active' => true, 'deleted' => false])
            ->count();

        $success = $client->updateCustomerLicenseCount($config->superops_customer_id, $userCount);

        $this->logSync($config->organization_id, 'license_count', $success ? 'success' : 'failed', [
            'user_count_pushed' => $userCount,
        ]);

        return $success;
    }

    private function mapAssetTypeToDeviceType(string $superopsType): string
    {
        $map = [
            'workstation' => 'workstation',
            'desktop' => 'workstation',
            'laptop' => 'workstation',
            'server' => 'server',
            'switch' => 'switch',
            'router' => 'router',
            'firewall' => 'firewall',
            'printer' => 'printer',
            'nas' => 'nas',
            'camera' => 'camera',
        ];

        return $map[strtolower($superopsType)] ?? 'other';
    }

    private function logSync(string $orgId, string $syncType, string $status, array $counts): void
    {
        $logTable = TableRegistry::getTableLocator()->get('Passbolt/SuperOpsSync.SuperOpsSyncLog');
        $record = $logTable->newEntity([
            'organization_id' => $orgId,
            'sync_type' => $syncType,
            'status' => $status,
            'records_created' => $counts['created'] ?? 0,
            'records_updated' => $counts['updated'] ?? 0,
            'records_skipped' => $counts['skipped'] ?? 0,
        ], ['accessibleFields' => ['*' => true]]);
        $logTable->save($record);
    }
}
