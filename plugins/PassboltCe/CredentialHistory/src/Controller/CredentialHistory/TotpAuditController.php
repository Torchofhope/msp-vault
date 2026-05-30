<?php
declare(strict_types=1);

namespace Passbolt\CredentialHistory\Controller\CredentialHistory;

use App\Controller\AppController;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\NotFoundException;
use Passbolt\CredentialHistory\Service\TotpAccessAuditService;

/**
 * Returns the access audit trail for a TOTP credential.
 * Shows who viewed the TOTP secret and when.
 *
 * GET /msp-vault/credentials/:resource_id/totp-audit
 *
 * Only admins or the credential owner can view the full audit trail.
 */
class TotpAuditController extends AppController
{
    public function index(string $resourceId): void
    {
        $this->assertJson();

        // Verify the resource exists
        $resource = $this->fetchTable('Resources')
            ->find()
            ->where(['id' => $resourceId, 'deleted' => false])
            ->first();

        if ($resource === null) {
            throw new NotFoundException(__('The credential does not exist.'));
        }

        // Only users with access can view the audit trail
        $hasAccess = $this->fetchTable('Permissions')
            ->hasAccess('Resource', $resourceId, $this->User->id());

        if (!$hasAccess) {
            throw new ForbiddenException(__('You do not have access to this credential.'));
        }

        $limit = (int)($this->request->getQuery('limit') ?? 100);
        $limit = min(max($limit, 1), 500);

        $service = new TotpAccessAuditService();
        $trail = $service->getAuditTrail($resourceId, $limit);

        $this->success(__('The operation was successful.'), $trail);
    }
}
