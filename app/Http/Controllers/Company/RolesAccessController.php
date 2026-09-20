<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Security\AccessRuntime;
use App\Security\PageRegistry;
use App\Service\CompanyMembershipService;
use App\Service\CompanyRoleService;
use App\Service\EmployeeMailProfileService;

final class RolesAccessController
{
    public function __construct(private readonly array $config, private readonly Database $db)
    {
    }

    public function index(): void
    {
        $companyId = $this->ownerCompanyId();
        if ($companyId === null) {
            return;
        }

        $rolesAccessInitialState = $this->snapshotPayload($companyId);
        $rolesAccessInitialStateJson = json_encode(
            $rolesAccessInitialState,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?: '{}';
        $pageTree = $rolesAccessInitialState['page_tree'];
        $pageList = $rolesAccessInitialState['pages'];
        $roles = $rolesAccessInitialState['roles'];
        $memberships = $rolesAccessInitialState['memberships'];
        $regulatedEntities = $rolesAccessInitialState['regulated_entities'];
        $pageTitle = 'Роли и доступы';
        $pageContext = 'Настройки › Роли и доступы';
        $topbarCrumbs = [
            ['label' => 'НАСТРОЙКИ', 'url' => null],
            ['label' => 'Роли и доступы', 'url' => null],
        ];
        $config = $this->config;
        $db = $this->db;

        ob_start();
        require base_path('app/View/pages/company/roles_access.php');
        $content = ob_get_clean();
        require base_path('app/View/layouts/main.php');
    }

    public function snapshot(): void
    {
        $this->execute(fn (#[\SensitiveParameter] array $payload, int $companyId): array => $this->snapshotPayload($companyId));
    }

    public function createRole(): void
    {
        $this->execute(function (#[\SensitiveParameter] array $payload, int $companyId): array {
            return ['role' => (new CompanyRoleService($this->db->connection()))->create($companyId, (string) ($payload['name'] ?? ''))];
        }, 201);
    }

    public function copyRole(string $id): void
    {
        $this->execute(function (#[\SensitiveParameter] array $payload, int $companyId) use ($id): array {
            $service = new CompanyRoleService($this->db->connection());
            return ['role' => $service->copy($companyId, $this->positiveId($id), (string) ($payload['name'] ?? ''), (new PageRegistry())->grantablePageKeys())];
        }, 201);
    }

    public function saveRole(string $id): void
    {
        $this->execute(function (#[\SensitiveParameter] array $payload, int $companyId) use ($id): array {
            $registry = new PageRegistry();
            (new CompanyRoleService($this->db->connection()))->save(
                $companyId,
                $this->positiveId($id),
                (string) ($payload['name'] ?? ''),
                is_array($payload['pages'] ?? null) ? $payload['pages'] : [],
                is_array($payload['data_scopes'] ?? null) ? $payload['data_scopes'] : [],
                isset($payload['default_page_key']) ? (string)$payload['default_page_key'] : null,
                $registry->grantablePageKeys(),
                array_values(array_map(static fn(array $page): string => (string)$page['key'], array_filter(
                    $registry->landingPages(),
                    static fn(array $page): bool => (bool)($page['grantable'] ?? true)
                ))),
            );
            return ['saved' => true];
        });
    }

    public function deleteRole(string $id): void
    {
        $this->execute(function (#[\SensitiveParameter] array $payload, int $companyId) use ($id): array {
            (new CompanyRoleService($this->db->connection()))->delete($companyId, $this->positiveId($id));
            return ['deleted' => true];
        });
    }

    public function addMembership(): void
    {
        $this->execute(function (#[\SensitiveParameter] array $payload, int $companyId): array {
            $result = (new CompanyMembershipService($this->db->connection()))->add(
                $companyId,
                $this->positiveId($payload['role_id'] ?? 0),
                (string) ($payload['full_name'] ?? ''),
                (string) ($payload['login'] ?? ''),
                isset($payload['password']) ? (string) $payload['password'] : null,
                isset($payload['email']) ? (string) $payload['email'] : null,
                isset($payload['phone']) ? (string) $payload['phone'] : null,
                (string) ($payload['status'] ?? 'active'),
                isset($payload['max_user_id']) ? (string)$payload['max_user_id'] : null,
                array_key_exists('max_user_id', $payload),
            );
            $this->saveWorkMail($payload,$companyId,(int)$result['account_id']);
            return ['membership' => $result];
        }, 201);
    }

    public function saveMembership(string $id): void
    {
        $this->execute(function (#[\SensitiveParameter] array $payload, int $companyId) use ($id): array {
            $q=$this->db->connection()->prepare('SELECT account_id FROM company_memberships WHERE id=? AND company_id=?');
            $q->execute([$this->positiveId($id),$companyId]);
            $accountId=(int)$q->fetchColumn();
            $this->saveWorkMail($payload,$companyId,$accountId);
            $registry = new PageRegistry();
            (new CompanyMembershipService($this->db->connection()))->save(
                $companyId,
                $this->positiveId($id),
                $this->positiveId($payload['role_id'] ?? 0),
                (string) ($payload['status'] ?? ''),
                is_array($payload['page_overrides'] ?? null) ? $payload['page_overrides'] : [],
                is_array($payload['data_scope_overrides'] ?? null) ? $payload['data_scope_overrides'] : [],
                $registry->grantablePageKeys(),
                isset($payload['max_user_id']) ? (string)$payload['max_user_id'] : null,
                array_key_exists('max_user_id', $payload),
            );
            return ['saved' => true];
        });
    }

    public function removeMembership(string $id): void
    {
        $this->execute(function (#[\SensitiveParameter] array $payload, int $companyId) use ($id): array {
            (new CompanyMembershipService($this->db->connection()))->remove($companyId, $this->positiveId($id));
            return ['removed' => true];
        });
    }

    /** @param callable(array<string,mixed>,int):array<string,mixed> $operation */
    private function execute(callable $operation, int $successStatus = 200): void
    {
        if (!AccessRuntime::current()->isSystemOwner()) {
            jsonResponse(['ok' => false, 'error' => 'Доступ запрещён.'], 403);
            return;
        }
        $companyId = AccessRuntime::current()->companyId() ?? 0;
        if ($companyId < 1) {
            jsonResponse(['ok' => false, 'error' => 'Компания не определена.'], 403);
            return;
        }
        try {
            $payload = array_replace($_POST, requestJsonBody());
            $transaction=array_key_exists('work_mail',$payload);
            if ($transaction) $this->db->connection()->beginTransaction();
            $result=$operation($payload,$companyId);
            if ($transaction) $this->db->connection()->commit();
            jsonResponse(['ok' => true] + $result, $successStatus);
        } catch (\InvalidArgumentException $e) {
            if ($this->db->connection()->inTransaction()) $this->db->connection()->rollBack();
            jsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\DomainException $e) {
            if ($this->db->connection()->inTransaction()) $this->db->connection()->rollBack();
            jsonResponse(['ok' => false, 'error' => $e->getMessage()], 409);
        } catch (\Throwable) {
            if ($this->db->connection()->inTransaction()) $this->db->connection()->rollBack();
            jsonResponse(['ok'=>false,'error'=>'Не удалось сохранить данные. Изменения не внесены. Попробуйте ещё раз.'],500);
        }
    }

    private function saveWorkMail(#[\SensitiveParameter] array $payload,int $companyId,int $accountId): void
    {
        if (!array_key_exists('work_mail',$payload)) return;
        if (!is_array($payload['work_mail'])) throw new \InvalidArgumentException('Некорректные данные рабочей почты.');
        $pdo=$this->db->connection();
        $q=$pdo->prepare('SELECT account_id FROM company_memberships WHERE id=? AND company_id=?');
        $q->execute([AccessRuntime::current()->membershipId(),$companyId]);
        (new EmployeeMailProfileService($pdo,$companyId))->saveBatch((int)$q->fetchColumn(),$accountId,$payload['work_mail']);
    }

    /** @return array<string,mixed> */
    private function snapshotPayload(int $companyId): array
    {
        $pdo = $this->db->connection();
        $registry = new PageRegistry();
        $roles = (new CompanyRoleService($pdo))->listForCompany($companyId, $registry->grantablePageKeys());

        return [
            'roles' => $roles,
            'memberships' => (new CompanyMembershipService($pdo))->listForCompany(
                $companyId,
                $registry->grantablePageKeys(),
                $roles
            ),
            'page_tree' => $registry->grantableTree(),
            'pages' => $registry->grantablePages(),
            'regulated_entities' => CompanyRoleService::regulatedEntities(),
        ];
    }

    private function ownerCompanyId(): ?int
    {
        if (!AccessRuntime::current()->isSystemOwner()) {
            http_response_code(403);
            echo '<div class="notice warn">Доступ запрещён.</div>';
            return null;
        }
        $companyId = AccessRuntime::current()->companyId() ?? 0;
        if ($companyId < 1) {
            http_response_code(403);
            echo '<div class="notice warn">Компания не определена.</div>';
            return null;
        }
        return $companyId;
    }

    private function positiveId(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new \InvalidArgumentException('Некорректный идентификатор.');
        }
        return (int) $id;
    }
}
