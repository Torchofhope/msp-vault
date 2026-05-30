<?php
declare(strict_types=1);

namespace Passbolt\MspEntraId\Controller\EntraId;

use App\Controller\AppController;
use Cake\Core\Configure;
use Cake\Http\Exception\BadRequestException;
use Passbolt\MspEntraId\Service\EntraIdOidcService;
use Passbolt\MspEntraId\Service\EntraIdUserProvisioningService;
use Passbolt\MultiTenant\Model\Behavior\TenantScopeBehavior;

/**
 * Handles the Microsoft OIDC callback after user authentication.
 *
 * GET /msp-vault/entra/callback?code=...&state=...
 *
 * Steps:
 *   1. Validate state (CSRF protection)
 *   2. Exchange code for id_token via Microsoft token endpoint
 *   3. Extract user email from token claims
 *   4. Find or create the user in this org's vault (JIT provisioning)
 *   5. Log the user in via passbolt's session mechanism
 *   6. Redirect to the vault UI
 */
class EntraIdCallbackController extends AppController
{
    public function callback(): void
    {
        $code = $this->request->getQuery('code');
        $state = $this->request->getQuery('state');
        $error = $this->request->getQuery('error');

        if ($error) {
            throw new BadRequestException(__('Microsoft authentication failed: {0}', $this->request->getQuery('error_description', $error)));
        }

        if (empty($code) || empty($state)) {
            throw new BadRequestException(__('Missing code or state in Entra ID callback.'));
        }

        $baseUrl = Configure::read('App.fullBaseUrl');
        $oidcService = new EntraIdOidcService();

        // Step 1-2: validate state, exchange code for token
        $claims = $oidcService->handleCallback($code, $state, $baseUrl);
        $orgId = $claims['_organization_id'];
        $config = $claims['_config'];

        // Step 3: extract email
        $email = $oidcService->extractEmail($claims, $config);

        // Step 4: find or create user (JIT provisioning)
        TenantScopeBehavior::setCurrentOrganization($orgId);
        $provisioningService = new EntraIdUserProvisioningService();
        $user = $provisioningService->findOrProvisionFromOidc($claims, $email, $orgId);

        // Step 5: establish passbolt session
        // Write the user identity into the session the same way passbolt's
        // auth flow does — the authentication plugin reads from 'Auth.User'.
        $this->request->getSession()->write('Auth.User', ['id' => $user->id]);

        // Step 6: redirect to vault
        $this->response = $this->response->withLocation('/app');
    }
}
