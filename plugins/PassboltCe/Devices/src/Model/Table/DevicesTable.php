<?php
declare(strict_types=1);

namespace Passbolt\Devices\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use Passbolt\Devices\Model\Entity\Device;

class DevicesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('devices');
        $this->setEntityClass(Device::class);
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');
        $this->addBehavior('Uuid');
        $this->addBehavior('Passbolt/MultiTenant.TenantScope');

        $this->hasMany('DeviceCredentials', [
            'className' => 'Passbolt/Devices.DeviceCredentials',
            'foreignKey' => 'device_id',
        ]);

        $this->belongsTo('Creator', [
            'className' => 'Users',
            'bindingKey' => 'created_by',
            'foreignKey' => 'id',
        ]);

        $this->belongsTo('Modifier', [
            'className' => 'Users',
            'bindingKey' => 'modified_by',
            'foreignKey' => 'id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->uuid('id')->requirePresence('id', 'create')->notEmptyString('id');
        $validator->scalar('name')->maxLength('name', 255)->requirePresence('name', 'create')->notEmptyString('name');
        $validator->inList('device_type', Device::VALID_TYPES)->notEmptyString('device_type');
        $validator->scalar('hostname')->maxLength('hostname', 255)->allowEmptyString('hostname');
        $validator->scalar('ip_address')->maxLength('ip_address', 45)->allowEmptyString('ip_address');
        $validator->scalar('operating_system')->maxLength('operating_system', 100)->allowEmptyString('operating_system');
        $validator->scalar('manufacturer')->maxLength('manufacturer', 100)->allowEmptyString('manufacturer');
        $validator->scalar('model')->maxLength('model', 100)->allowEmptyString('model');
        $validator->scalar('serial_number')->maxLength('serial_number', 100)->allowEmptyString('serial_number');
        $validator->scalar('notes')->allowEmptyString('notes');
        $validator->scalar('superops_asset_id')->maxLength('superops_asset_id', 100)->allowEmptyString('superops_asset_id');

        return $validator;
    }

    /**
     * Find a device with all its linked credentials (resources).
     */
    public function findProfile(string $deviceId): ?Device
    {
        return $this->find()
            ->where(['Devices.id' => $deviceId, 'Devices.deleted' => false])
            ->contain([
                'DeviceCredentials' => [
                    'Resources' => ['Creator.Profiles', 'Modifier.Profiles'],
                ],
                'Creator.Profiles',
                'Modifier.Profiles',
            ])
            ->first();
    }

    /**
     * Find devices by SuperOps asset ID (for sync upsert).
     */
    public function findBySuperopsAssetId(string $assetId): ?Device
    {
        return $this->find()
            ->where(['superops_asset_id' => $assetId, 'deleted' => false])
            ->first();
    }
}
