<?php

namespace App\Service;

final class FeoUtilizerService
{
    public function listUtilizers(\PDO $pdo, bool $includeInactive=true): array
    {
        $sql='SELECT * FROM feo_utilizers'.($includeInactive?'':' WHERE is_active=1').' ORDER BY sort_order ASC,short_name ASC,id ASC';
        $rows=$pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC)?:[];
        $aliases=$this->aliasesByUtilizer($pdo);
        foreach($rows as &$row)$row['aliases']=$aliases[(int)$row['id']]??[];
        unset($row);
        return $rows;
    }

    public function createUtilizer(\PDO $pdo,array $input,int $userId): int
    {
        $data=$this->validateUtilizer($input); $aliases=$this->parseAliases($input['aliases']??'');
        $pdo->beginTransaction();
        try{
            $this->assertIdentityAvailable($pdo,null,$data['full_name'],$data['short_name'],$aliases);
            $q=$pdo->prepare('INSERT INTO feo_utilizers (full_name,short_name,address,latitude,longitude,fkko_classification,adr,color_hex,is_active,sort_order,created_by_user_id,updated_by_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$data['full_name'],$data['short_name'],$data['address'],$data['latitude'],$data['longitude'],$data['fkko_classification'],$data['adr'],$data['color_hex'],$data['is_active'],$data['sort_order'],$userId?:null,$userId?:null]);
            $id=(int)$pdo->lastInsertId(); $this->replaceAliases($pdo,$id,$aliases,$data['full_name'],$data['short_name'],$userId); $pdo->commit(); return $id;
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public function updateUtilizer(\PDO $pdo,int $id,array $input,int $userId): void
    {
        if($id<=0)throw new \RuntimeException('Некорректный утилизатор.');
        $data=$this->validateUtilizer($input); $aliases=$this->parseAliases($input['aliases']??'');
        $pdo->beginTransaction();
        try{
            $q=$pdo->prepare('SELECT id FROM feo_utilizers WHERE id=? FOR UPDATE');$q->execute([$id]);
            if(!$q->fetchColumn())throw new \RuntimeException('Утилизатор не найден.');
            $this->assertIdentityAvailable($pdo,$id,$data['full_name'],$data['short_name'],$aliases);
            $q=$pdo->prepare('UPDATE feo_utilizers SET full_name=?,short_name=?,address=?,latitude=?,longitude=?,fkko_classification=?,adr=?,color_hex=?,is_active=?,sort_order=?,updated_by_user_id=? WHERE id=?');
            $q->execute([$data['full_name'],$data['short_name'],$data['address'],$data['latitude'],$data['longitude'],$data['fkko_classification'],$data['adr'],$data['color_hex'],$data['is_active'],$data['sort_order'],$userId?:null,$id]);
            $this->replaceAliases($pdo,$id,$aliases,$data['full_name'],$data['short_name'],$userId); $pdo->commit();
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public function deleteUtilizer(\PDO $pdo,int $id): void
    {
        if($id<=0)throw new \RuntimeException('Некорректный утилизатор.');
        $pdo->beginTransaction();
        try{
            $q=$pdo->prepare('SELECT id FROM feo_utilizers WHERE id=? FOR UPDATE');$q->execute([$id]);
            if(!$q->fetchColumn())throw new \RuntimeException('Утилизатор не найден.');
            // Legacy rule/override tables are archive-only after migration 126; clear their FKs only on explicit utilizer deletion.
            foreach(['feo_utilizer_match_rules','feo_request_utilizer_overrides'] as $table){$q=$pdo->prepare("DELETE FROM {$table} WHERE utilizer_id=?");$q->execute([$id]);}
            $q=$pdo->prepare('DELETE FROM feo_utilizers WHERE id=?');$q->execute([$id]); $pdo->commit();
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public function classifyRequests(\PDO $pdo,array $requests): array
    {
        if($requests===[])return [];
        $utilizers=[];foreach($this->listUtilizers($pdo,true) as $row)$utilizers[(int)$row['id']]=$row;
        $index=$this->identityIndex($utilizers);$out=[];
        foreach($requests as $row){
            $id=(string)($row['zayavka_id']??'');$recipient=self::normalizeText((string)($row['gruzopoluchatel']??''));$match=$recipient!==''?($index[$recipient]??null):null;
            if(is_array($match)&&isset($utilizers[(int)$match['utilizer_id']])){$out[$id]=$this->classification((string)$match['source'],$utilizers[(int)$match['utilizer_id']]);continue;}
            $out[$id]=['status'=>'unmatched','source'=>'none','utilizer_id'=>null,'utilizer'=>null,'rule_id'=>null,'alias_id'=>null];
        }
        return $out;
    }

    public function currentPlacementProblemSummary(\PDO $pdo): array
    {
        $rows=$pdo->query("SELECT r.zayavka_id,r.gruzopoluchatel FROM feo_placement_current c JOIN feo_requests r ON r.zayavka_id=c.zayavka_id ORDER BY c.created_at DESC,c.zayavka_id ASC")->fetchAll(\PDO::FETCH_ASSOC)?:[];
        if($rows===[])return ['count'=>0,'groups'=>[]];
        $classified=$this->classifyRequests($pdo,$rows);$groups=[];$count=0;
        foreach($rows as $row){
            $id=(string)($row['zayavka_id']??'');if(($classified[$id]['status']??'unmatched')!=='unmatched')continue;$count++;
            $raw=trim((string)($row['gruzopoluchatel']??''));$label=$raw!==''?$raw:'Название не указано';
            if(!isset($groups[$label]))$groups[$label]=['name'=>$label,'count'=>0,'request_ids'=>[]];
            $groups[$label]['count']++;if(count($groups[$label]['request_ids'])<20)$groups[$label]['request_ids'][]=$id;
        }
        uasort($groups,static fn(array $a,array $b):int=>$b['count']<=>$a['count']?:strnatcasecmp($a['name'],$b['name']));
        return ['count'=>$count,'groups'=>array_values($groups)];
    }

    public static function normalizeText(string $value): string
    {
        $value=trim($value);if($value==='')return '';
        if(function_exists('mb_strtolower'))$value=mb_strtolower($value,'UTF-8');
        else $value=strtr(strtolower($value),['А'=>'а','Б'=>'б','В'=>'в','Г'=>'г','Д'=>'д','Е'=>'е','Ё'=>'ё','Ж'=>'ж','З'=>'з','И'=>'и','Й'=>'й','К'=>'к','Л'=>'л','М'=>'м','Н'=>'н','О'=>'о','П'=>'п','Р'=>'р','С'=>'с','Т'=>'т','У'=>'у','Ф'=>'ф','Х'=>'х','Ц'=>'ц','Ч'=>'ч','Ш'=>'ш','Щ'=>'щ','Ъ'=>'ъ','Ы'=>'ы','Ь'=>'ь','Э'=>'э','Ю'=>'ю','Я'=>'я']);
        $value=str_replace('ё','е',$value);$value=preg_replace('/[^\p{L}\p{N}]+/u',' ',$value)??$value;
        return trim(preg_replace('/\s+/u',' ',$value)??$value);
    }

    private function identityIndex(array $utilizers): array
    {
        $index=[];$register=static function(array &$index,string $raw,int $id,string $source):void{$key=self::normalizeText($raw);if($key==='')return;if(!array_key_exists($key,$index)){$index[$key]=['utilizer_id'=>$id,'source'=>$source];return;}if(is_array($index[$key])&&(int)$index[$key]['utilizer_id']===$id)return;$index[$key]=false;};
        foreach($utilizers as $id=>$u){$id=(int)$id;$register($index,(string)($u['full_name']??''),$id,'name');$register($index,(string)($u['short_name']??''),$id,'name');foreach(($u['aliases']??[]) as $a)$register($index,(string)($a['alias']??''),$id,'alias');}
        return $index;
    }

    private function classification(string $source,array $u): array{return ['status'=>(int)($u['is_active']??0)===1?'matched':'inactive','source'=>$source,'utilizer_id'=>(int)$u['id'],'utilizer'=>$u,'rule_id'=>null,'alias_id'=>null];}
    private function aliasesByUtilizer(\PDO $pdo): array{$rows=$pdo->query('SELECT id,utilizer_id,alias,normalized_alias FROM feo_utilizer_aliases ORDER BY alias ASC,id ASC')->fetchAll(\PDO::FETCH_ASSOC)?:[];$out=[];foreach($rows as $r)$out[(int)$r['utilizer_id']][]=$r;return $out;}

    private function parseAliases(mixed $input): array
    {
        $parts=preg_split('/\R+/u',is_array($input)?implode("\n",array_map('strval',$input)):(string)$input)?:[];$out=[];
        foreach($parts as $part){$alias=trim((string)$part);if($alias==='')continue;if(self::textLength($alias)>500)throw new \RuntimeException('Синоним не может быть длиннее 500 символов.');$key=self::normalizeText($alias);if($key!=='')$out[$key]=$alias;if(count($out)>100)throw new \RuntimeException('Для одного утилизатора можно указать не более 100 синонимов.');}
        return $out;
    }

    private function assertIdentityAvailable(\PDO $pdo,?int $excludeId,string $full,string $short,array $aliases): void
    {
        $candidate=[];foreach([$full,$short] as $name){$key=self::normalizeText($name);if($key!=='')$candidate[$key]=true;}foreach($aliases as $key=>$_)$candidate[$key]=true;
        foreach($pdo->query('SELECT id,full_name,short_name FROM feo_utilizers ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC)?:[] as $row){$id=(int)$row['id'];if($excludeId!==null&&$id===$excludeId)continue;foreach(['full_name','short_name'] as $field){$key=self::normalizeText((string)$row[$field]);if($key!==''&&isset($candidate[$key]))throw new \RuntimeException('Название или синоним уже используется другим утилизатором.');}}
        if($candidate===[])return;$marks=implode(',',array_fill(0,count($candidate),'?'));$sql='SELECT a.utilizer_id FROM feo_utilizer_aliases a WHERE a.normalized_alias IN ('.$marks.')';$params=array_keys($candidate);if($excludeId!==null){$sql.=' AND a.utilizer_id<>?';$params[]=$excludeId;}$sql.=' LIMIT 1';$q=$pdo->prepare($sql);$q->execute($params);if($q->fetchColumn()!==false)throw new \RuntimeException('Название или синоним уже используется другим утилизатором.');
    }

    private function replaceAliases(\PDO $pdo,int $id,array $aliases,string $full,string $short,int $userId): void
    {
        $canonical=array_filter([self::normalizeText($full),self::normalizeText($short)]);$q=$pdo->prepare('DELETE FROM feo_utilizer_aliases WHERE utilizer_id=?');$q->execute([$id]);if($aliases===[])return;
        $q=$pdo->prepare('INSERT INTO feo_utilizer_aliases (utilizer_id,alias,normalized_alias,created_by_user_id,updated_by_user_id) VALUES (?,?,?,?,?)');
        foreach($aliases as $normalized=>$alias){if(in_array($normalized,$canonical,true))continue;try{$q->execute([$id,$alias,$normalized,$userId?:null,$userId?:null]);}catch(\PDOException $e){if((string)$e->getCode()==='23000')throw new \RuntimeException('Этот синоним уже используется другим утилизатором.');throw $e;}}
    }

    private function validateUtilizer(array $input): array
    {
        $full=trim((string)($input['full_name']??''));$short=trim((string)($input['short_name']??''));if($full===''||self::textLength($full)>255)throw new \RuntimeException('Укажите полное название утилизатора.');if($short===''||self::textLength($short)>80)throw new \RuntimeException('Укажите короткое название для карты.');
        $address=trim((string)($input['address']??''));$fkko=trim((string)($input['fkko_classification']??''));if(self::textLength($address)>500||self::textLength($fkko)>500)throw new \RuntimeException('Адрес или классификация слишком длинные.');
        [$lat,$lng]=$this->coordinates($input['latitude']??null,$input['longitude']??null);$color=strtoupper(trim((string)($input['color_hex']??'#2563EB')));if(!preg_match('/^#[0-9A-F]{6}$/D',$color))throw new \RuntimeException('Укажите цвет в формате #RRGGBB.');
        return ['full_name'=>$full,'short_name'=>$short,'address'=>$address,'latitude'=>$lat,'longitude'=>$lng,'fkko_classification'=>$fkko,'adr'=>$this->boolInput($input['adr']??0),'color_hex'=>$color,'is_active'=>$this->boolInput($input['is_active']??0),'sort_order'=>max(-100000,min(100000,(int)($input['sort_order']??100)))];
    }

    private function coordinates(mixed $latRaw,mixed $lngRaw): array
    {
        $latRaw=trim((string)($latRaw??''));$lngRaw=trim((string)($lngRaw??''));if($latRaw===''&&$lngRaw==='')return [null,null];$latRaw=str_replace(',','.',$latRaw);$lngRaw=str_replace(',','.',$lngRaw);if($latRaw===''||$lngRaw===''||!is_numeric($latRaw)||!is_numeric($lngRaw))throw new \RuntimeException('Координаты утилизатора должны быть заполнены парой чисел.');$lat=(float)$latRaw;$lng=(float)$lngRaw;if($lat< -90||$lat>90||$lng< -180||$lng>180)throw new \RuntimeException('Координаты утилизатора выходят за допустимый диапазон.');if(abs($lat)<.0000001&&abs($lng)<.0000001)throw new \RuntimeException('Координаты 0,0 недопустимы.');return [$lat,$lng];
    }
    private function boolInput(mixed $v): int{return in_array($v,[1,'1',true,'on','yes'],true)?1:0;}
    private static function textLength(string $v): int{if(function_exists('mb_strlen'))return mb_strlen($v,'UTF-8');$chars=preg_split('//u',$v,-1,PREG_SPLIT_NO_EMPTY);return is_array($chars)?count($chars):strlen($v);}
}
