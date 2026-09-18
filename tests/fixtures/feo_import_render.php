<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
function app_url(string $path): string { return $path; }
function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrfField(): string { return '<input type="hidden" name="_csrf_token" value="synthetic">'; }
$_SESSION = ['role_code'=>'company_owner'];
$dbError = null; $successFlash = null; $errorFlash = null; $imports = [];
$legacySyncConfigured = true;
$freshness = ['is_problem'=>false];
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/app.css"><link rel="stylesheet" href="/assets/css/erp-ui.css"></head><body><main style="padding:20px;max-width:1500px;margin:auto"><?php require $root . '/app/View/pages/company_feo_requests.php'; ?></main><script src="/assets/js/modal-shell.js"></script><script src="/assets/js/app.js"></script></body></html>
