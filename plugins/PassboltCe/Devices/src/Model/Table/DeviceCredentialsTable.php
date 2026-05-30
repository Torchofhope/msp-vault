<?php
declare(strict_types=1);

namespace Passbolt\Devices\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class DeviceCredentialsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('device_credentials');
        $this->setPrimaryKey('id');
        $this->addBehavior('Uuid');
        $this->addBehavior('Timestamp', ['events' => ['Model.beforeSave' => ['created' => 'new']]]);

        $this->belongsTo('Devices', [
            'className' => 'Passbolt/Devices.Devices',
            'foreignKey' => 'device_id',
        ]);

        $this->belongsTo('Resources', [
            'className' => 'Resources',
            'foreignKey' => 'resource_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->uuid('id')->requirePresence('id', 'create')->notEmptyString('id');
        $validator->uuid('device_id')->requirePresence('device_id', 'create')->notEmptyString('device_id');
        $validator->uuid('resource_id')->requirePresence('resource_id', 'create')->notEmptyString('resource_id');
        $validator->uuid('created_by')->requirePresence('created_by', 'create')->notEmptyString('created_by');

        return $validator;
    }
}
