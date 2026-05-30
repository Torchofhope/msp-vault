<?php
declare(strict_types=1);

namespace Passbolt\SuperOpsSync\Service;

use Cake\Http\Client;
use Cake\Http\Exception\BadRequestException;

/**
 * HTTP client wrapper for the SuperOps REST API.
 *
 * All requests are authenticated with the org's API key via Bearer token.
 * Base URL is configurable per org (defaults to https://app.superops.com/api).
 *
 * SuperOps API reference: https://developer.superops.com/
 */
class SuperOpsApiClient
{
    private Client $http;
    private string $baseUrl;

    public function __construct(string $apiKey, string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = new Client([
            'headers' => [
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /** @return array[] */
    public function getCustomers(): array
    {
        return $this->get('/clients');
    }

    /** @return array[] */
    public function getTechnicians(): array
    {
        return $this->get('/technicians');
    }

    /** @return array[] */
    public function getAssets(string $customerId): array
    {
        return $this->get("/assets?clientId={$customerId}");
    }

    /**
     * Push updated user/license count to a SuperOps customer record.
     * SuperOps tracks this as a custom field for billing purposes.
     */
    public function updateCustomerLicenseCount(string $customerId, int $userCount): bool
    {
        $response = $this->http->patch(
            "{$this->baseUrl}/clients/{$customerId}",
            json_encode(['customFields' => ['mspVaultUserCount' => $userCount]])
        );

        return $response->isOk();
    }

    private function get(string $path): array
    {
        $response = $this->http->get($this->baseUrl . $path);

        if (!$response->isOk()) {
            throw new BadRequestException(__('SuperOps API error on {0}: {1}', $path, $response->getStatusCode()));
        }

        $data = $response->getJson();

        // SuperOps wraps results in 'data' or returns arrays directly
        return $data['data'] ?? $data ?? [];
    }
}
