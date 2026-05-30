<?php
declare(strict_types=1);

namespace Passbolt\CredentialRotation\Command;

use Cake\Console\Arguments;
use Cake\Console\Command;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\ORM\TableRegistry;
use Passbolt\CredentialRotation\Service\RotateCredentialService;

/**
 * Processes all credentials with scheduled rotation policies that are due.
 *
 * Run via cron: bin/cake msp_vault rotation:run_scheduled
 * Recommended schedule: hourly or daily depending on shortest rotation interval.
 */
class RotateScheduledCredentialsCommand extends Command
{
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription('Rotate all credentials that are scheduled and due for rotation.');

        return $parser;
    }

    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $io->out('MSP-Vault: Running scheduled credential rotation...');

        /** @var \Passbolt\CredentialRotation\Model\Table\CredentialRotationPoliciesTable $policiesTable */
        $policiesTable = TableRegistry::getTableLocator()->get('Passbolt/CredentialRotation.CredentialRotationPolicies');
        $service = new RotateCredentialService();

        $due = $policiesTable->findDueForRotation();
        $count = 0;

        foreach ($due as $policy) {
            try {
                $service->rotateScheduled($policy->resource_id);
                $io->out("  Rotated: {$policy->resource_id}");
                $count++;
            } catch (\Throwable $e) {
                $io->error("  Failed to rotate {$policy->resource_id}: {$e->getMessage()}");
            }
        }

        $io->success("Done. {$count} credential(s) rotation initiated.");

        return self::CODE_SUCCESS;
    }
}
