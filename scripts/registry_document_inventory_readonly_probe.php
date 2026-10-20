<?php
declare(strict_types=1);

/** CLI forensic export. No application services, HTTP, migrations or business writes. */
final class RegistryDocumentInventoryReadOnly
{
    public const TABLES = [
        'route_executor_review_cards' => ['card_id','actor_account_id','last_editor_account_id','revision','snapshot_json','files_json','contractor_name','driver_name','plate','created_at','updated_at','deleted_at'],
        'route_executor_review_sends' => ['card_id','actor_account_id','stage','partial_message_id','final_message_id','partial_mail_status','final_mail_status','partial_at','final_at','created_at','updated_at'],
        'documents' => ['id','entity_type','entity_id','document_type','document_type_id','original_name','stored_name','relative_path','mime_type','file_size','status','created_by_user_id','created_by_role','uploaded_by_user_id','uploaded_by_role','created_at','updated_at','deleted_at','delete_comment'],
        'document_types' => ['id','name','code','entity_type','category','status','created_at','deleted_at'],
        'contractors' => ['id','name','status','created_by_user_id','created_at','updated_at','deleted_at'],
        'drivers' => ['id','full_name','status','created_by_user_id','created_at','updated_at','deleted_at'],
        'vehicle_units' => ['id','unit_type','plate_number','status','created_by_user_id','created_at','updated_at','deleted_at'],
        'vehicle_sets' => ['id','set_type','primary_vehicle_unit_id','secondary_vehicle_unit_id','status','created_by_user_id','created_at','updated_at','deleted_at'],
        'driver_vehicle_blocks' => ['id','driver_id','vehicle_set_id','status','created_by_user_id','created_at','updated_at','deleted_at'],
        'crews' => ['id','contractor_id','driver_id','vehicle_id','driver_vehicle_block_id','status','created_by_user_id','created_at','updated_at','deleted_at'],
        'crew_drivers' => ['id','crew_id','driver_id','position','created_at'],
    ];

    public static function snapshot(PDO $pdo): void
    {
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    }

