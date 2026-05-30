<?php
declare(strict_types=1);

namespace Passbolt\CredentialEscrow\Service;

use Cake\Event\EventManager;
use Cake\Event\Event;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\NotFoundException;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Passbolt\CredentialEscrow\Model\Entity\EscrowRequest;

/**
 * Manages the full break-glass escrow lifecycle:
 *   - submit: tech requests emergency access with a reason
 *   - approve: admin grants a time-limited access window
 *   - deny: admin rejects the request with an optional note
 *
 * On submit: dispatches MspVault.Escrow.requested event so email
 *            notifications can be sent to all org admins.
 * On approve: creates an EscrowAccessGrant with configurable expiry,
 *             dispatches MspVault.Escrow.approved for tech notification.
 * On deny: dispatches MspVault.Escrow.denied for tech notification.
 *
 * The escrow_requests table is append-only for full audit trail.
 */
class EscrowRequestService
{
    /**
     * Submit a new escrow request.
     */
    public function submit(string $resourceId, string $requesterId, string $orgId, string $reason): EscrowRequest
    {
        $resourcesTable = TableRegistry::getTableLocator()->get('Resources');
        if (!$resourcesTable->exists(['id' => $resourceId, 'deleted' => false])) {
            throw new NotFoundException(__('The credential does not exist.'));
        }

        // Block duplicate pending requests
        $requestsTable = TableRegistry::getTableLocator()->get('Passbolt/CredentialEscrow.EscrowRequests');
        if ($requestsTable->exists([
            'resource_id' => $resourceId,
            'requester_id' => $requesterId,
            'status' => EscrowRequest::STATUS_PENDING,
        ])) {
            throw new BadRequestException(__('You already have a pending escrow request for this credential.'));
        }

        $request = $requestsTable->newEntity([
            'resource_id' => $resourceId,
            'requester_id' => $requesterId,
            'organization_id' => $orgId,
            'reason' => $reason,
            'status' => EscrowRequest::STATUS_PENDING,
        ], ['accessibleFields' => ['*' => true]]);

        $requestsTable->saveOrFail($request);

        // Notify all org admins
        EventManager::instance()->dispatch(new Event(
            'MspVault.Escrow.requested',
            $this,
            ['request' => $request]
        ));

        return $request;
    }

    /**
     * Admin approves the request and grants time-limited access.
     *
     * @param string $requestId
     * @param string $reviewerId
     * @param int $accessHours Duration of the access window in hours (default: 4)
     * @param string|null $note Optional note to requester
     */
    public function approve(string $requestId, string $reviewerId, int $accessHours = EscrowRequest::DEFAULT_ACCESS_HOURS, ?string $note = null): EscrowRequest
    {
        $request = $this->getRequest($requestId);

        if ($request->status !== EscrowRequest::STATUS_PENDING) {
            throw new BadRequestException(__('This request has already been reviewed.'));
        }

        $now = new DateTime();
        $request->set('status', EscrowRequest::STATUS_APPROVED);
        $request->set('reviewed_by', $reviewerId);
        $request->set('reviewed_at', $now);
        $request->set('reviewer_note', $note);

        $requestsTable = TableRegistry::getTableLocator()->get('Passbolt/CredentialEscrow.EscrowRequests');
        $requestsTable->saveOrFail($request);

        // Create the time-limited access grant
        $grantsTable = TableRegistry::getTableLocator()->get('Passbolt/CredentialEscrow.EscrowAccessGrants');
        $grant = $grantsTable->newEntity([
            'escrow_request_id' => $requestId,
            'user_id' => $request->requester_id,
            'resource_id' => $request->resource_id,
            'access_expires' => $now->modify("+{$accessHours} hours"),
        ], ['accessibleFields' => ['*' => true]]);
        $grantsTable->saveOrFail($grant);

        // Notify the requester
        EventManager::instance()->dispatch(new Event(
            'MspVault.Escrow.approved',
            $this,
            ['request' => $request, 'grant' => $grant]
        ));

        return $request;
    }

    /**
     * Admin denies the request.
     */
    public function deny(string $requestId, string $reviewerId, ?string $note = null): EscrowRequest
    {
        $request = $this->getRequest($requestId);

        if ($request->status !== EscrowRequest::STATUS_PENDING) {
            throw new BadRequestException(__('This request has already been reviewed.'));
        }

        $request->set('status', EscrowRequest::STATUS_DENIED);
        $request->set('reviewed_by', $reviewerId);
        $request->set('reviewed_at', new DateTime());
        $request->set('reviewer_note', $note);

        $requestsTable = TableRegistry::getTableLocator()->get('Passbolt/CredentialEscrow.EscrowRequests');
        $requestsTable->saveOrFail($request);

        EventManager::instance()->dispatch(new Event(
            'MspVault.Escrow.denied',
            $this,
            ['request' => $request]
        ));

        return $request;
    }

    private function getRequest(string $requestId): EscrowRequest
    {
        $requestsTable = TableRegistry::getTableLocator()->get('Passbolt/CredentialEscrow.EscrowRequests');
        $request = $requestsTable->find()
            ->where(['id' => $requestId])
            ->contain(['Requester.Profiles', 'Resources'])
            ->first();

        if ($request === null) {
            throw new NotFoundException(__('Escrow request not found.'));
        }

        return $request;
    }
}
