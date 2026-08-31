<?php

namespace App\Service;

use PDO;
use RuntimeException;
use Throwable;

trait DocumentGeneratorAccessTrait
{
    private static function activeExecutorWhere(): string
    {
        return " WHERE c.status='active' AND c.deleted_at IS NULL
                  AND ct.status='active' AND ct.deleted_at IS NULL
                  AND d.status='active' AND d.deleted_at IS NULL
                  AND dvb.status='active' AND dvb.deleted_at IS NULL
                  AND vs.status='active' AND vs.deleted_at IS NULL
                  AND vu1.id IS NOT NULL AND vu1.status='active' AND vu1.deleted_at IS NULL
                  AND (vu2.id IS NULL OR (vu2.status='active' AND vu2.deleted_at IS NULL))";
    }

    private static function executorSelectSql(): string
    {
        return "SELECT c.id crew_id,c.created_by_user_id created_by_user_id,c.created_by_user_id crew_created_by_user_id,c.driver_vehicle_block_id,
                       (SELECT COUNT(*) FROM crew_drivers cd WHERE cd.crew_id=c.id) driver_count,
                       ct.id contractor_id,ct.created_by_user_id contractor_created_by_user_id,ct.name contractor_name,ct.inn contractor_inn,ct.kpp contractor_kpp,ct.ogrn contractor_ogrn,ct.contractor_type,ct.legal_address contractor_legal_address,ct.physical_address contractor_physical_address,ct.bank_account contractor_bank_account,ct.bank_name contractor_bank_name,ct.bank_bik contractor_bank_bik,ct.bank_corr_account contractor_bank_corr_account,ct.director_full_name contractor_director_full_name,ct.director_position contractor_director_position,
                       dvb.created_by_user_id dvb_created_by_user_id,d.id driver_id,d.created_by_user_id driver_created_by_user_id,d.full_name driver_full_name,d.passport_number,d.passport_issued_by,d.passport_issue_date,d.license_number,d.license_issue_date,
                       vs.id vehicle_set_id,vs.created_by_user_id vehicle_set_created_by_user_id,vu1.id primary_vehicle_unit_id,vu1.brand primary_brand,vu1.model primary_model,vu1.plate_number primary_plate,vu2.id secondary_vehicle_unit_id,vu2.brand secondary_brand,vu2.model secondary_model,vu2.plate_number secondary_plate
                FROM crews c JOIN contractors ct ON ct.id=c.contractor_id JOIN driver_vehicle_blocks dvb ON dvb.id=c.driver_vehicle_block_id JOIN drivers d ON d.id=dvb.driver_id JOIN vehicle_sets vs ON vs.id=dvb.vehicle_set_id LEFT JOIN vehicle_units vu1 ON vu1.id=vs.primary_vehicle_unit_id LEFT JOIN vehicle_units vu2 ON vu2.id=vs.secondary_vehicle_unit_id";
    }

