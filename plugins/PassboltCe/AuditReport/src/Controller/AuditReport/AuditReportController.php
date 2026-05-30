<?php
declare(strict_types=1);

namespace Passbolt\AuditReport\Controller\AuditReport;

use App\Controller\AppController;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;
use Cake\I18n\DateTime;
use Passbolt\AuditReport\Service\AuditReportService;
use Passbolt\MultiTenant\Model\Behavior\TenantScopeBehavior;
use Passbolt\MultiTenant\Model\Entity\OrganizationUser;

/**
 * Exportable per-client audit reports.
 *
 * GET /msp-vault/audit/report?format=json          — JSON (default)
 * GET /msp-vault/audit/report?format=csv           — CSV download
 * GET /msp-vault/audit/report?from=2025-01-01&to=2025-12-31
 *
 * Optional query params:
 *   organization_id — MSP super admins can specify any org;
 *                     others are scoped to their own org automatically.
 *   from            — ISO 8601 date lower bound (inclusive)
 *   to              — ISO 8601 date upper bound (inclusive)
 *   format          — 'json' (default) or 'csv'
 *
 * Access: client_admin or msp_super_admin only.
 */
class AuditReportController extends AppController
{
    public function index(): void
    {
        $this->assertJson();

        $orgUsersTable = $this->fetchTable('Passbolt/MultiTenant.OrganizationUsers');
        $orgUser = $orgUsersTable->findForUser($this->User->id());

        $isSuperAdmin = $this->request->getAttribute('msp_super_admin', false);
        $isClientAdmin = $orgUser && in_array($orgUser->msp_role, [
            OrganizationUser::MSP_SUPER_ADMIN,
            OrganizationUser::CLIENT_ADMIN,
        ], true);

        if (!$isSuperAdmin && !$isClientAdmin) {
            throw new ForbiddenException(__('Access restricted to administrators.'));
        }

        // Resolve which org to report on
        $requestedOrgId = $this->request->getQuery('organization_id');

        if ($isSuperAdmin && $requestedOrgId) {
            $organizationId = $requestedOrgId;
            TenantScopeBehavior::bypassScoping();
        } elseif ($orgUser && $orgUser->organization_id) {
            $organizationId = $orgUser->organization_id;
        } else {
            throw new BadRequestException(__('Could not determine organization for report.'));
        }

        // Parse optional date range
        $from = $this->parseDateParam($this->request->getQuery('from'));
        $to = $this->parseDateParam($this->request->getQuery('to'));

        $service = new AuditReportService();
        $rows = $service->buildReport($organizationId, $from, $to);

        TenantScopeBehavior::reset();

        $format = $this->request->getQuery('format', 'json');

        if ($format === 'csv') {
            $csv = $service->toCsv($rows);
            $this->response = $this->response
                ->withType('text/csv')
                ->withHeader('Content-Disposition', 'attachment; filename="msp-vault-audit-report.csv"')
                ->withStringBody($csv);
            return;
        }

        $this->success(__('The operation was successful.'), $rows);
    }

    private function parseDateParam(?string $value): ?DateTime
    {
        if (empty($value)) {
            return null;
        }
        try {
            return new DateTime($value);
        } catch (\Throwable) {
            throw new BadRequestException(__('Invalid date format. Use ISO 8601, e.g. 2025-01-01.'));
        }
    }
}