    public static function inspect(string $storage, int $company, string $path, string $prefix, float $deadline): array
    {
        $out = ['status'=>'rejected_path','size'=>null,'sha256'=>null];
        if ($company < 1 || str_contains($path, '\\') || preg_match('/[\x00-\x20\x7f:]/', $path)
            || !str_starts_with($path, "companies/$company/$prefix/")) return $out;
        $parts = explode('/', $path);
        if (array_intersect($parts, ['', '.', '..'])) return $out;
        $base = realpath($storage);
        if ($base === false) return ['status'=>'storage_unavailable'];
        $check = static function () use ($base, $parts): ?array {
            $current = $base; $identities = [];
            foreach ($parts as $part) {
                $current .= DIRECTORY_SEPARATOR . $part;
                clearstatcache(true, $current);
                $s = @lstat($current);
                if ($s === false) return null;
                if (($s['mode'] & 0170000) === 0120000) throw new RuntimeException('PATH_SYMLINK');
                $identities[] = [$s['dev'],$s['ino'],$s['mode']];
            }
            return [$current,$s,$identities];
        };
        try {
            $before = $check();
            if ($before === null) return ['status'=>'missing'];
            [$absolute,$stat,$identities] = $before;
            if (($stat['mode'] & 0170000) !== 0100000) return ['status'=>'not_regular'];
            if ($stat['size'] > 268435456) return ['status'=>'size_limit','size'=>$stat['size']];
            $handle = @fopen($absolute, 'rb');
            if ($handle === false) return ['status'=>'unreadable'];
            try {
                $opened = fstat($handle);
                if ($opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino']) return ['status'=>'changed_during_read'];
                // On Linux inspect the opened FD target before reading; never follow a swapped path silently.
                if (PHP_OS_FAMILY === 'Linux') {
                    $fdVerified = false;
                    foreach (glob('/proc/self/fd/*') ?: [] as $fd) {
                        $fs = @stat($fd);
                        if ($fs && $fs['dev']===$opened['dev'] && $fs['ino']===$opened['ino'] && @readlink($fd)===$absolute) $fdVerified=true;
                    }
                    if (!$fdVerified) return ['status'=>'unverified_opened_path'];
                }
                $hash=hash_init('sha256'); $size=0;
                while (!feof($handle)) {
                    if (microtime(true)>$deadline) throw new RuntimeException('DEADLINE');
                    $chunk=fread($handle,1048576);
                    if ($chunk===false) return ['status'=>'read_error'];
                    $size+=strlen($chunk); hash_update($hash,$chunk);
                }
                $after=$check(); $closed=fstat($handle);
                if (!$after || $after[2]!==$identities || $closed['size']!==$opened['size']
                    || $closed['mtime']!==$opened['mtime'] || $closed['ctime']!==$opened['ctime']
                    || $size!==$opened['size'] || $after[1]['size']!==$size) return ['status'=>'changed_during_read'];
                return ['status'=>'present_stable','size'=>$size,'sha256'=>hash_final($hash),
                    'observed_at_utc'=>gmdate('c'),'fs_snapshot_atomic'=>false];
            } finally { fclose($handle); }
        } catch (RuntimeException $e) {
            if ($e->getMessage()==='DEADLINE') throw $e;
            return ['status'=>'symlink'];
        }
    }

    public static function export(PDO $pdo, int $company, string $storage, callable $emit, float $deadline): array
    {
        $counts=[]; $availability=[]; $inspectionCounts=[];
        foreach (self::TABLES as $table=>$wanted) {
            if (microtime(true)>$deadline) throw new RuntimeException('DEADLINE');
            $q=$pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $q->execute([$table]); $engine=$q->fetchColumn();
            if ($engine!=='InnoDB') throw new RuntimeException('REQUIRED_INNODB_TABLE_UNAVAILABLE');
            $q=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $q->execute([$table]); $actual=$q->fetchAll(PDO::FETCH_COLUMN);
            $columns=array_values(array_intersect($wanted,$actual));
            $key=str_starts_with($table,'route_executor_review_')?'card_id':'id';
            $required=match($table) {
                'route_executor_review_cards'=>['card_id','snapshot_json','files_json','deleted_at'],
                'documents'=>['id','entity_type','entity_id','document_type','original_name','relative_path','file_size','deleted_at'],
                'route_executor_review_sends'=>['card_id','stage','final_mail_status','final_at'],
                'contractors'=>['id','name','status','created_at','deleted_at'],
                'drivers'=>['id','full_name','status','created_at','deleted_at'],
                'vehicle_units'=>['id','plate_number','status','created_at','deleted_at'],
                'vehicle_sets'=>['id','primary_vehicle_unit_id','secondary_vehicle_unit_id','created_at','deleted_at'],
                'driver_vehicle_blocks'=>['id','driver_id','vehicle_set_id','created_at','deleted_at'],
                'crews'=>['id','contractor_id','driver_id','vehicle_id','driver_vehicle_block_id','created_at','deleted_at'],
                'crew_drivers'=>['id','crew_id','driver_id'],
                default=>[$key],
            };
            if (array_diff($required,$columns)) throw new RuntimeException('REQUIRED_COLUMN_UNAVAILABLE');
            $availability[$table]=['columns'=>$columns,'absent_optional_columns'=>array_values(array_diff($wanted,$actual))];
            $total=(int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            if ($total>50000) throw new RuntimeException('ROW_LIMIT');
            $selection=implode(',',array_map(static fn($c)=>"`$c`",$columns));
            if ($table==='route_executor_review_cards') {
                foreach (['snapshot_json','files_json'] as $jc)
                    $selection.=",OCTET_LENGTH(`$jc`) AS `{$jc}_db_bytes`,SHA2(CAST(`$jc` AS BINARY),256) AS `{$jc}_db_sha256`";
            }
            $last=null; $count=0; $page=0;
            do {
                $sql="SELECT $selection FROM `$table`";
                if ($last!==null) $sql.=" WHERE `$key`>?";
                $sql.=" ORDER BY `$key` LIMIT 250";
                $q=$pdo->prepare($sql); $q->execute($last===null?[]:[$last]); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
                $page++; $pageHash=hash_init('sha256');
                foreach ($rows as $row) {
                    if (microtime(true)>$deadline) throw new RuntimeException('DEADLINE');
                    if ($last!==null && (string)$row[$key]===(string)$last) throw new RuntimeException('KEYSET_STALLED');
                    $last=$row[$key]; $count++;
                    if ($table==='route_executor_review_cards') {
                        foreach (['snapshot_json','files_json'] as $jsonColumn) {
                            $raw=(string)$row[$jsonColumn];
                            $row[$jsonColumn.'_bytes']=strlen($raw); $row[$jsonColumn.'_sha256']=hash('sha256',$raw);
                            if ((int)$row[$jsonColumn.'_db_bytes']!==strlen($raw)
                                || $row[$jsonColumn.'_db_sha256']!==hash('sha256',$raw)) throw new RuntimeException('JSON_TRANSPORT_MISMATCH');
                            $row[$jsonColumn]=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
                            if (!is_array($row[$jsonColumn])) throw new RuntimeException('JSON_SHAPE');
                        }
                        $snapshot=$row['snapshot_json'];
                        if (!array_is_list($row['files_json'])) throw new RuntimeException('FILE_JSON_SHAPE');
                        $controls=array_values(array_filter((array)($snapshot['controls']??[]), static fn($c)=>is_array($c)
                            && preg_match('/^(predef_doc_type|custom_doc_type|custom_doc_type_new)(\[|$)/',(string)($c['name']??''))));
                        $row['snapshot_json']=['selected_entities'=>$snapshot['selected_entities']??[],
                            'removed_documents'=>$snapshot['removed_documents']??[],'controls'=>$controls];
                        foreach ($row['files_json'] as &$file) {
                            if (!is_array($file)) throw new RuntimeException('FILE_JSON_SHAPE');
                            $file['inspection']=self::inspect($storage,$company,(string)($file['path']??''),
                                'route-executor-review/'.$row['card_id'],$deadline);
                            $inspectionCounts[$file['inspection']['status']]=($inspectionCounts[$file['inspection']['status']]??0)+1;
                        } unset($file);
                    }
                    if ($table==='documents' && in_array($row['entity_type'],['contractor','driver','vehicle_unit','vehicle_set'],true)) {
                        $row['inspection']=self::inspect($storage,$company,(string)$row['relative_path'],
                            'documents/'.$row['entity_type'].'/'.$row['entity_id'],$deadline);
                        $inspectionCounts[$row['inspection']['status']]=($inspectionCounts[$row['inspection']['status']]??0)+1;
                    }
                    // Other document categories contribute counts but no unrelated personal metadata.
                    if ($table==='documents' && !in_array($row['entity_type'],['contractor','driver','vehicle_unit','vehicle_set'],true))
                        $row=array_intersect_key($row,array_flip(['id','entity_type','entity_id','deleted_at']));
                    $line=['kind'=>'row','company_id'=>$company,'table'=>$table,'row'=>$row];
                    hash_update($pageHash,json_encode($line,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); $emit($line);
                }
                $emit(['kind'=>'page','company_id'=>$company,'table'=>$table,'page'=>$page,'count'=>count($rows),
                    'last_key'=>$last,'sha256'=>hash_final($pageHash)]);
            } while ($rows!==[]);
            if ($count!==$total) throw new RuntimeException('COUNT_MISMATCH');
            $counts[$table]=['expected'=>$total,'exported'=>$count,'pages'=>$page];
        }
        return ['company_id'=>$company,'table_counts'=>$counts,'schema'=>$availability,'inspections'=>$inspectionCounts,
            'consistent_db_snapshot'=>true,'fs_snapshot_atomic'=>false,'purged_history'=>'unknown','completed'=>true];
    }
}

if (!defined('PLANEX_DOCUMENT_INVENTORY_LIBRARY_ONLY')) {
    $pdo=null; $central=null;
    try {
        if (PHP_SAPI!=='cli') throw new RuntimeException('CLI_REQUIRED');
        $root=rtrim((string)getenv('PLANEX_ROOT'),'/');
        if ($root!=='/home/s/spugovxsim/planexp/public_html/erpv2') throw new RuntimeException('ROOT_SCOPE');
        $scope=(string)getenv('PLANEX_INVENTORY_SCOPE');
        if ($scope!=='discover' && !preg_match('/^[1-9][0-9]{0,9}(,[1-9][0-9]{0,9}){0,19}$/D',$scope)) throw new RuntimeException('SCOPE');
        $emit=static function(array $record): void { echo json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n"; };
        $config=require $root.'/bootstrap/app.php';
        require_once $root.'/app/Support/crypto_helper.php';
        require_once $root.'/app/Support/company_database.php';
        $connect=static function(array $db): PDO {
            $p=new PDO('mysql:host='.$db['host'].';port='.($db['port']??3306).';dbname='.$db['database'].';charset=utf8mb4',
                $db['username'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
            $p->exec('SET SESSION TRANSACTION READ ONLY'); return $p;
        };
        $central=$connect($config['database']); RegistryDocumentInventoryReadOnly::snapshot($central);
        $emit(['kind'=>'start','schema_version'=>1,'run_id'=>(string)getenv('PLANEX_AUDIT_RUN'),
            'probe_sha256'=>getenv('PLANEX_PROBE_HASH'),'live_sha'=>getenv('PLANEX_LIVE_SHA'),'started_utc'=>gmdate('c'),'scope'=>$scope]);
        if ($scope==='discover') {
            $q=$central->query("SELECT id,name,status FROM companies WHERE db_identifier IS NOT NULL AND db_identifier<>'' ORDER BY id");
            $n=0; foreach ($q as $company) { if (++$n>200) throw new RuntimeException('COMPANY_LIMIT'); $emit(['kind'=>'company','row'=>$company]); }
            $central->rollBack(); $emit(['kind'=>'end','completed'=>true,'mode'=>'discover','companies'=>$n]);
        } else {
            $deadline=microtime(true)+900; $manifests=[];
            foreach (array_unique(explode(',',$scope)) as $id) {
                $q=$central->prepare('SELECT id,db_identifier,db_host,db_port,db_username,db_password FROM companies WHERE id=? AND db_identifier IS NOT NULL AND db_identifier<>\'\'');
                $q->execute([(int)$id]); $company=$q->fetch();
                if (!$company) throw new RuntimeException('COMPANY_UNAVAILABLE');
                $pdo=$connect(companyDatabaseConfig($config,$company)); RegistryDocumentInventoryReadOnly::snapshot($pdo);
                $manifests[]=RegistryDocumentInventoryReadOnly::export($pdo,(int)$id,$root.'/storage',$emit,$deadline);
                $pdo->rollBack(); $pdo=null;
            }
            $central->rollBack(); $emit(['kind'=>'end','completed'=>true,'manifests'=>$manifests,'finished_utc'=>gmdate('c')]);
        }
    } catch (Throwable $error) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        if ($central instanceof PDO && $central->inTransaction()) $central->rollBack();
        // Never leak DSN, passwords, SQL values or document paths into Actions stderr.
        $safeCodes=['CLI_REQUIRED','ROOT_SCOPE','SCOPE','COMPANY_LIMIT','COMPANY_UNAVAILABLE','DEADLINE','ROW_LIMIT',
            'REQUIRED_INNODB_TABLE_UNAVAILABLE','REQUIRED_COLUMN_UNAVAILABLE','KEYSET_STALLED','JSON_SHAPE',
            'FILE_JSON_SHAPE','JSON_TRANSPORT_MISMATCH','COUNT_MISMATCH'];
        $reason=in_array($error->getMessage(),$safeCodes,true)?$error->getMessage():'INTERNAL_ERROR';
        fwrite(STDERR,"DOCUMENT_INVENTORY_INCOMPLETE class=".get_class($error)." reason=$reason\n"); exit(1);
    }
}
