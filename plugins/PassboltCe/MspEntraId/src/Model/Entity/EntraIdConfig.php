<?php
declare(strict_types=1);

namespace Passbolt\MspEntraId\Model\Entity;

use Cake\ORM\Entity;

/**
 * Per-organization Entra ID configuration.
 *
 * client_secret_encrypted and scim_bearer_token_encrypted hold
 * AES-256-GCM encrypted values. Decryption is handled by
 * EntraIdConfigsTable::decryptSecret().
 *
 * @property string $id
 * @property string $organization_id
 * @property string $tenant_id
 * @property string $client_id
 * @property string $client_secret_encrypted
 * @property \Cake\I18n\DateTime|null $client_secret_expiry
 * @property string|null $scim_bearer_token_encrypted
 * @property string $email_claim
 * @property bool $sso_enabled
 * @property bool $scim_enabled
 * @property bool $is_active
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
class EntraIdConfig extends Entity
{
    public const EMAIL_CLAIM_EMAIL = 'email';
    public const EMAIL_CLAIM_UPN = 'upn';
    public const EMAIL_CLAIM_PREFERRED_USERNAME = 'preferred_username';

    public const VALID_EMAIL_CLAIMS = [
        self::EMAIL_CLAIM_EMAIL,
        self::EMAIL_CLAIM_UPN,
        self::EMAIL_CLAIM_PREFERRED_USERNAME,
    ];

    /** Microsoft OIDC authorize endpoint template. */
    public const AUTHORIZE_URL = 'https://login.microsoftonline.com/{tenant_id}/oauth2/v2.0/authorize';
    /** Microsoft OIDC token endpoint template. */
    public const TOKEN_URL = 'https://login.microsoftonline.com/{tenant_id}/oauth2/v2.0/token';

    protected array $_accessible = [
        'tenant_id' => true,
        'client_id' => true,
        'client_secret_encrypted' => true,
        'client_secret_expiry' => true,
        'scim_bearer_token_encrypted' => true,
        'email_claim' => true,
        'sso_enabled' => true,
        'scim_enabled' => true,
        'is_active' => true,
        'modified_by' => true,
    ];

    /** @var array Never expose encrypted secrets in serialization. */
    protected array $_hidden = [
        'client_secret_encrypted',
        'scim_bearer_token_encrypted',
    ];

    public function getAuthorizeUrl(): string
    {
        return str_replace('{tenant_id}', $this->tenant_id, self::AUTHORIZE_URL);
    }

    public function getTokenUrl(): string
    {
        return str_replace('{tenant_id}', $this->tenant_id, self::TOKEN_URL);
    }
}
