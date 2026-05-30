<?php
declare(strict_types=1);

namespace Passbolt\MspDashboard\Service;

use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;

/**
 * Aggregates per-organization stats for the MSP super-admin dashboard.
 *
 * Returns one row per org containing:
 *   - org metadata (id, name, slug, plan_type, is_active)
 *   - user_count: number of active, non-deleted users in the org
 *   - resource_count: number of active credentials in the org
 *   - last_activity: most recent secret access timestamp across the org
 *   - vault_status: 'active' | 'inactive' | 'empty'
 */
class MspDashboardStatsService
{
    public function getStats(): array
    {
        $orgsTable = TableRegistry::getTableLocator()->get('Passbolt/MultiTenant.Organizations');
        $orgs = $orgsTable->find()
            ->where(['is_active' => true])
            ->orderBy(['name' => 'ASC'])
            ->all();

        $stats = [];
        foreach ($orgs as $org) {
            $stats[] = $this->buildOrgStats($org);
        }

        return $stats;
    }

    private function buildOrgStats(mixed $org): array
    {
        $orgId = $org->id;

        $userCount = TableRegistry::getTableLocator()->get('Users')
            ->find()
            ->where(['organization_id' => $orgId, 'active' => true, 'deleted' => false])
            ->count();

        $resourceCount = TableRegistry::getTableLocator()->get('Resources')
            ->find()
            ->where(['organization_id' => $orgId, 'deleted' => false])
            ->count();

        // Last activity = most recent secret access for any resource in this org
        $lastActivity = $this->getLastActivity($orgId);

        $vaultStatus = $this->computeVaultStatus($resourceCount, $lastActivity);

        return [
            'id' => $org->id,
            'name' => $org->name,
            'slug' => $org->slug,
            'plan_type' => $org->plan_type,
            'is_active' => $org->is_active,
            'user_count' => $userCount,
            'resource_count' => $resourceCount,
            'last_activity' => $lastActivity?->toIso8601String(),
            'vault_status' => $vaultStatus,
        ];
    }

    private function getLastActivity(string $orgId): ?DateTime
    {
        // Find resources belonging to this org, then get the latest secret access
        $resourceIds = TableRegistry::getTableLocator()->get('Resources')
            ->find()
            ->select(['id'])
            ->where(['organization_id' => $orgId, 'deleted' => false])
            ->all()
            ->extract('id')
            ->toArray();

        if (empty($resourceIds)) {
            return null;
        }

        $access = TableRegistry::getTableLocator()->get('Passbolt/Log.SecretAccesses')
            ->find()
            ->select(['created'])
            ->where(['resource_id IN' => $resourceIds])
            ->orderBy(['created' => 'DESC'])
            ->first();

        return $access?->created;
    }

    private function computeVaultStatus(int $resourceCount, ?DateTime $lastActivity): string
    {
        if ($resourceCount === 0) {
            return 'empty';
        }

        // Inactive if no access in the last 30 days
        if ($lastActivity === null || $lastActivity->lt(new DateTime('-30 days'))) {
            return 'inactive';
        }

        return 'active';
    }
}
