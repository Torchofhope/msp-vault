<?php
declare(strict_types=1);

namespace Passbolt\SuperOpsSync;

use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Passbolt\SuperOpsSync\Command\SuperOpsSyncCommand;

class SuperOpsSyncPlugin extends BasePlugin
{
    public function console(CommandCollection $commands): CommandCollection
    {
        $commands->add('msp_vault superops:sync', SuperOpsSyncCommand::class);

        return $commands;
    }
}
