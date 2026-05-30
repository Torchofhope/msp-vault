<?php
declare(strict_types=1);

namespace Passbolt\CredentialRotation\Model\Entity;

use Cake\ORM\Entity;

/**
 * @property string $id
 * @property string $resource_id
 * @property string $rotation_type
 * @property int|null $interval_days
 * @property \Cake\I18n\DateTime|null $last_rotated
 * @property \Cake\I18n\DateTime|null $next_rotation
 * @property bool $notify_on_rotation
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property string $created_by
 * @property string $modified_by
 */
class CredentialRotationPolicy extends Entity
{
    public const TYPE_MANUAL = 'manual';
    public const TYPE_SCHEDULED = 'scheduled';

    public const VALID_TYPES = [self::TYPE_MANUAL, self::TYPE_SCHEDULED];

    protected array $_accessible = [
        'rotation_type' => true,
        'interval_days' => true,
        'notify_on_rotation' => true,
        'next_rotation' => true,
        'last_rotated' => true,
        'modified_by' => true,
    ];
}
