<?php
declare(strict_types=1);

namespace Passbolt\MspEntraId\Service;

use Cake\Http\Client;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\InternalErrorException;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Passbolt\MspEntraId\Model\Entity\EntraIdConfig;

/**
 * Handles the per-org Microsoft Entra ID OIDC flow.
 *
 * Flow:
 *   1. buildAuthorizationUrl(): generate Microsoft login URL + store state/nonce
 *   2. User authenticates with Microsoft
 *   3. Microsoft POSTs back to /msp-vault/entra/callback with code + state
 *   4. exchangeCodeForToken(): trade code for id_token
 *   5. extractEmailFromToken(): get the user's email from the JWT claims
 *
 * The state/nonce are stored in entra_id_oidc_state and validated in step 3
 * to prevent CSRF and replay attacks.
 */
class EntraIdOidcService
{
    private const REDIRECT_URI_SUFFIX = '/msp-vault/entra/callback';

    /**
     * Build the Microsoft authorization URL and persist the OIDC state.
     *
     * @param \Passbolt\MspEntraId\Model\Entity\EntraIdConfig $config
     * @param string $baseUrl Application full base URL (e.g. https://client1.mspvault.com)
     * @return string The URL to redirect the user to
     */
    public function buildAuthorizationUrl(EntraIdConfig $config, string $baseUrl): string
    {
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));

        // Persist state for callback validation
        $stateTable = TableRegistry::getTableLocator()->get('Passbolt/MspEntraId.EntraIdOidcState');
        $record = $stateTable->newEntity([
            'organization_id' => $config->organization_id,
            'state' => $state,
            'nonce' => $nonce,
            'expires' => new DateTime('+10 minutes'),
        ], ['accessibleFields' => ['*' => true]]);
        $stateTable->saveOrFail($record);

        $params = http_build_query([
            'client_id' => $config->client_id,
            'response_type' => 'code',
            'redirect_uri' => rtrim($baseUrl, '/') . self::REDIRECT_URI_SUFFIX,
            'response_mode' => 'query',
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
        ]);

        return $config->getAuthorizeUrl() . '?' . $params;
    }

    /**
     * Validate state, exchange authorization code for tokens.
     *
     * @return array Decoded id_token claims
     */
    public function handleCallback(string $code, string $state, string $baseUrl): array
    {
        // Validate and consume state
        $stateTable = TableRegistry::getTableLocator()->get('Passbolt/MspEntraId.EntraIdOidcState');
        $stateRecord = $stateTable->find()
            ->where(['state' => $state, 'expires >' => new DateTime()])
            ->first();

        if ($stateRecord === null) {
            throw new BadRequestException(__('Invalid or expired OIDC state. Please try again.'));
        }

        $orgId = $stateRecord->organization_id;
        $nonce = $stateRecord->nonce;

        // Delete consumed state (prevents replay)
        $stateTable->delete($stateRecord);

        // Load org's Entra ID config
        $configsTable = TableRegistry::getTableLocator()->get('Passbolt/MspEntraId.EntraIdConfigs');
        $config = $configsTable->findByOrganizationId($orgId);
        if ($config === null) {
            throw new InternalErrorException(__('Entra ID configuration not found for this organization.'));
        }

        $clientSecret = $configsTable->decryptSecret($config->client_secret_encrypted);

        // Exchange code for tokens
        $http = new Client();
        $response = $http->post($config->getTokenUrl(), [
            'client_id' => $config->client_id,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => rtrim($baseUrl, '/') . self::REDIRECT_URI_SUFFIX,
            'grant_type' => 'authorization_code',
        ]);

        if (!$response->isOk()) {
            throw new BadRequestException(__('Failed to exchange authorization code with Microsoft.'));
        }

        $tokens = $response->getJson();
        $claims = $this->decodeJwtClaims($tokens['id_token'] ?? '');

        // Validate nonce to prevent replay attacks
        if (($claims['nonce'] ?? '') !== $nonce) {
            throw new BadRequestException(__('OIDC nonce mismatch. Potential replay attack detected.'));
        }

        $claims['_organization_id'] = $orgId;
        $claims['_config'] = $config;

        return $claims;
    }

    /**
     * Extract user email from token claims based on the configured email_claim.
     */
    public function extractEmail(array $claims, EntraIdConfig $config): string
    {
        $claim = $config->email_claim;
        $email = $claims[$claim] ?? $claims['email'] ?? $claims['preferred_username'] ?? null;

        if (empty($email)) {
            throw new BadRequestException(__('Could not extract email from Entra ID token. Check the email_claim configuration.'));
        }

        return strtolower(trim($email));
    }

    /**
     * Decode JWT claims without signature verification.
     * Signature is verified implicitly by Microsoft issuing the token in response
     * to our authenticated token request (code + client_secret).
     */
    private function decodeJwtClaims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new BadRequestException(__('Invalid id_token format.'));
        }

        $payload = base64_decode(str_pad(
            strtr($parts[1], '-_', '+/'),
            strlen($parts[1]) % 4 === 0 ? strlen($parts[1]) : strlen($parts[1]) + (4 - strlen($parts[1]) % 4),
            '='
        ));

        return json_decode($payload, true) ?? [];
    }
}
