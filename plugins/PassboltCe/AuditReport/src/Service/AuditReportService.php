<?php
declare(strict_types=1);

namespace Passbolt\AuditReport\Service;

use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;

/**
 * Builds per-client audit report data from the Log plugin tables.
 *
 * Report contents:
 *   - Every secret access event: technician name, credential name, timestamp
 *   - Filtered by organization (via resource organization_id)
 *   - Optional date range filter
 *
 * Output formats: array (for JSON), CSV string (for download).
 */
class AuditReportService
{
    /**
     * @param string $organizationId
     * @param \Cake\I18n\DateTime|null $from
     * @param \Cake\I18n\DateTime|null $to
     * @return array
     */
    public function buildReport(string $organizationId, ?DateTime $from = null, ?DateTime $to = null): array
    {
        // Get all resource IDs for this org
        $resourceIds = TableRegistry::getTableLocator()->get('Resources')
            ->find()
            ->select(['Resources.id', 'Resources.name'])
            ->where(['Resources.organization_id' => $organizationId, 'Resources.deleted' => false])
            ->disableHydration()
            ->all()
            ->combine('id', 'name')
            ->toArray();

        if (empty($resourceIds)) {
            return [];
        }

        $accessesTable = TableRegistry::getTableLocator()->get('Passbolt/Log.SecretAccesses');

        $query = $accessesTable->find()
            ->where(['SecretAccesses.resource_id IN' => array_keys($resourceIds)])
            ->contain(['Users' => ['Profiles']])
            ->orderBy(['SecretAccesses.created' => 'DESC']);

        if ($from !== null) {
            $query->where(['SecretAccesses.created >=' => $from]);
        }
        if ($to !== null) {
            $query->where(['SecretAccesses.created <=' => $to]);
        }

        $rows = [];
        foreach ($query->all() as $access) {
            $techName = $access->user
                ? trim(($access->user->profile->first_name ?? '') . ' ' . ($access->user->profile->last_name ?? ''))
                : 'Unknown';

            $rows[] = [
                'technician' => $techName,
                'user_id' => $access->user_id,
                'credential' => $resourceIds[$access->resource_id] ?? 'Deleted credential',
                'resource_id' => $access->resource_id,
                'accessed_at' => $access->created->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * Convert report rows to CSV string.
     *
     * @param array $rows
     * @return string
     */
    public function toCsv(array $rows): string
    {
        if (empty($rows)) {
            return "technician,user_id,credential,resource_id,accessed_at\n";
        }

        $output = fopen('php://temp', 'r+');
        fputcsv($output, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}
