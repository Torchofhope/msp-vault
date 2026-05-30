<?php
declare(strict_types=1);

namespace Passbolt\CredentialRotation\Service;

use App\Utility\UserAccessControl;
use Cake\ORM\TableRegistry;
use Passbolt\CredentialRotation\Model\Entity\CredentialRotationPolicy;

/**
 * Handles the business logic for rotating a credential.
 *
 * Passbolt encrypts secrets client-side with GPG keys, so the server
 * cannot generate a new password and re-encrypt it on its own. Instead,
 * manual rotation is a two-phase process:
 *
 *   Phase 1 (this service): Mark the credential as "rotation pending",
 *   notify all users with access that they must complete rotation.
 *
 *   Phase 2 (client): The user with OWNER permission opens the credential,
 *   generates a new password, re-encrypts for all other users, and saves.
 *   The existing SecretRevisions plugin captures the new revision automatically.
 *
 * For scheduled rotation, this service fires the Phase 1 notification on schedule.
 * The RotationHistoryTable records each rotation event for audit purposes.
 */
class RotateCredentialService
{
    /**
     * Trigger manual rotation for a credential.
     *
     * @param string $resourceId
     * @param \App\Utility\UserAccessControl $uac
     * @return void
     * @throws \Cake\Http\Exception\NotFoundException
     */
    public function rotateManual(string $resourceId, UserAccessControl $uac): void
    {
        $this->assertResourceExists($resourceId);
        $this->markRotationPending($resourceId, $uac->getId(), 'manual');

        if ($this->shouldNotify($resourceId)) {
            $this->notifyUsersWithAccess($resourceId, $uac->getId());
        }
    }

    /**
     * Trigger scheduled rotation for a credential (called by the cron command).
     *
     * @param string $resourceId
     * @return void
     */
    public function rotateScheduled(string $resourceId): void
    {
        $this->assertResourceExists($resourceId);
        $this->markRotationPending($resourceId, null, 'scheduled');

        if ($this->shouldNotify($resourceId)) {
            $this->notifyUsersWithAccess($resourceId, null);
        }

        /** @var \Passbolt\CredentialRotation\Model\Table\CredentialRotationPoliciesTable $policiesTable */
        $policiesTable = TableRegistry::getTableLocator()->get('Passbolt/CredentialRotation.CredentialRotationPolicies');
        $policiesTable->stampRotation($resourceId);
    }

    private function assertResourceExists(string $resourceId): void
    {
        $resources = TableRegistry::getTableLocator()->get('Resources');
        if (!$resources->exists(['id' => $resourceId, 'deleted' => false])) {
            throw new \Cake\Http\Exception\NotFoundException(__('The credential does not exist.'));
        }
    }

    private function markRotationPending(string $resourceId, ?string $triggeredBy, string $triggerType): void
    {
        /** @var \Passbolt\CredentialRotation\Model\Table\CredentialRotationHistoryTable $historyTable */
        $historyTable = TableRegistry::getTableLocator()->get('Passbolt/CredentialRotation.CredentialRotationHistory');
        $historyTable->logRotation($resourceId, $triggeredBy, $triggerType);
    }

    private function shouldNotify(string $resourceId): bool
    {
        /** @var \Passbolt\CredentialRotation\Model\Table\CredentialRotationPoliciesTable $policiesTable */
        $policiesTable = TableRegistry::getTableLocator()->get('Passbolt/CredentialRotation.CredentialRotationPolicies');
        $policy = $policiesTable->find()->where(['resource_id' => $resourceId])->first();

        return $policy !== null && $policy->notify_on_rotation;
    }

    private function notifyUsersWithAccess(string $resourceId, ?string $triggeredBy): void
    {
        // Dispatch a CakePHP event so email redactors can send notifications.
        // This follows passbolt's existing email notification pattern.
        $eventManager = \Cake\Event\EventManager::instance();
        $eventManager->dispatch(new \Cake\Event\Event(
            'MspVault.Rotation.notify',
            $this,
            ['resource_id' => $resourceId, 'triggered_by' => $triggeredBy]
        ));
    }
}
