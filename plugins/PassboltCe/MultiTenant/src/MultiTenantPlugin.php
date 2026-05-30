<?php
declare(strict_types=1);

/**
 * MSP-Vault ~ Secure. Manage. Protect.
 *
 * Multi-tenant isolation plugin. Provides per-client vault isolation,
 * MSP super-admin cross-tenant access, and role-based tenant grants.
 *
 * @license https://opensource.org/licenses/AGPL-3.0 AGPL License
 */
namespace Passbolt\MultiTenant;

use Cake\Core\BasePlugin;
use Cake\Core\PluginApplicationInterface;
use Cake\Http\MiddlewareQueue;
use Passbolt\MultiTenant\Middleware\TenantIsolationMiddleware;

class MultiTenantPlugin extends BasePlugin
{
    /**
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue
     * @return \Cake\Http\MiddlewareQueue
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        $middlewareQueue->add(new TenantIsolationMiddleware());

        return $middlewareQueue;
    }

    /**
     * @param \Cake\Core\PluginApplicationInterface $app
     * @return void
     */
    public function bootstrap(PluginApplicationInterface $app): void
    {
        parent::bootstrap($app);
    }
}
