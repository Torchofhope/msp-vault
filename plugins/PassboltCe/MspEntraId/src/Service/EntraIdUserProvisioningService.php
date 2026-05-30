<?php
declare(strict_types=1);

namespace Passbolt\MspEntraId\Service;

use Cake\Http\Client;
use Cake\ORM\TableRegistry;
use Passbolt\MspEntraId\Model\Entity\EntraIdConfig;
use Passbolt\MultiTenant\Model\Behavior\TenantScopeBehavior;
use Passbolt\MultiTenant\Model\Entity\OrganizationUser;

/**
 * Provisions and updates MSP-Vault users from Entra ID data.
 *
 * Used in two scenarios:
 *   1. OIDC login: just-in-time user creation on first SSO login
 *   2. SCIM push: Microsoft calls our SCIM endpoint to add/update/disable users
 *   3. Full sync: admin triggers a pull-sync from the Graph API
 *
 * Users provisioned via Entra ID are assigned CLIENT_USER role by default.
 * The organization_id is stamped from the org's tenant config.
 */
class EntraIdUserProvisioningService
{
    /**
     * Find or create a user from OIDC claims (just-in-time provisioning).
     *
     * @param array $claims Decoded id_token claims
     * @param string $email Resolved email address
     * @param string $organizationId
     * @return \App\Model\Entity\User
     */
    public function findOrProvisionFromOidc(array $claims, string $email, string $organizationId): mixed
    {
        $usersTable = TableRegistry::getTableLocator()->get('Users');

        // Bypass scope to search across the org specifically
        TenantScopeBehavior::setCurrentOrganization($organizationId);

        $user = $usersTable->find()
            ->where(['username' => $email, 'deleted' => false])
            ->first();

        if ($user !== null) {
            // Reactivate if disabled
            if ($user->active === false) {
                $user->set('active', true);
                $user->set('disabled', null);
                $usersTable->save($user);
            }
            return $user;
        }

        // Create new user
        return $this->createUser($email, $claims['given_name'] ?? '', $claims['family_name'] ?? '', $organizationId);
    }

    /**
     * Create a new user in the given org from SCIM or Entra ID sync data.
     */
    public function createUser(string $email, string $firstName, string $lastName, string $organizationId): mixed
    {
        $rolesTable = TableRegistry::getTableLocator()->get('Roles');
        $userRoleId = $rolesTable->getIdByName('user');

        $usersTable = TableRegistry::getTableLocator()->get('Users');

        $user = $usersTable->newEmptyEntity();
        $user->set('username', $email);
        $user->set('role_id', $userRoleId);
        $user->set('active', false); // Requires setup completion
        $user->set('deleted', false);
        $user->set('organization_id', $organizationId);

        $profile = TableRegistry::getTableLocator()->get('Profiles')->newEmptyEntity();
        $profile->set('first_name', $firstName ?: 'User');
        $profile->set('last_name', $lastName ?: $email);
        $user->set('profile', $profile);

        $usersTable->saveOrFail($user, ['associated' => ['Profiles']]);

        // Set org user record
        $this->createOrganizationUserRecord($user->id, $organizationId);

        return $user;
    }

    /**
     * Disable a user (called on SCIM DELETE or Entra ID deprovisioning).
     */
    public function disableUser(string $userId): void
    {
        $usersTable = TableRegistry::getTableLocator()->get('Users');
        $user = $usersTable->get($userId);
        $user->set('active', false);
        $user->set('disabled', new \Cake\I18n\DateTime());
        $usersTable->save($user);
    }

    /**
     * Full sync: pull all users from the org's Entra ID tenant via Graph API.
     *
     * Requires the app to have User.Read.All permission in Azure.
     */
    public function syncAllUsers(EntraIdConfig $config): array
    {
        $accessToken = $this->getGraphApiToken($config);
        $graphUsers = $this->fetchGraphUsers($accessToken, $config->tenant_id);

        $configsTable = TableRegistry::getTableLocator()->get('Passbolt/MspEntraId.EntraIdConfigs');
        $results = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($graphUsers as $graphUser) {
            $email = strtolower(trim($graphUser['mail'] ?? $graphUser['userPrincipalName'] ?? ''));
            if (empty($email)) {
                $results['skipped']++;
                continue;
            }

            $usersTable = TableRegistry::getTableLocator()->get('Users');
            TenantScopeBehavior::setCurrentOrganization($config->organization_id);
            $existing = $usersTable->find()->where(['username' => $email, 'deleted' => false])->first();

            if ($existing === null) {
                $this->createUser(
                    $email,
                    $graphUser['givenName'] ?? '',
                    $graphUser['surname'] ?? '',
                    $config->organization_id
                );
                $results['created']++;
            } else {
                $results['updated']++;
            }
        }

        return $results;
    }

    private function getGraphApiToken(EntraIdConfig $config): string
    {
        $configsTable = TableRegistry::getTableLocator()->get('Passbolt/MspEntraId.EntraIdConfigs');
        $clientSecret = $configsTable->decryptSecret($config->client_secret_encrypted);

        $http = new Client();
        $response = $http->post(
            "https://login.microsoftonline.com/{$config->tenant_id}/oauth2/v2.0/token",
            [
                'client_id' => $config->client_id,
                'client_secret' => $clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]
        );

        $data = $response->getJson();
        return $data['access_token'] ?? throw new \RuntimeException('Failed to get Graph API token.');
    }

    private function fetchGraphUsers(string $accessToken, string $tenantId): array
    {
        $http = new Client(['headers' => ['Authorization' => "Bearer {$accessToken}"]]);
        $response = $http->get('https://graph.microsoft.com/v1.0/users?$select=id,mail,userPrincipalName,givenName,surname,accountEnabled');

        return $response->getJson()['value'] ?? [];
    }

    private function createOrganizationUserRecord(string $userId, string $organizationId): void
    {
        $orgUsersTable = TableRegistry::getTableLocator()->get('Passbolt/MultiTenant.OrganizationUsers');
        $record = $orgUsersTable->newEntity([
            'user_id' => $userId,
            'organization_id' => $organizationId,
            'msp_role' => OrganizationUser::CLIENT_USER,
        ], ['accessibleFields' => ['*' => true]]);
        $orgUsersTable->save($record);
    }
}
