<?php
declare(strict_types=1);

namespace Passbolt\Devices\Controller\Devices;

use App\Controller\AppController;

class DevicesIndexController extends AppController
{
    public array $paginate = [
        'sortableFields' => ['Devices.name', 'Devices.device_type', 'Devices.created', 'Devices.modified'],
        'order' => ['Devices.name' => 'asc'],
    ];

    public function index(): void
    {
        $this->assertJson();

        /** @var \Passbolt\Devices\Model\Table\DevicesTable $devicesTable */
        $devicesTable = $this->fetchTable('Passbolt/Devices.Devices');

        $query = $devicesTable->find()
            ->where(['Devices.deleted' => false])
            ->contain(['Creator.Profiles', 'Modifier.Profiles']);

        // Filter by device type if requested
        $type = $this->request->getQuery('filter.type');
        if ($type) {
            $query->where(['Devices.device_type' => $type]);
        }

        // Search by name/hostname
        $search = $this->request->getQuery('filter.search');
        if ($search) {
            $query->where(['OR' => [
                'Devices.name LIKE' => '%' . $search . '%',
                'Devices.hostname LIKE' => '%' . $search . '%',
                'Devices.ip_address LIKE' => '%' . $search . '%',
            ]]);
        }

        $devices = $this->paginate($query)->items()->toArray();
        $this->success(__('The operation was successful.'), $devices);
    }
}
