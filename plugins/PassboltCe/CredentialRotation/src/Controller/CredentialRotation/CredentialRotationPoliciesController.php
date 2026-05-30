<?php
declare(strict_types=1);

namespace Passbolt\CredentialRotation\Controller\CredentialRotation;

use App\Controller\AppController;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\NotFoundException;
use Passbolt\CredentialRotation\Model\Entity\CredentialRotationPolicy;
use Passbolt\CredentialRotation\Service\RotateCredentialService;

/**
 * Manages rotation policies for credentials and triggers manual rotation.
 *
 * GET    /msp-vault/rotation/:resource_id/policy  — view current policy
 * POST   /msp-vault/rotation/:resource_id/policy  — create or update policy
 * POST   /msp-vault/rotation/:resource_id/rotate  — trigger manual rotation now
 * GET    /msp-vault/rotation/:resource_id/history — view rotation history
 */
class CredentialRotationPoliciesController extends AppController
{
    public function viewPolicy(string $resourceId): void
    {
        $this->assertJson();

        /** @var \Passbolt\CredentialRotation\Model\Table\CredentialRotationPoliciesTable $table */
        $table = $this->fetchTable('Passbolt/CredentialRotation.CredentialRotationPolicies');
        $policy = $table->find()->where(['resource_id' => $resourceId])->first();

        $this->success(__('The operation was successful.'), $policy);
    }

    public function savePolicy(string $resourceId): void
    {
        $this->assertJson();
        $this->assertNotEmptyRequestData();

        /** @var \Passbolt\CredentialRotation\Model\Table\CredentialRotationPoliciesTable $table */
        $table = $this->fetchTable('Passbolt/CredentialRotation.CredentialRotationPolicies');

        $policy = $table->find()->where(['resource_id' => $resourceId])->first();
        $data = $this->request->getData();
        $data['resource_id'] = $resourceId;
        $data['modified_by'] = $this->User->id();

        if ($policy === null) {
            $data['created_by'] = $this->User->id();
            $policy = $table->newEntity($data, ['accessibleFields' => ['*' => true]]);
        } else {
            $table->patchEntity($policy, $data);
        }

        // Compute next_rotation for scheduled type
        if (($policy->rotation_type === CredentialRotationPolicy::TYPE_SCHEDULED) && $policy->interval_days) {
            if (empty($policy->next_rotation)) {
                $policy->set('next_rotation', (new \Cake\I18n\DateTime())->modify("+{$policy->interval_days} days"));
            }
        }

        if ($policy->getErrors()) {
            throw new BadRequestException(__('Could not validate rotation policy.'));
        }

        $table->saveOrFail($policy);
        $this->success(__('The rotation policy has been saved.'), $policy);
    }

    public function rotateNow(string $resourceId): void
    {
        $this->assertJson();

        $service = new RotateCredentialService();
        $service->rotateManual($resourceId, $this->User->getAccessControl());

        $this->success(__('Rotation has been initiated. Users with access have been notified.'));
    }

    public function viewHistory(string $resourceId): void
    {
        $this->assertJson();

        /** @var \Passbolt\CredentialRotation\Model\Table\CredentialRotationHistoryTable $table */
        $table = $this->fetchTable('Passbolt/CredentialRotation.CredentialRotationHistory');

        $history = $table->find()
            ->where(['resource_id' => $resourceId])
            ->contain(['TriggeredBy.Profiles'])
            ->orderBy(['created' => 'DESC'])
            ->all()
            ->toArray();

        $this->success(__('The operation was successful.'), $history);
    }
}
