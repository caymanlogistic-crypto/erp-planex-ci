param(
    [Parameter(Mandatory)][string]$TaskId,
    [switch]$ProbeCleanupFailure
)

$ErrorActionPreference = 'Stop'
$worktree = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).ProviderPath
$localRoot = Join-Path (Split-Path (Split-Path $worktree -Parent) -Parent) 'local'
Import-Module (Join-Path $localRoot 'Planex.Local.psm1') -Force
Assert-PlanexTaskId -TaskId $TaskId
$worktree = Resolve-PlanexWorktree -Worktree $worktree

$outerAction = {
    $aDb = $env:PLANEX_TEST_FIXED_DB
    $aUser = $env:PLANEX_SCHEMA_DB_USER
    $aPassword = $env:PLANEX_SCHEMA_DB_PASSWORD
    $innerAction = {
        $bDb = $env:PLANEX_TEST_FIXED_DB
        if ($aDb -eq $bDb) { throw 'Dual-schema leases must be distinct' }
        $newValues = @{
            PLANEX_TEST_MYSQL_DB_A = $aDb
            PLANEX_TEST_MYSQL_USER_A = $aUser
            PLANEX_TEST_MYSQL_PASSWORD_A = $aPassword
            PLANEX_TEST_MYSQL_DB_B = $bDb
            PLANEX_TEST_MYSQL_USER_B = $env:PLANEX_SCHEMA_DB_USER
            PLANEX_TEST_MYSQL_PASSWORD_B = $env:PLANEX_SCHEMA_DB_PASSWORD
            PLANEX_TEST_MYSQL_DUAL_LEASES = "$aDb,$bDb"
        }
        $oldValues = @{}
        foreach ($key in $newValues.Keys) {
            $oldValues[$key] = [Environment]::GetEnvironmentVariable($key, 'Process')
            [Environment]::SetEnvironmentVariable($key, $newValues[$key], 'Process')
        }
        try {
            if ($env:PLANEX_DUAL_SCHEMA_PROBE_FAILURE -eq '1') { throw 'DUAL_SCHEMA_CLEANUP_PROBE' }
            $php = (Get-PlanexToolchain).Php
            $innerWorktree = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).ProviderPath
            Push-Location $innerWorktree
            try {
                & $php 'tests/finance_closing_documents_contract_test.php'
                if ($LASTEXITCODE -ne 0) { throw "Closing Documents contract failed (exit $LASTEXITCODE)" }
            } finally {
                Pop-Location
            }
        } finally {
            foreach ($key in $oldValues.Keys) {
                [Environment]::SetEnvironmentVariable($key, $oldValues[$key], 'Process')
            }
        }
    }.GetNewClosure()
    Invoke-PlanexIsolatedTestDatabase -TaskId $TaskId -Worktree $worktree -Action $innerAction
}.GetNewClosure()

$oldProbe = [Environment]::GetEnvironmentVariable('PLANEX_DUAL_SCHEMA_PROBE_FAILURE', 'Process')
try {
    if ($ProbeCleanupFailure) { $env:PLANEX_DUAL_SCHEMA_PROBE_FAILURE = '1' }
    Invoke-PlanexIsolatedTestDatabase -TaskId $TaskId -Worktree $worktree -Action $outerAction
} finally {
    [Environment]::SetEnvironmentVariable('PLANEX_DUAL_SCHEMA_PROBE_FAILURE', $oldProbe, 'Process')
}
