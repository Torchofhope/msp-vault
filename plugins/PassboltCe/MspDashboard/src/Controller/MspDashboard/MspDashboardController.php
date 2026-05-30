<?php
declare(strict_types=1);

namespace Passbolt\MspDashboard\Controller\MspDashboard;

use App\Controller\AppController;
use Cake\Http\Exception\ForbiddenException;
use Passbolt\MspDashboard\Service\MspDashboardStatsService;
use Passbolt\MultiTenant\Model\Behavior\TenantScopeBehavior;

/**
 * MSP super-admin dashboard.
 *
 * GET /msp-vault/dashboard
 *
 * Returns all organizations with:
 *   - name, slug, plan_type, is_active
 *   - user_count, resource_count
 *   - last_activity timestamp
 *   - vault_status: active | inactive | empty
 *
 * Restricted to msp_super_admin role only.
 */
class MspDashboardController extends AppController
{
    public function index(): void
    {
        $this->assertJson();

        // Only MSP super admins can see the cross-tenant dashboard
        $isSuperAdmin = $this->request->getAttribute('msp_super_admin', false);
        if (!$isSuperAdmin) {
            throw new ForbiddenException(__('Access restricted to MSP super administrators.'));
        }

        // Bypass tenant scoping so stats queries see all orgs
        TenantScopeBehavior::bypassScoping();

        $service = new MspDashboardStatsService();
        $stats = $service->getStats();

        // Restore scoping for safety
        TenantScopeBehavior::reset();

        $this->success(__('The operation was successful.'), $stats);
    }
}
