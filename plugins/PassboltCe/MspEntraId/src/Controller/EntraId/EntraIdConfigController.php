<?php
declare(strict_types=1);

namespace Passbolt\MspEntraId\Controller\EntraId;

use App\Controller\AppController;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;
use Passbolt\MspEntraId\Service\EntraIdUserProvisioningService;
use Passbolt\MultiTenant\Model\Entity\OrganizationUser;

/**
 * Admin management of per-org Entra ID configuration.
 *
 * GET  /msp-vault/entra/config          — view current config (secrets redacted)
 * POST /msp-vault/entra/config          — create or update config
 * POST /msp-vault/entra/sync            — trigger immediate Graph API user sync
 */
class EntraIdConfigController extends AppController
{
    private function requireAdmin(): void
    {
        $orgUser = $this->fetchTable('Passbolt/MultiTenant.OrganizationUsers')
            ->findForUser($this->User->id());
        $isSuperAdmin = $this->request->getAttribute('msp_super_admin', false);
        $isAdmin = $orgUser && in_array($orgUser->msp_role, [
            OrganizationUser::MSP_SUPER_ADMIN,
            OrganizationUser::CLIENT_ADMIN,
        ], true);

        if (!$isSuperAdmin && !$isAdmin) {
            throw new ForbiddenException(__('Access restricted to administrators.'));
        }
    }

    public function view(): void
    {
        $this->assertJson();
        $this->requireAdmin();

        $orgId = $this->request->getAttribute('organization')?->id;
        $config = $this->fetchTable('Passbolt/MspEntraId.EntraIdConfigs')
            ->findByOrganizationId($orgId);

        $this->success(__('The operation was successful.'), $config);
    }

    public function save(): void
    {
        $this->assertJson();
        $this->assertNotEmptyRequestData();
        $this->requireAdmin();

        $orgId = $this->request->getAttribute('organization')?->id;
        if (empty($orgId)) {
            throw new BadRequestException(__('Could not determine organization.'));
        }

        $configsTable = $this->fetchTable('Passbolt/MspEntraId.EntraIdConfigs');
        $existing = $configsTable->findByOrganizationId($orgId);

        $data = $this->request->getData();
        $data['organization_id'] = $orgId;
        $data['modified_by'] = $this->User->id();

        // Encrypt the client_secret if provided in plaintext
        if (!empty($data['client_secret'])) {
            $data['client_secret_encrypted'] = $configsTable->encryptSecret($data['client_secret']);
            unset($data['client_secret']);
        }

        // Encrypt the SCIM bearer token if provided
        if (!empty($data['scim_bearer_token'])) {
            $data['scim_bearer_token_encrypted'] = $configsTable->encryptSecret($data['scim_bearer_token']);
            unset($data['scim_bearer_token']);
        }

        if ($existing === null) {
            $data['created_by'] = $this->User->id();
            $config = $configsTable->newEntity($data, ['accessibleFields' => ['*' => true]]);
        } else {
            $config = $configsTable->patchEntity($existing, $data);
        }

        if ($config->getErrors()) {
            throw new BadRequestException(__('Could not validate Entra ID configuration.'));
        }

        $configsTable->saveOrFail($config);
        $this->success(__('Entra ID configuration saved successfully.'), $config);
    }

    public function syncUsers(): void
    {
        $this->assertJson();
        $this->requireAdmin();

        $orgId = $this->request->getAttribute('organization')?->id;
        $config = $this->fetchTable('Passbolt/MspEntraId.EntraIdConfigs')
            ->findByOrganizationId($orgId);

        if ($config === null) {
            throw new BadRequestException(__('Entra ID is not configured for this organization.'));
        }

        $service = new EntraIdUserProvisioningService();
        $results = $service->syncAllUsers($config);

        $this->success(__('User sync completed.'), $results);
    }
}
