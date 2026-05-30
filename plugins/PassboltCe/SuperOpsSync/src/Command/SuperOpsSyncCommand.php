<?php
declare(strict_types=1);

namespace Passbolt\SuperOpsSync\Command;

use Cake\Console\Arguments;
use Cake\Console\Command;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\ORM\TableRegistry;
use Passbolt\MultiTenant\Model\Behavior\TenantScopeBehavior;
use Passbolt\SuperOpsSync\Service\SuperOpsSyncService;

/**
 * Runs the bidirectional SuperOps sync for all configured organizations.
 *
 * Run via cron: bin/cake msp_vault superops:sync
 * Recommended schedule: every 15–30 minutes.
 *
 * Options:
 *   --org-id  Only sync a specific organization (UUID)
 *   --type    Only run a specific sync type: customers|technicians|assets|license_count
 */
class SuperOpsSyncCommand extends Command
{
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->setDescription('Bidirectional SuperOps ↔ MSP-Vault sync.')
            ->addOption('org-id', ['help' => 'Only sync this specific organization UUID.'])
            ->addOption('type', ['help' => 'Only run this sync type: customers|technicians|assets|license_count.']);

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $io->out('MSP-Vault: Starting SuperOps sync...');
        TenantScopeBehavior::bypassScoping();

        $configsTable = TableRegistry::getTableLocator()->get('Passbolt/SuperOpsSync.SuperOpsSyncConfigs');
        $orgId = $args->getOption('org-id');

        $configs = $orgId
            ? [$configsTable->findByOrganizationId($orgId)]
            : $configsTable->findAllActive()->toArray();

        $configs = array_filter($configs);

        if (empty($configs)) {
            $io->warning('No active SuperOps configurations found.');
            return self::CODE_SUCCESS;
        }

        $service = new SuperOpsSyncService();
        $total = 0;

        foreach ($configs as $config) {
            $io->out("  Syncing org: {$config->organization_id}");
            try {
                $results = $service->syncForConfig($config);
                foreach ($results as $type => $counts) {
                    $io->out("    {$type}: " . json_encode($counts));
                }
                $total++;
            } catch (\Throwable $e) {
                $io->error("  Failed for {$config->organization_id}: {$e->getMessage()}");
            }
        }

        TenantScopeBehavior::reset();
        $io->success("Done. Synced {$total} organization(s).");

        return self::CODE_SUCCESS;
    }
}
