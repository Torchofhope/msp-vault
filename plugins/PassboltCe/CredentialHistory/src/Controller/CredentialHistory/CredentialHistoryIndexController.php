<?php
declare(strict_types=1);

namespace Passbolt\CredentialHistory\Controller\CredentialHistory;

use App\Controller\AppController;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\NotFoundException;

/**
 * Returns the full version history for a credential.
 *
 * Each entry in the response represents one revision and includes:
 *   - who changed it (modifier name)
 *   - when it was changed (created timestamp)
 *   - the encrypted previous secret data (accessible to users who have the key)
 *
 * GET /msp-vault/credentials/:resource_id/history
 *
 * The secret data is GPG-encrypted per-user — callers will receive only
 * the encrypted blob for their own user_id. Decryption happens client-side.
 *
 * Only users with at least READ permission on the resource can view history.
 * Only OWNER-level users can see the encrypted previous secrets of other users.
 */
class CredentialHistoryIndexController extends AppController
{
    public function index(string $resourceId): void
    {
        $this->assertJson();

        $resourcesTable = $this->fetchTable('Resources');

        // Verify resource exists and is not deleted
        $resource = $resourcesTable->find()
            ->where(['Resources.id' => $resourceId, 'Resources.deleted' => false])
            ->first();

        if ($resource === null) {
            throw new NotFoundException(__('The credential does not exist.'));
        }

        // Verify the user has access to this resource
        $permissionsTable = $this->fetchTable('Permissions');
        $hasAccess = $permissionsTable->hasAccess('Resource', $resourceId, $this->User->id());
        if (!$hasAccess) {
            throw new ForbiddenException(__('You do not have access to this credential.'));
        }

        /** @var \Passbolt\SecretRevisions\Model\Table\SecretRevisionsTable $revisionsTable */
        $revisionsTable = $this->fetchTable('Passbolt/SecretRevisions.SecretRevisions');

        // Load all revisions for this resource, newest first
        $revisions = $revisionsTable->find()
            ->where([
                'SecretRevisions.resource_id' => $resourceId,
            ])
            ->contain([
                'Creator' => ['Profiles'],
                'Modifier' => ['Profiles'],
                'Secrets' => function ($q) {
                    // Return only the current user's encrypted secret per revision
                    return $q->where(['Secrets.user_id' => $this->User->id()]);
                },
            ])
            ->orderBy(['SecretRevisions.created' => 'DESC'])
            ->all()
            ->map(function ($revision) {
                return [
                    'id' => $revision->id,
                    'changed_by' => $revision->modifier
                        ? $revision->modifier->profile->full_name
                        : ($revision->creator ? $revision->creator->profile->full_name : 'System'),
                    'changed_at' => $revision->created,
                    'modifier_id' => $revision->modified_by,
                    'encrypted_secret' => !empty($revision->secrets) ? $revision->secrets[0]->data : null,
                ];
            })
            ->toArray();

        $this->success(__('The operation was successful.'), $revisions);
    }
}
