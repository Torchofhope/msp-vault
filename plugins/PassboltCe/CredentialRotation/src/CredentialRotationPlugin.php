<?php
declare(strict_types=1);

namespace Passbolt\CredentialRotation;

use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Passbolt\CredentialRotation\Command\RotateScheduledCredentialsCommand;

class CredentialRotationPlugin extends BasePlugin
{
    public function console(CommandCollection $commands): CommandCollection
    {
        $commands->add('msp_vault rotation:run_scheduled', RotateScheduledCredentialsCommand::class);

        return $commands;
    }
}
