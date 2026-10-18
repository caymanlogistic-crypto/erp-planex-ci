<?php
/**
 * Browser filter state is scoped to the server-authorized identity and grants.
 * It is presentation state only; the server still authorizes every request.
 */
$registryUiStateScope = hash('sha256', json_encode([
    'account' => (int) ($_SESSION['account_id'] ?? 0),
    'membership' => (int) ($_SESSION['membership_id'] ?? 0),
    'role' => (string) ($_SESSION['role_code'] ?? ''),
    'company' => (int) ($companyId ?? $company['id'] ?? $_SESSION['company_id'] ?? 0),
    'permissions' => $_SESSION['permissions'] ?? [],
    'data_scopes' => $_SESSION['data_scopes'] ?? [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
