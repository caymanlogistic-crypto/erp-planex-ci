<?php

namespace App\Http\Controllers\Company;

use App\Core\Database;
use App\Security\AccessRuntime;
use App\Security\PageRegistry;
use App\Service\CompanyMembershipService;
use App\Service\CompanyRoleService;

final class RolesAccessController
{
    public function __construct(private readonly Database $db)
    {
    }

    public function snapshot(): void
    {
        $this->execute(function (array $payload, int $companyId): array {
            $pdo = $this->db->connection();
            return [
                'roles' => (new CompanyRoleService($pdo))->listForCompany($companyId),
                'memberships' => (new CompanyMembershipService($pdo))->listForCompany($companyId),
                'regulated_entities' => CompanyRoleService::regulatedEntities(),
            ];
        });
    }

    public function createRole(): void
    {
        $this->execute(function (array $payload, int $companyId): array {
            return ['role' => (new CompanyRoleService($this->db->connection()))->create($companyId, (string) ($payload['name'] ?? ''))];
        }, 201);
    }

    public function copyRole(string $id): void
    {
        $this->execute(function (array $payload, int $companyId) use ($id): array {
            $service = new CompanyRoleService($this->db->connection());
            return ['role' => $service->copy($companyId, $this->positiveId($id), (string) ($payload['name'] ?? ''), (new PageRegistry())->pageKeys())];
        }, 201);
    }

    public function saveRole(string $id): void
    {
        $this->execute(function (array $payload, int $companyId) use ($id): array {
            (new CompanyRoleService($this->db->connection()))->save(
                $companyId,
                $this->positiveId($id),
                (string) ($payload['name'] ?? ''),
                is_array($payload['pages'] ?? null) ? $payload['pages'] : [],
                is_array($payload['data_scopes'] ?? null) ? $payload['data_scopes'] : [],
            );
            return ['saved' => true];
        });
    }

    public function deleteRole(string $id): void
    {
        $this->execute(function (array $payload, int $companyId) use ($id): array {
            (new CompanyRoleService($this->db->connection()))->delete($companyId, $this->positiveId($id));
            return ['deleted' => true];
        });
    }

    public function addMembership(): void
    {
        $this->execute(function (array $payload, int $companyId): array {
            $result = (new CompanyMembershipService($this->db->connection()))->add(
                $companyId,
                $this->positiveId($payload['role_id'] ?? 0),
                (string) ($payload['full_name'] ?? ''),
                (string) ($payload['login'] ?? ''),
                isset($payload['password']) ? (string) $payload['password'] : null,
                isset($payload['email']) ? (string) $payload['email'] : null,
                isset($payload['phone']) ? (string) $payload['phone'] : null,
                (string) ($payload['status'] ?? 'active'),
            );
            return ['membership' => $result];
        }, 201);
    }

    public function saveMembership(string $id): void
    {
        $this->execute(function (array $payload, int $companyId) use ($id): array {
            (new CompanyMembershipService($this->db->connection()))->save(
                $companyId,
                $this->positiveId($id),
                $this->positiveId($payload['role_id'] ?? 0),
                (string) ($payload['status'] ?? ''),
                is_array($payload['page_overrides'] ?? null) ? $payload['page_overrides'] : [],
                is_array($payload['data_scope_overrides'] ?? null) ? $payload['data_scope_overrides'] : [],
            );
            return ['saved' => true];
        });
    }

    public function removeMembership(string $id): void
    {
        $this->execute(function (array $payload, int $companyId) use ($id): array {
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
            jsonResponse(['ok' => true] + $operation($payload, $companyId), $successStatus);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\DomainException $e) {
            jsonResponse(['ok' => false, 'error' => $e->getMessage()], 409);
        }
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
