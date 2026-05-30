<?php
declare(strict_types=1);

namespace Passbolt\Devices\Model\Entity;

use Cake\ORM\Entity;

/**
 * Represents a network-connected asset that credentials can be linked to.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $device_type
 * @property string|null $hostname
 * @property string|null $ip_address
 * @property string|null $operating_system
 * @property string|null $manufacturer
 * @property string|null $model
 * @property string|null $serial_number
 * @property string|null $notes
 * @property string|null $superops_asset_id
 * @property bool $deleted
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property string $created_by
 * @property string $modified_by
 * @property \Passbolt\Devices\Model\Entity\DeviceCredential[] $device_credentials
 */
class Device extends Entity
{
    public const TYPE_WORKSTATION = 'workstation';
    public const TYPE_SERVER = 'server';
    public const TYPE_SWITCH = 'switch';
    public const TYPE_FIREWALL = 'firewall';
    public const TYPE_ROUTER = 'router';
    public const TYPE_PRINTER = 'printer';
    public const TYPE_NAS = 'nas';
    public const TYPE_CAMERA = 'camera';
    public const TYPE_OTHER = 'other';

    public const VALID_TYPES = [
        self::TYPE_WORKSTATION,
        self::TYPE_SERVER,
        self::TYPE_SWITCH,
        self::TYPE_FIREWALL,
        self::TYPE_ROUTER,
        self::TYPE_PRINTER,
        self::TYPE_NAS,
        self::TYPE_CAMERA,
        self::TYPE_OTHER,
    ];

    protected array $_accessible = [
        'name' => true,
        'device_type' => true,
        'hostname' => true,
        'ip_address' => true,
        'operating_system' => true,
        'manufacturer' => true,
        'model' => true,
        'serial_number' => true,
        'notes' => true,
        'superops_asset_id' => true,
    ];
}
