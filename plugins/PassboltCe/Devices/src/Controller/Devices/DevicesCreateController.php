<?php
declare(strict_types=1);

namespace Passbolt\Devices\Controller\Devices;

use App\Controller\AppController;
use Cake\Http\Exception\BadRequestException;
use Passbolt\Devices\Model\Entity\Device;

class DevicesCreateController extends AppController
{
    public function create(): void
    {
        $this->assertJson();
        $this->assertNotEmptyRequestData();

        /** @var \Passbolt\Devices\Model\Table\DevicesTable $devicesTable */
        $devicesTable = $this->fetchTable('Passbolt/Devices.Devices');

        $data = $this->request->getData();
        $data['created_by'] = $this->User->id();
        $data['modified_by'] = $this->User->id();

        $device = $devicesTable->newEntity($data, [
            'accessibleFields' => [
                'name' => true, 'device_type' => true, 'hostname' => true,
                'ip_address' => true, 'operating_system' => true, 'manufacturer' => true,
                'model' => true, 'serial_number' => true, 'notes' => true,
                'superops_asset_id' => true, 'created_by' => true, 'modified_by' => true,
            ],
        ]);

        if ($device->getErrors()) {
            throw new BadRequestException(__('Could not validate device data.'));
        }

        if (!$devicesTable->save($device)) {
            throw new BadRequestException(__('Could not save the device.'));
        }

        $this->success(__('The device has been added successfully.'), $device);
    }
}
