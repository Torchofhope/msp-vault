<?php
declare(strict_types=1);

namespace Passbolt\CredentialEscrow\Controller\CredentialEscrow;

use App\Controller\AppController;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;
use Passbolt\CredentialEscrow\Service\EscrowRequestService;
use Passbolt\MultiTenant\Model\Entity\OrganizationUser;

/**
 * Break-glass emergency credential access workflow.
 *
 * POST /msp-vault/escrow/request
 *   Body: { resource_id, reason }
 *   Any authenticated user can submit a request.
 *
 * GET  /msp-vault/escrow/pending
 *   Admin-only: lists pending requests for the current org.
 *
 * POST /msp-vault/escrow/:id/approve
 *   Body: { access_hours (optional, default 4), note (optional) }
 *   Admin-only: approves the request and creates a time-limited grant.
 *
 * POST /msp-vault/escrow/:id/deny
 *   Body: { note (optional) }
 *   Admin-only: denies the request.
 *
 * GET  /msp-vault/escrow/history
 *   Admin-only: all escrow requests for the org (all statuses).
 */
class EscrowRequestsController extends AppController
{
    private function requireAdminRole(): void
    {
        $orgUsersTable = $this->fetchTable('Passbolt/MultiTenant.OrganizationUsers');
        $orgUser = $orgUsersTable->findForUser($this->User->id());
        $isSuperAdmin = $this->request->getAttribute('msp_super_admin', false);
        $isAdmin = $orgUser && in_array($orgUser->msp_role, [
            OrganizationUser::MSP_SUPER_ADMIN,
            OrganizationUser::CLIENT_ADMIN,
        ], true);

        if (!$isSuperAdmin && !$isAdmin) {
            throw new ForbiddenException(__('Access restricted to administrators.'));
        }
    }

    public function submit(): void
    {
        $this->assertJson();
        $this->assertNotEmptyRequestData();

        $data = $this->request->getData();
        $resourceId = $data['resource_id'] ?? null;
        $reason = $data['reason'] ?? null;

        if (empty($resourceId) || empty($reason)) {
            throw new BadRequestException(__('resource_id and reason are required.'));
        }

        $orgUsersTable = $this->fetchTable('Passbolt/MultiTenant.OrganizationUsers');
        $orgUser = $orgUsersTable->findForUser($this->User->id());
        $orgId = $orgUser?->organization_id ?? $this->request->getAttribute('organization')?->id;

        if (empty($orgId)) {
            throw new BadRequestException(__('Could not determine organization.'));
        }

        $service = new EscrowRequestService();
        $request = $service->submit($resourceId, $this->User->id(), $orgId, $reason);

        $this->success(__('Your emergency access request has been submitted. An administrator will review it shortly.'), $request);
    }

    public function pending(): void
    {
        $this->assertJson();
        $this->requireAdminRole();

        $orgUser = $this->fetchTable('Passbolt/MultiTenant.OrganizationUsers')->findForUser($this->User->id());
        $orgId = $orgUser?->organization_id ?? $this->request->getAttribute('organization')?->id;

        $requestsTable = $this->fetchTable('Passbolt/CredentialEscrow.EscrowRequests');
        $pending = $requestsTable->findPendingForOrg($orgId)->toArray();

        $this->success(__('The operation was successful.'), $pending);
    }

    public function approve(string $id): void
    {
        $this->assertJson();
        $this->requireAdminRole();

        $data = $this->request->getData();
        $accessHours = isset($data['access_hours']) ? (int)$data['access_hours'] : 4;
        $note = $data['note'] ?? null;

        $service = new EscrowRequestService();
        $request = $service->approve($id, $this->User->id(), $accessHours, $note);

        $this->success(__('The request has been approved. Access granted for {0} hour(s).', $accessHours), $request);
    }

    public function deny(string $id): void
    {
        $this->assertJson();
        $this->requireAdminRole();

        $note = $this->request->getData('note');

        $service = new EscrowRequestService();
        $request = $service->deny($id, $this->User->id(), $note);

        $this->success(__('The request has been denied.'), $request);
    }

    public function history(): void
    {
        $this->assertJson();
        $this->requireAdminRole();

        $orgUser = $this->fetchTable('Passbolt/MultiTenant.OrganizationUsers')->findForUser($this->User->id());
        $orgId = $orgUser?->organization_id ?? $this->request->getAttribute('organization')?->id;

        $requestsTable = $this->fetchTable('Passbolt/CredentialEscrow.EscrowRequests');
        $history = $requestsTable->find()
            ->where(['organization_id' => $orgId])
            ->contain(['Requester.Profiles', 'Reviewer.Profiles', 'Resources', 'EscrowAccessGrants'])
            ->orderBy(['created' => 'DESC'])
            ->all()
            ->toArray();

        $this->success(__('The operation was successful.'), $history);
    }
}
