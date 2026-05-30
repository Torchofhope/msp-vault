<?php
declare(strict_types=1);

namespace Passbolt\SuperOpsSync\Model\Table;

use Cake\Core\Configure;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use Passbolt\SuperOpsSync\Model\Entity\SuperOpsSyncConfig;

class SuperOpsSyncConfigsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('superops_sync_configs');
        $this->setEntityClass(SuperOpsSyncConfig::class);
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        $this->belongsTo('Organizations', [
            'className' => 'Passbolt/MultiTenant.Organizations',
            'foreignKey' => 'organization_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator->uuid('id')->requirePresence('id', 'create')->notEmptyString('id');
        $validator->uuid('organization_id')->requirePresence('organization_id', 'create')->notEmptyString('organization_id');
        $validator->scalar('api_key_encrypted')->requirePresence('api_key_encrypted', 'create')->notEmptyString('api_key_encrypted');
        $validator->url('base_url')->notEmptyString('base_url');

        return $validator;
    }

    public function findByOrganizationId(string $orgId): ?SuperOpsSyncConfig
    {
        return $this->find()->where(['organization_id' => $orgId, 'is_active' => true])->first();
    }

    public function findAllActive(): iterable
    {
        return $this->find()->where(['is_active' => true])->all();
    }

    public function encryptApiKey(string $plaintext): string
    {
        $key = substr(hash('sha256', Configure::read('Security.salt'), true), 0, 32);
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return base64_encode($iv . $tag . $encrypted);
    }

    public function decryptApiKey(string $encrypted): string
    {
        $key = substr(hash('sha256', Configure::read('Security.salt'), true), 0, 32);
        $decoded = base64_decode($encrypted);
        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $ciphertext = substr($decoded, 28);

        return openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    }
}
