<?php
declare(strict_types=1);

namespace Passbolt\Devices\Controller\Devices;

use App\Controller\AppController;
use Cake\Http\Exception\NotFoundException;

class DevicesDeleteController extends AppController
{
    public function delete(string $id): void
    {
        $this->assertJson();

        /** @var \Passbolt\Devices\Model\Table\DevicesTable $devicesTable */
        $devicesTable = $this->fetchTable('Passbolt/Devices.Devices');

        $device = $devicesTable->find()->where(['id' => $id, 'deleted' => false])->first();
        if ($device === null) {
            throw new NotFoundException(__('The device does not exist or has been deleted.'));
        }

        $device->set('deleted', true);
        $device->set('modified_by', $this->User->id());
        $devicesTable->saveOrFail($device);

        $this->success(__('The device has been deleted successfully.'));
    }
}
