<?php
declare(strict_types=1);

namespace Passbolt\SuperOpsSync\Controller\SuperOpsSync;

use App\Controller\AppController;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;
use Passbolt\MultiTenant\Model\Entity\OrganizationUser;
use Passbolt\SuperOpsSync\Service\SuperOpsSyncService;

/**
 * Manages SuperOps sync configuration per org and triggers manual syncs.
 *
 * GET  /msp-vault/superops/config        — view config (API key redacted)
 * POST /msp-vault/superops/config        — create or update config
 * POST /msp-vault/superops/sync          — trigger immediate full sync
 * GET  /msp-vault/superops/sync/log      — view recent sync log entries
 */
class SuperOpsSyncConfigController extends AppController
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

    public function viewConfig(): void
    {
        $this->assertJson();
        $this->requireAdmin();

        $orgId = $this->request->getAttribute('organization')?->id;
        $config = $this->fetchTable('Passbolt/SuperOpsSync.SuperOpsSyncConfigs')
            ->findByOrganizationId($orgId);

        $this->success(__('The operation was successful.'), $config);
    }

    public function saveConfig(): void
    {
        $this->assertJson();
        $this->assertNotEmptyRequestData();
        $this->requireAdmin();

        $orgId = $this->request->getAttribute('organization')?->id;
        if (empty($orgId)) {
            throw new BadRequestException(__('Could not determine organization.'));
        }

        $configsTable = $this->fetchTable('Passbolt/SuperOpsSync.SuperOpsSyncConfigs');
        $existing = $configsTable->findByOrganizationId($orgId);

        $data = $this->request->getData();
        $data['organization_id'] = $orgId;
        $data['modified_by'] = $this->User->id();

        // Encrypt raw API key if provided
        if (!empty($data['api_key'])) {
            $data['api_key_encrypted'] = $configsTable->encryptApiKey($data['api_key']);
            unset($data['api_key']);
        }

        if ($existing === null) {
            $data['created_by'] = $this->User->id();
            $config = $configsTable->newEntity($data, ['accessibleFields' => ['*' => true]]);
        } else {
            $config = $configsTable->patchEntity($existing, $data);
        }

        if ($config->getErrors()) {
            throw new BadRequestException(__('Could not validate SuperOps sync configuration.'));
        }

        $configsTable->saveOrFail($config);
        $this->success(__('SuperOps configuration saved.'), $config);
    }

    public function runSync(): void
    {
        $this->assertJson();
        $this->requireAdmin();

        $orgId = $this->request->getAttribute('organization')?->id;
        $config = $this->fetchTable('Passbolt/SuperOpsSync.SuperOpsSyncConfigs')
            ->findByOrganizationId($orgId);

        if ($config === null) {
            throw new BadRequestException(__('SuperOps is not configured for this organization.'));
        }

        $service = new SuperOpsSyncService();
        $results = $service->syncForConfig($config);

        $this->success(__('SuperOps sync completed.'), $results);
    }

    public function syncLog(): void
    {
        $this->assertJson();
        $this->requireAdmin();

        $orgId = $this->request->getAttribute('organization')?->id;

        $log = $this->fetchTable('Passbolt/SuperOpsSync.SuperOpsSyncLog')
            ->find()
            ->where(['organization_id' => $orgId])
            ->orderBy(['created' => 'DESC'])
            ->limit(50)
            ->all()
            ->toArray();

        $this->success(__('The operation was successful.'), $log);
    }
}
