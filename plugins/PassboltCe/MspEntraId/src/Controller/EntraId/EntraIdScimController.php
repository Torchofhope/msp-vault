<?php
declare(strict_types=1);

namespace Passbolt\MspEntraId\Controller\EntraId;

use App\Controller\AppController;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\NotFoundException;
use Passbolt\MspEntraId\Service\EntraIdUserProvisioningService;

/**
 * Per-org SCIM 2.0 endpoint for Entra ID user provisioning.
 *
 * Microsoft Entra ID calls these endpoints to manage users automatically
 * when SCIM provisioning is configured in the Azure enterprise application.
 *
 * Routes (registered by the plugin):
 *   POST   /msp-vault/scim/{org_slug}/Users       — provision new user
 *   GET    /msp-vault/scim/{org_slug}/Users        — list users
 *   GET    /msp-vault/scim/{org_slug}/Users/{id}   — get user
 *   PATCH  /msp-vault/scim/{org_slug}/Users/{id}   — update user (active flag etc.)
 *   DELETE /msp-vault/scim/{org_slug}/Users/{id}   — deprovision user
 *
 * Authentication: Bearer token validated against scim_bearer_token_encrypted
 * in the org's entra_id_configs record.
 */
class EntraIdScimController extends AppController
{
    /**
     * Validate the SCIM Bearer token for the given org slug.
     */
    private function authenticateScimRequest(string $orgSlug): mixed
    {
        $orgsTable = $this->fetchTable('Passbolt/MultiTenant.Organizations');
        $org = $orgsTable->findBySlug($orgSlug);
        if ($org === null) {
            throw new NotFoundException(__('Organization not found.'));
        }

        $configsTable = $this->fetchTable('Passbolt/MspEntraId.EntraIdConfigs');
        $config = $configsTable->findByOrganizationId($org->id);

        if ($config === null || !$config->scim_enabled || empty($config->scim_bearer_token_encrypted)) {
            throw new ForbiddenException(__('SCIM provisioning is not enabled for this organization.'));
        }

        $expectedToken = $configsTable->decryptSecret($config->scim_bearer_token_encrypted);
        $providedToken = ltrim($this->request->getHeaderLine('Authorization'), 'Bearer ');

        if (!hash_equals($expectedToken, trim($providedToken))) {
            throw new ForbiddenException(__('Invalid SCIM bearer token.'));
        }

        return $org;
    }

    public function create(string $orgSlug): void
    {
        $org = $this->authenticateScimRequest($orgSlug);
        $data = $this->request->getData();

        $email = $data['emails'][0]['value'] ?? $data['userName'] ?? null;
        $firstName = $data['name']['givenName'] ?? '';
        $lastName = $data['name']['familyName'] ?? '';

        if (empty($email)) {
            $this->response = $this->response->withStatus(400);
            $this->set('_serialize', ['error']);
            $this->viewBuilder()->setOption('serialize', true);
            return;
        }

        $service = new EntraIdUserProvisioningService();
        $user = $service->createUser($email, $firstName, $lastName, $org->id);

        // Return SCIM-formatted response
        $this->response = $this->response->withStatus(201);
        $scimUser = $this->toScimUser($user, $email);
        $this->success(__('User provisioned.'), $scimUser);
    }

    public function update(string $orgSlug, string $userId): void
    {
        $org = $this->authenticateScimRequest($orgSlug);
        $data = $this->request->getData();

        // Handle active/inactive toggling (Entra ID sends PATCH with active=false to deprovision)
        $operations = $data['Operations'] ?? [];
        foreach ($operations as $op) {
            if (strtolower($op['op'] ?? '') === 'replace' && isset($op['value']['active'])) {
                $service = new EntraIdUserProvisioningService();
                if ($op['value']['active'] === false) {
                    $service->disableUser($userId);
                }
            }
        }

        $this->success(__('User updated.'));
    }

    public function delete(string $orgSlug, string $userId): void
    {
        $this->authenticateScimRequest($orgSlug);

        $service = new EntraIdUserProvisioningService();
        $service->disableUser($userId);

        $this->response = $this->response->withStatus(204);
    }

    private function toScimUser(mixed $user, string $email): array
    {
        return [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
            'id' => $user->id,
            'userName' => $email,
            'active' => $user->active,
            'emails' => [['value' => $email, 'type' => 'work', 'primary' => true]],
        ];
    }
}