    private static function loadExecutorForAccess(PDO $pdo, int $crewId, int $userId, string $role): ?array
    {
        if ($crewId <= 0) return null;
        $stmt=$pdo->prepare(self::executorSelectSql() . ' WHERE c.id=? LIMIT 1');
        $stmt->execute([$crewId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) return null;
        if (in_array($role,['company_owner','senior_logist'],true)) return $row;
        if ($role==='logist' && $userId>0 && AccessControlService::hasRouteExecutorAccess($pdo,$row,$userId,'view')) return $row;
        return null;
    }

    private static function loadFeoRequests(PDO $pdo, array $ids): array
    {
        if ($ids===[]) return [];
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $stmt=$pdo->prepare("SELECT zayavka_id,mass_netto,naim_oo_gruzootpravitel,mno_adres_pogruzki,kontakt_tel,tel_dopolnitelnyy,kontakt_email,adres_vygruzki_otkhodov FROM feo_requests WHERE zayavka_id IN ({$ph})");
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function buildSnapshot(array $company, array $executor, array $requests, array $params, array $template): array
    {
        $primaryModel=trim(implode(' ',array_filter([(string)($executor['primary_brand'] ?? ''),(string)($executor['primary_model'] ?? '')])));
        $secondaryModel=trim(implode(' ',array_filter([(string)($executor['secondary_brand'] ?? ''),(string)($executor['secondary_model'] ?? '')])));
        $driverPassport=trim((string)($executor['passport_number'] ?? ''));
        if ($driverPassport!=='') {
            $issuer=trim((string)($executor['passport_issued_by'] ?? ''));
            $date=self::formatDateSimple((string)($executor['passport_issue_date'] ?? ''));
            if ($issuer!=='' || $date!=='') $driverPassport .= ', выдан ' . trim($issuer . ($date!=='' ? ' ' . $date . ' г.' : ''));
        }
        $driverLicense=trim((string)($executor['license_number'] ?? ''));
        $licenseDate=self::formatDateSimple((string)($executor['license_issue_date'] ?? ''));
        if ($driverLicense!=='' && $licenseDate!=='') $driverLicense .= ', выдано ' . $licenseDate . ' г.';

        $contractorName=(string)($executor['contractor_name'] ?? '');
        $contractorSignatory=trim((string)($executor['contractor_director_full_name'] ?? '')) ?: $contractorName;
        return [
            'company'=>[
                'id'=>(int)($company['id'] ?? 0),'name'=>(string)($company['name'] ?? ''),'inn'=>(string)($company['inn'] ?? ''),'kpp'=>(string)($company['kpp'] ?? ''),'ogrn'=>(string)($company['ogrn'] ?? ''),
                'legal_address'=>(string)($company['legal_address'] ?? ''),'physical_address'=>(string)($company['physical_address'] ?? ''),'bank_name'=>(string)($company['bank_name'] ?? ''),'bank_account'=>(string)($company['bank_account'] ?? ''),'bank_corr_account'=>(string)($company['bank_corr_account'] ?? ''),'bank_bik'=>(string)($company['bank_bik'] ?? ''),'contact_phone'=>(string)($company['contact_phone'] ?? ''),'contact_email'=>(string)($company['contact_email'] ?? ''),'director_full_name'=>(string)($company['director_full_name'] ?? ''),'director_position'=>(string)($company['director_position'] ?? ''),
            ],
            'executor'=>[
                'crew_id'=>(int)($executor['crew_id'] ?? 0),'contractor_id'=>(int)($executor['contractor_id'] ?? 0),'name'=>$contractorName,'inn'=>(string)($executor['contractor_inn'] ?? ''),'kpp'=>(string)($executor['contractor_kpp'] ?? ''),'ogrn'=>(string)($executor['contractor_ogrn'] ?? ''),'contractor_type'=>(string)($executor['contractor_type'] ?? ''),'legal_address'=>(string)($executor['contractor_legal_address'] ?? ''),'physical_address'=>(string)($executor['contractor_physical_address'] ?? ''),'bank_name'=>(string)($executor['contractor_bank_name'] ?? ''),'bank_account'=>(string)($executor['contractor_bank_account'] ?? ''),'bank_corr_account'=>(string)($executor['contractor_bank_corr_account'] ?? ''),'bank_bik'=>(string)($executor['contractor_bank_bik'] ?? ''),'director_full_name'=>(string)($executor['contractor_director_full_name'] ?? ''),'director_position'=>(string)($executor['contractor_director_position'] ?? ''),'signature_name'=>self::signatureName($contractorSignatory),
            ],
            'driver'=>[
                'id'=>(int)($executor['driver_id'] ?? 0),'full_name'=>(string)($executor['driver_full_name'] ?? ''),'passport_number'=>(string)($executor['passport_number'] ?? ''),'passport_issued_by'=>(string)($executor['passport_issued_by'] ?? ''),'passport_issue_date'=>(string)($executor['passport_issue_date'] ?? ''),'passport'=>$driverPassport,'license_number'=>(string)($executor['license_number'] ?? ''),'license_issue_date'=>(string)($executor['license_issue_date'] ?? ''),'license'=>$driverLicense,'signature_name'=>self::signatureName((string)($executor['driver_full_name'] ?? '')),
            ],
            'vehicle'=>['id'=>(int)($executor['primary_vehicle_unit_id'] ?? 0),'model'=>$primaryModel,'plate'=>(string)($executor['primary_plate'] ?? '')],
            'trailer'=>['id'=>(int)($executor['secondary_vehicle_unit_id'] ?? 0),'model'=>$secondaryModel,'plate'=>(string)($executor['secondary_plate'] ?? ''),'display'=>trim(implode(', ',array_filter([$secondaryModel,(string)($executor['secondary_plate'] ?? '')])))],
            'requests'=>array_values($requests),
            'parameters'=>self::normalizeParameters((string)$template['document_type'],$params),
            'template'=>['id'=>(int)$template['template_id'],'version_id'=>(int)$template['version_id'],'version_no'=>(int)$template['version_no'],'name'=>(string)$template['template_name'],'document_type'=>(string)$template['document_type'],'sha256'=>(string)$template['sha256']],
        ];
    }
}
