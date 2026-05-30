<?php
declare(strict_types=1);

namespace Passbolt\SuperOpsSync\Model\Entity;

use Cake\ORM\Entity;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $api_key_encrypted
 * @property string $base_url
 * @property string|null $superops_customer_id
 * @property bool $sync_customers
 * @property bool $sync_technicians
 * @property bool $sync_assets
 * @property bool $push_license_count
 * @property \Cake\I18n\DateTime|null $last_synced
 * @property bool $is_active
 */
class SuperOpsSyncConfig extends Entity
{
    protected array $_accessible = [
        'base_url' => true,
        'superops_customer_id' => true,
        'sync_customers' => true,
        'sync_technicians' => true,
        'sync_assets' => true,
        'push_license_count' => true,
        'is_active' => true,
        'api_key_encrypted' => true,
        'modified_by' => true,
    ];

    protected array $_hidden = ['api_key_encrypted'];
}
