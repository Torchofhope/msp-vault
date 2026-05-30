<?php
declare(strict_types=1);

namespace Passbolt\Devices\Controller\Devices;

use App\Controller\AppController;
use Cake\Http\Exception\NotFoundException;

/**
 * Device profile: returns the device and all credentials linked to it.
 */
class DevicesViewController extends AppController
{
    public function view(string $id): void
    {
        $this->assertJson();

        /** @var \Passbolt\Devices\Model\Table\DevicesTable $devicesTable */
        $devicesTable = $this->fetchTable('Passbolt/Devices.Devices');

        $device = $devicesTable->findProfile($id);
        if ($device === null) {
            throw new NotFoundException(__('The device does not exist or has been deleted.'));
        }

        $this->success(__('The operation was successful.'), $device);
    }
}
