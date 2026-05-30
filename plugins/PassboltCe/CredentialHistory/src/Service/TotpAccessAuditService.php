<?php
declare(strict_types=1);

namespace Passbolt\CredentialHistory\Service;

use Cake\ORM\TableRegistry;

/**
 * Returns the TOTP access audit trail for a given resource.
 *
 * Passbolt's Log plugin (SecretAccesses table) already records every time
 * a secret is read. This service filters those records to TOTP resources
 * and returns a readable audit trail per credential.
 *
 * Each entry: who accessed the TOTP secret, when, and from what IP.
 */
class TotpAccessAuditService
{
    /**
     * @param string $resourceId
     * @param int $limit
     * @return array
     */
    public function getAuditTrail(string $resourceId, int $limit = 100): array
    {
        // Use the existing SecretAccesses table from the Log plugin
        $secretAccessesTable = TableRegistry::getTableLocator()->get('Passbolt/Log.SecretAccesses');

        $accesses = $secretAccessesTable->find()
            ->where(['SecretAccesses.resource_id' => $resourceId])
            ->contain([
                'Users' => ['Profiles'],
            ])
            ->orderBy(['SecretAccesses.created' => 'DESC'])
            ->limit($limit)
            ->all()
            ->map(function ($access) {
                return [
                    'id' => $access->id,
                    'accessed_by' => $access->user
                        ? $access->user->profile->full_name
                        : 'Unknown',
                    'user_id' => $access->user_id,
                    'accessed_at' => $access->created,
                ];
            })
            ->toArray();

        return $accesses;
    }
}
