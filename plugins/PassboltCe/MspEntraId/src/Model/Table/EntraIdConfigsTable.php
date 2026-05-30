<?php
declare(strict_types=1);

namespace Passbolt\MspEntraId\Model\Table;

use Cake\Core\Configure;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use Passbolt\MspEntraId\Model\Entity\EntraIdConfig;

class EntraIdConfigsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('entra_id_configs');
        $this->setEntityClass(EntraIdConfig::class);
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
        $this->addBehavior('Uuid');

        $this->belongsTo('Organizations', [
            'className' => 'Passbolt/MultiTenant.Organizations',
            'foreignKey' => 'organization_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->uuid('id')->requirePresence('id', 'create')->notEmptyString('id');
        $validator->uuid('organization_id')->requirePresence('organization_id', 'create')->notEmptyString('organization_id');
        $validator->scalar('tenant_id')->maxLength('tenant_id', 100)->requirePresence('tenant_id', 'create')->notEmptyString('tenant_id');
        $validator->scalar('client_id')->maxLength('client_id', 100)->requirePresence('client_id', 'create')->notEmptyString('client_id');
        $validator->scalar('client_secret_encrypted')->requirePresence('client_secret_encrypted', 'create')->notEmptyString('client_secret_encrypted');
        $validator->inList('email_claim', EntraIdConfig::VALID_EMAIL_CLAIMS)->notEmptyString('email_claim');
        $validator->boolean('sso_enabled');
        $validator->boolean('scim_enabled');

        return $validator;
    }

    public function findByOrganizationId(string $orgId): ?EntraIdConfig
    {
        return $this->find()
            ->where(['organization_id' => $orgId, 'is_active' => true])
            ->first();
    }

    /**
     * Encrypt a plaintext secret for storage.
     * Uses AES-256-GCM with the app's Security.salt as key material.
     */
    public function encryptSecret(string $plaintext): string
    {
        $key = substr(hash('sha256', Configure::read('Security.salt'), true), 0, 32);
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return base64_encode($iv . $tag . $encrypted);
    }

    /**
     * Decrypt a stored secret.
     */
    public function decryptSecret(string $encrypted): string
    {
        $key = substr(hash('sha256', Configure::read('Security.salt'), true), 0, 32);
        $decoded = base64_decode($encrypted);
        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $ciphertext = substr($decoded, 28);

        return openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    }
}
