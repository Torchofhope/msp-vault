<?php
declare(strict_types=1);

namespace Passbolt\MspEntraId\Controller\EntraId;

use App\Controller\AppController;
use Cake\Core\Configure;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\NotFoundException;
use Passbolt\MspEntraId\Service\EntraIdOidcService;

/**
 * Initiates the per-org Entra ID OIDC login flow.
 *
 * GET /msp-vault/entra/login
 *
 * Resolves the organization from the request (via TenantIsolationMiddleware),
 * looks up that org's Entra ID configuration, and redirects the user to
 * the Microsoft login page.
 *
 * The org is identified by subdomain (client1.mspvault.com) or
 * X-MSP-Organization header — same resolution as TenantIsolationMiddleware.
 */
class EntraIdLoginController extends AppController
{
    public function login(): void
    {
        $organization = $this->request->getAttribute('organization');
        if ($organization === null) {
            throw new BadRequestException(__('Could not resolve organization for Entra ID login.'));
        }

        $configsTable = $this->fetchTable('Passbolt/MspEntraId.EntraIdConfigs');
        $config = $configsTable->findByOrganizationId($organization->id);

        if ($config === null || !$config->sso_enabled) {
            throw new NotFoundException(__('Entra ID SSO is not configured for this organization.'));
        }

        $baseUrl = Configure::read('App.fullBaseUrl');
        $service = new EntraIdOidcService();
        $authUrl = $service->buildAuthorizationUrl($config, $baseUrl);

        $this->response = $this->response->withLocation($authUrl);
    }
}
