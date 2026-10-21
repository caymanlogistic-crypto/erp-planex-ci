<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/scripts/feo_legacy_sync_configure.php';
$count=0;
$ok=static function(bool $value,string $message) use (&$count): void { if (!$value) throw new RuntimeException($message); $count++; };
feoLegacySyncLoadDependencies(dirname(__DIR__));
$previousKey = getenv('APP_ENCRYPTION_KEY');
putenv('APP_ENCRYPTION_KEY=synthetic-feo-sync-bootstrap-test');
try {
    $plain = 'synthetic-tenant-password';
    $encrypted = cryptoEncrypt($plain);
    $resolved = companyDatabaseConfig(['database'=>['database'=>'central','password'=>'unused']], ['db_identifier'=>'test_tenant','db_password'=>$encrypted]);
    $ok($resolved['database']==='test_tenant' && $resolved['password']===$plain, 'CLI dependencies resolve encrypted tenant credentials');
    $resolved = companyDatabaseConfig(['database'=>['database'=>'test_tenant']], ['db_password'=>$plain]);
    $ok($resolved['password']===$plain, 'Legacy plaintext credential compatibility preserved');
} finally { putenv($previousKey === false ? 'APP_ENCRYPTION_KEY' : 'APP_ENCRYPTION_KEY='.$previousKey); }
$original="# synthetic configuration\r\nAPP_ENV=production\r\nUNRELATED=preserve-exactly\r\n";
$configured=feoLegacySyncEnvironment($original,'test_tenant','test_legacy');
$ok(str_starts_with($configured,$original),'Existing environment bytes preserved');
$ok(substr_count($configured,'FEO_LEGACY_SYNC_DATABASE=')===1,'Exactly one legacy destination');
$ok(feoLegacySyncEnvironment($configured,'test_tenant','test_legacy')===$configured,'Configuration idempotent');
$encoded=base64_encode(json_encode(['host'=>'localhost','username'=>'synthetic','password'=>'synthetic-secret']));
$separate=feoLegacySyncEnvironment($configured,'test_tenant','test_legacy',$encoded);
$ok(str_contains($separate,'FEO_LEGACY_SYNC_CONNECTION_B64='.$encoded),'Separate credentials installed only in environment');
$ok(feoLegacySyncEnvironment($separate,'test_tenant','test_legacy',$encoded)===$separate,'Separate credentials idempotent');
$transfer=feoTransferSyncEnvironment($separate);
$ok(str_contains($transfer,'FEO_LEGACY_TRANSFER_SYNC_ENABLED=1'),'Transfer flag added');
$ok(feoTransferSyncEnvironment($transfer)===$transfer,'Transfer activation idempotent');
$ok(feoTransferSyncEnvironment($separate."FEO_LEGACY_TRANSFER_SYNC_ENABLED=0\n")===$transfer,'Disabled transfer flag can be activated');
$thrown=false; try { feoTransferSyncEnvironment($transfer."FEO_LEGACY_TRANSFER_SYNC_ENABLED=1\n"); } catch (RuntimeException) {$thrown=true;}
$ok($thrown,'Duplicate transfer activation rejected');
foreach ([['test_tenant','other_legacy'],['invalid;db','test_legacy'],['test_tenant','test_tenant']] as [$tenant,$legacy]) {
    $thrown=false; try { feoLegacySyncEnvironment($configured,$tenant,$legacy); } catch (RuntimeException) { $thrown=true; }
    $ok($thrown,'Wrong or changed destination rejected');
}
$thrown=false; try { feoLegacySyncEnvironment($configured."FEO_LEGACY_SYNC_DATABASE=test_legacy\n",'test_tenant','test_legacy'); } catch (RuntimeException) {$thrown=true;}
$ok($thrown,'Duplicate keys rejected');
$source=file_get_contents(dirname(__DIR__).'/scripts/feo_legacy_sync_configure.php');
$workflow=file_get_contents(dirname(__DIR__).'/.github/workflows/feo_legacy_import_sync_configuration.yml');
foreach (['if (PHP_SAPI !== \'cli\')',"'check','activate'",'$sync->validate()','FeoLegacyPlacementSync',"flock(\$handle, LOCK_EX)",'business_data_unchanged'] as $needle) $ok(str_contains($source,$needle),'Configuration guard: '.$needle);
$ok(!preg_match('/\b(?:INSERT INTO|UPDATE feo|DELETE FROM|GRANT |ALTER TABLE|CREATE TABLE)\b/i',$source),'Configuration never writes business data, schema or grants');
foreach (['CONFIGURE_FEO_IMPORT_SYNC','StrictHostKeyChecking=yes','erpv2-controlled-deploy','Exact canonical CI PASS required','Successful deployed SHA required','FEO_SYNC_COMPANY_ID',"\"\$COMPANY_ID\" -gt 0"] as $needle) $ok(str_contains($workflow,$needle),'Workflow guard: '.$needle);
$ok(str_contains($workflow,'ENABLE_FEO_TRANSFER_MIRROR'),'Separate transfer confirmation required');
echo "FEO_LEGACY_SYNC_CONFIGURATION: {$count} passed\n";
