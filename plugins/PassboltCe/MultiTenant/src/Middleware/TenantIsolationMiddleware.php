<?php
declare(strict_types=1);

namespace Passbolt\MultiTenant\Middleware;

use Cake\ORM\TableRegistry;
use Passbolt\MultiTenant\Model\Behavior\TenantScopeBehavior;
use Passbolt\MultiTenant\Model\Entity\OrganizationUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resolves the current tenant from the request and configures TenantScopeBehavior.
 *
 * Tenant resolution order:
 *   1. Subdomain: client1.mspvault.com → slug = "client1"
 *   2. X-MSP-Organization header (for API clients / testing)
 *
 * After resolving the org, checks the authenticated user's MSP role:
 *   - msp_super_admin → bypass scoping (sees all orgs)
 *   - others          → scope to their org (or deny if no match)
 *
 * The resolved organization is stored on the request as attribute
 * 'organization' for use in controllers.
 */
class TenantIsolationMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        TenantScopeBehavior::reset();

        $slug = $this->resolveSlug($request);
        $organization = null;

        if ($slug !== null) {
            /** @var \Passbolt\MultiTenant\Model\Table\OrganizationsTable $orgsTable */
            $orgsTable = TableRegistry::getTableLocator()->get('Passbolt/MultiTenant.Organizations');
            $organization = $orgsTable->findBySlug($slug);
        }

        // Check if the authenticated user is an MSP super admin.
        $identity = $request->getAttribute('identity');
        if ($identity !== null) {
            $userId = $identity->getIdentifier();
            /** @var \Passbolt\MultiTenant\Model\Table\OrganizationUsersTable $orgUsersTable */
            $orgUsersTable = TableRegistry::getTableLocator()->get('Passbolt/MultiTenant.OrganizationUsers');

            if ($orgUsersTable->isMspSuperAdmin($userId)) {
                TenantScopeBehavior::bypassScoping();
                $request = $request->withAttribute('msp_super_admin', true);
                $request = $request->withAttribute('organization', $organization);

                return $handler->handle($request);
            }
        }

        if ($organization !== null) {
            TenantScopeBehavior::setCurrentOrganization($organization->id);
            $request = $request->withAttribute('organization', $organization);
            $request = $request->withAttribute('msp_super_admin', false);
        }

        return $handler->handle($request);
    }

    /**
     * Extract organization slug from subdomain or header.
     */
    private function resolveSlug(ServerRequestInterface $request): ?string
    {
        // Try X-MSP-Organization header first (API / dev use)
        $header = $request->getHeaderLine('X-MSP-Organization');
        if ($header !== '') {
            return strtolower(trim($header));
        }

        // Try subdomain: client1.mspvault.com → "client1"
        $host = $request->getUri()->getHost();
        $parts = explode('.', $host);
        if (count($parts) >= 3) {
            // First segment is the tenant slug
            return strtolower($parts[0]);
        }

        return null;
    }
}
