<?php
declare(strict_types=1);

namespace Passbolt\CredentialEscrow\Model\Entity;

use Cake\ORM\Entity;

/**
 * @property string $id
 * @property string $resource_id
 * @property string $requester_id
 * @property string $organization_id
 * @property string $reason
 * @property string $status
 * @property string|null $reviewed_by
 * @property \Cake\I18n\DateTime|null $reviewed_at
 * @property string|null $reviewer_note
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
class EscrowRequest extends Entity
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DENIED = 'denied';

    public const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_DENIED,
    ];

    /** Default access window granted on approval (in hours). */
    public const DEFAULT_ACCESS_HOURS = 4;

    protected array $_accessible = [
        'resource_id' => true,
        'requester_id' => true,
        'organization_id' => true,
        'reason' => true,
        'status' => true,
        'reviewed_by' => true,
        'reviewed_at' => true,
        'reviewer_note' => true,
    ];
}
