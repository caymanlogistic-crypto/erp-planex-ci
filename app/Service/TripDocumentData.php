<?php
declare(strict_types=1);
namespace App\Service;
use PDO;

/** Reads existing cards. Never creates shadow copies of contractor/driver/vehicle records. */
final class TripDocumentData
{
    public function __construct(private readonly PDO $central,private readonly PDO $tenant,private readonly int $companyId) {}

    public function member(int $membershipId): array
    {
        $q=$this->central->prepare("SELECT m.id membership_id,m.account_id,a.full_name,a.login FROM company_memberships m JOIN user_accounts a ON a.id=m.account_id WHERE m.id=? AND m.company_id=? AND m.status='active' AND a.status='active'");
        $q->execute([$membershipId,$this->companyId]);
        return $q->fetch(PDO::FETCH_ASSOC)?:throw new \DomainException('Сотрудник недоступен в этой компании.');
    }

    public function memberByLogin(string $login): array
    {
        $q=$this->central->prepare("SELECT m.id FROM company_memberships m JOIN user_accounts a ON a.id=m.account_id WHERE a.normalized_login=? AND m.company_id=? AND m.status='active' AND a.status='active'");
        $q->execute([$login,$this->companyId]);
        $ids=$q->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids)!==1) throw new \DomainException('Не найден единственный активный сотрудник '.$login.' в этой компании.');
        return $this->member((int)$ids[0]);
    }

    public static function tripTable(string $type): string
    {
        return match($type) {'waste'=>'waste_trips',default=>throw new \DomainException('Запрос документов доступен только для перевозки отходов.')};
    }

    public static function shortPersonName(string $name): string
    {
        $parts=preg_split('/\s+/u',trim(preg_replace('/\s+/u',' ',$name)??$name))?:[];
        if (!$parts) return '';
        $surname=array_shift($parts);
        $initials='';
        foreach (array_slice($parts,0,2) as $part) {
            if (preg_match_all('/([\p{L}])\./u',$part,$matches) && $matches[1]) {
                foreach ($matches[1] as $letter) $initials.=mb_strtoupper($letter).'.';
                continue;
            }
            $letter=mb_substr($part,0,1);
            if ($letter!=='') $initials.=mb_strtoupper($letter).'.';
        }
        return trim($surname.($initials!==''?' '.$initials:''));
    }

    public static function shortPersonNames(string $names): string
    {
        $result=[];
        foreach (preg_split('/\s*\/\s*/u',trim($names))?:[] as $name) {
            $short=self::shortPersonName($name);
            if ($short!=='') $result[]=$short;
        }
        return implode(' / ',$result);
    }

    public static function carrierDisplayName(string $name): string
    {
        $name=trim(preg_replace('/\s+/u',' ',$name)??$name);
        if (preg_match('/^(?:Индивидуальный\s+предприниматель|ИП)\s+(.+)$/iu',$name,$match)) {
            return 'ИП '.self::shortPersonName($match[1]);
        }
        return $name;
    }

    public static function tripLabel(array $trip): string
    {
        $label='#'.trim((string)($trip['number']??$trip['business_id']??$trip['id']??''));
        $comment=trim((string)($trip['comment']??''));
        if ($comment!=='') $label.=' '.$comment;
        $details=array_values(array_filter([
            self::shortPersonNames((string)($trip['driver_names']??'')),
            self::carrierDisplayName((string)($trip['contractor_name']??'')),
        ],static fn(string $value):bool=>$value!==''));
        return $label.($details?' - '.implode(' - ',$details):'');
    }

    public function packageRecipientByLogin(string $login): array
    {
        $member=$this->memberByLogin($login);
        $q=$this->central->prepare('SELECT email FROM user_accounts WHERE id=?');$q->execute([$member['account_id']]);
        $email=strtolower(trim((string)$q->fetchColumn()));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new \DomainException('У '.$login.' не заполнен основной email в карточке сотрудника.');
        return ['source'=>'account','account_id'=>(int)$member['account_id'],'login'=>$login,'email'=>$email];
    }

    public function trip(string $type,int $id): array
    {
        self::tripTable($type);
        $q=$this->tenant->prepare('SELECT * FROM waste_trips WHERE id=? AND deleted_at IS NULL');
        $q->execute([$id]); $trip=$q->fetch(PDO::FETCH_ASSOC);
        if (!$trip) throw new \DomainException('Рейс не найден.');
        $q=$this->tenant->prepare('SELECT membership_id FROM users WHERE id=? AND deleted_at IS NULL');
        $q->execute([$trip['responsible_user_id']]); $membership=(int)$q->fetchColumn();
        $responsible=$this->member($membership);
        $trip['responsible']=$responsible;
        $trip['number']=(string)$trip['business_id'];
        $trip['type']=$type;
        $trip['contractor_name']='';
        $trip['driver_names']='';
        if ((int)($trip['route_executor_id']??0)>0) {
            $q=$this->tenant->prepare("SELECT ct.name contractor_name,COALESCE(NULLIF(GROUP_CONCAT(d.full_name ORDER BY cd.position SEPARATOR ' / '),''),fd.full_name,'') driver_names FROM crews c LEFT JOIN contractors ct ON ct.id=c.contractor_id AND ct.deleted_at IS NULL LEFT JOIN crew_drivers cd ON cd.crew_id=c.id LEFT JOIN drivers d ON d.id=cd.driver_id AND d.deleted_at IS NULL LEFT JOIN driver_vehicle_blocks dvb ON dvb.id=c.driver_vehicle_block_id AND dvb.deleted_at IS NULL LEFT JOIN drivers fd ON fd.id=dvb.driver_id AND fd.deleted_at IS NULL WHERE c.id=? AND c.deleted_at IS NULL GROUP BY c.id,ct.name,fd.full_name");
            $q->execute([(int)$trip['route_executor_id']]);
            $executor=$q->fetch(PDO::FETCH_ASSOC)?:[];
            $trip['contractor_name']=(string)($executor['contractor_name']??'');
            $trip['driver_names']=(string)($executor['driver_names']??'');
        }
        if ($trip['contractor_name']==='' && (int)($trip['carrier_contractor_id']??0)>0) {
            $q=$this->tenant->prepare('SELECT name FROM contractors WHERE id=? AND deleted_at IS NULL');
            $q->execute([(int)$trip['carrier_contractor_id']]);
            $trip['contractor_name']=(string)$q->fetchColumn();
        }
        $trip['label']=self::tripLabel($trip);
        $trip['loading_dates']=(string)($trip['planned_start_date']??'')?:trim(($trip['planned_start_date_from']??'').' — '.($trip['planned_start_date_to']??''),' —');
        $trip['points']=[];
        {
            $q=$this->tenant->prepare('SELECT DISTINCT f.mno_adres_pogruzki address_text FROM waste_trip_requests w JOIN feo_requests f ON f.id=w.feo_request_id WHERE w.waste_trip_id=? AND w.is_active=1 ORDER BY f.mno_adres_pogruzki');
            $q->execute([$id]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $point) $trip['points'][]=['point_type'=>'loading']+$point;
            if (!empty($trip['utilizer_id'])) {
                $q=$this->tenant->prepare('SELECT address FROM feo_utilizers WHERE id=?'); $q->execute([$trip['utilizer_id']]);
                $trip['points'][]=['point_type'=>'unloading','address_text'=>(string)$q->fetchColumn()];
            } elseif ($trip['destination_type']==='warehouse') $trip['points'][]=['point_type'=>'unloading','address_text'=>'Склад'];
        }
        return array_intersect_key($trip,array_flip(['id','type','number','label','status','route_executor_id','carrier_contractor_id','responsible','loading_dates','points']));
    }

    public function cards(array $trip): array
    {
        $crew=$this->row('crews',(int)($trip['route_executor_id']??0));
        $block=$this->row('driver_vehicle_blocks',(int)($crew['driver_vehicle_block_id']??0));
        $set=$this->row('vehicle_sets',(int)($block['vehicle_set_id']??0));
        $contractor=$this->row('contractors',(int)($crew['contractor_id']??$trip['carrier_contractor_id']??0));
        $drivers=[];
        if ($crew) {
            $q=$this->tenant->prepare('SELECT driver_id FROM crew_drivers WHERE crew_id=? ORDER BY position,driver_id');
            $q->execute([$crew['id']]); $ids=$q->fetchAll(PDO::FETCH_COLUMN);
            if (!$ids && !empty($block['driver_id'])) $ids=[$block['driver_id']];
            foreach (array_unique($ids) as $id) {
                $driver=$this->row('drivers',(int)$id);
                if (!$driver) continue;
                $q=$this->tenant->prepare('SELECT phone,is_main FROM driver_phones WHERE driver_id=? ORDER BY is_main DESC,id');
                $q->execute([$id]); $driver['phones']=$q->fetchAll(PDO::FETCH_ASSOC);
                $drivers[]=$driver;
            }
        }
        $vehicles=[];
        foreach (['primary_vehicle_unit_id','secondary_vehicle_unit_id'] as $key) {
            $unit=$this->row('vehicle_units',(int)($set[$key]??0));
            if ($unit) {$unit['part']=$key==='primary_vehicle_unit_id'?'Машина':'Прицеп'; $vehicles[]=$unit;}
        }
        $contacts=[];
        if ($contractor) {
            $q=$this->tenant->prepare('SELECT * FROM contractor_contacts WHERE contractor_id=? ORDER BY is_document_email DESC,is_primary DESC,id');
            $q->execute([$contractor['id']]); $contacts=$q->fetchAll(PDO::FETCH_ASSOC);
            $q=$this->tenant->prepare('SELECT tax_system,vat_mode FROM contractor_tax_history WHERE contractor_id=? AND (effective_from IS NULL OR effective_from<=CURRENT_DATE) ORDER BY effective_from DESC,id DESC LIMIT 1');
            $q->execute([$contractor['id']]); $contractor+=($q->fetch(PDO::FETCH_ASSOC)?:[]);
        }
        $documents=[];
        $objects=[];
        if ($contractor) $objects[]=['contractor',$contractor['id']];
        foreach ($drivers as $driver) $objects[]=['driver',$driver['id']];
        foreach ($vehicles as $unit) $objects[]=['vehicle_unit',$unit['id']];
        foreach ($objects as [$type,$id]) {
            $q=$this->tenant->prepare('SELECT d.*,t.code type_code FROM documents d LEFT JOIN document_types t ON t.id=d.document_type_id WHERE d.entity_type=? AND d.entity_id=? AND d.deleted_at IS NULL ORDER BY d.id');
            $q->execute([$type,$id]); array_push($documents,...$q->fetchAll(PDO::FETCH_ASSOC));
        }
        return compact('crew','set','contractor','drivers','vehicles','contacts','documents');
    }

    private function row(string $table,int $id): ?array
    {
        if ($id<=0) return null;
        $sql=match($table){
            'crews'=>'SELECT * FROM crews WHERE id=? AND deleted_at IS NULL',
            'driver_vehicle_blocks'=>'SELECT * FROM driver_vehicle_blocks WHERE id=? AND deleted_at IS NULL',
            'vehicle_sets'=>'SELECT * FROM vehicle_sets WHERE id=? AND deleted_at IS NULL',
            'contractors'=>'SELECT * FROM contractors WHERE id=? AND deleted_at IS NULL',
            'drivers'=>'SELECT * FROM drivers WHERE id=? AND deleted_at IS NULL',
            'vehicle_units'=>'SELECT * FROM vehicle_units WHERE id=? AND deleted_at IS NULL',
            default=>throw new \LogicException('Unknown document card type')
        };
        $q=$this->tenant->prepare($sql);
        $q->execute([$id]); return $q->fetch(PDO::FETCH_ASSOC)?:null;
    }
}
