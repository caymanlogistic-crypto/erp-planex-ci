<?php
/** @var VehicleSetService $service */
// This file contains the EXACT same logic as the original POST /company/vehicle-sets/create route closure
// Variables available: $config, $db, $service
$VSC_pageTitle='Создать транспорт';$VSC_pageContext='Транспорт > Компания';
$VSC_companyId=$service->getCompanyId();$VSC_errors=[];$VSC_old=$_POST;$VSC_formError=null;$VSC_success=false;$VSC_createdVehicleSet=null;$VSC_docErrors=[];$VSC_uploadedDocs=[];$VSC_docTypes=[];$VSC_company=null;
if($VSC_companyId<=0){$VSC_formError='Компания не найдена';goto VSC_render;}
try{$VSC_pdo=$db->connection();$VSC_stmt=$VSC_pdo->prepare('SELECT * FROM companies WHERE id=?');$VSC_stmt->execute([$VSC_companyId]);$VSC_company=$VSC_stmt->fetch(PDO::FETCH_ASSOC);
if(!$VSC_company){$VSC_company=null;$VSC_formError='Компания не найдена';goto VSC_render;}
$VSC_pageContext='Транспорт > Компания: '.$VSC_company['name'];if(($VSC_company['status']??'')!=='active'){$VSC_formError='Компания недоступна';goto VSC_render;}
$VSC_cfg=companyDatabaseConfig($config, $VSC_company);$VSC_ldb=new \App\Core\Database($VSC_cfg);$VSC_lpdo=$VSC_ldb->connection();applyLocalMigrations($VSC_lpdo);
$VSC_membershipId=\App\Service\RegulatedDataScopeService::assertCanCreate('vehicle_unit');
try{$VSC_lpdo->query('SELECT 1 FROM document_types LIMIT 1')->fetch();}catch(\Exception$e){$VSC_lpdo->exec(file_get_contents(base_path('database/migrations-local/024_create_document_types.sql')));$VSC_lpdo->exec(file_get_contents(base_path('database/migrations-local/025_add_document_type_id.sql')));}
$VSC_docTypes=$VSC_lpdo->query("SELECT id,name,code,entity_type FROM document_types WHERE entity_type='vehicle_unit' OR entity_type IS NULL ORDER BY sort_order,name")->fetchAll(PDO::FETCH_ASSOC);
if(isPostTruncated()){$VSC_formError='Общий размер отправки превышает серверный лимит.';goto VSC_render;}
// Use helper functions from the runtime
$VSC_rules=vehicleSetTypeRules();$VSC_docSections=vehicleSetPredefinedDocumentSections();
$VSC_setType=trim((string)($_POST['set_type']??''));$VSC_status=trim((string)($_POST['status']??'active'));$VSC_comments=trim((string)($_POST['comments']??''));
$VSC_unitsInput=isset($_POST['units'])&&is_array($_POST['units'])?$_POST['units']:[];
$VSC_activeRoles=vehicleSetVisibleUnitRoles($VSC_setType);
$VSC_unitPayload=[];$VSC_createdUnits=[];$VSC_createdUnitIds=[];$VSC_storedPaths=[];
if($VSC_setType===''||!isset($VSC_rules[$VSC_setType]))$VSC_errors['set_type']='Выберите тип комплекта';
if(!in_array($VSC_status,['active','inactive'],true))$VSC_status='active';
foreach($VSC_activeRoles as $VSC_role){$VSC_unitRule=$VSC_rules[$VSC_setType]['units'][$VSC_role]??[];$VSC_source=isset($VSC_unitsInput[$VSC_role])&&is_array($VSC_unitsInput[$VSC_role])?$VSC_unitsInput[$VSC_role]:[];
$VSC_label=(string)($VSC_unitRule['label']??'Транспортная единица');$VSC_showCapacity=!empty($VSC_unitRule['show_capacity']);$VSC_showVolume=!empty($VSC_unitRule['show_volume']);
$VSC_diagnosticCardDate=trim((string)($VSC_source['diagnostic_card_date']??''));
$VSC_payload=['label'=>$VSC_label,'unit_type'=>(string)($VSC_unitRule['unit_type']??''),'plate_number'=>trim((string)($VSC_source['plate_number']??'')),'brand'=>trim((string)($VSC_source['brand']??'')),'model'=>trim((string)($VSC_source['model']??'')),'vin'=>trim((string)($VSC_source['vin']??'')),'capacity_tons'=>trim((string)($VSC_source['capacity_tons']??'')),'volume_m3'=>trim((string)($VSC_source['volume_m3']??'')),'diagnostic_card_number'=>trim((string)($VSC_source['diagnostic_card_number']??'')),'diagnostic_card_date'=>$VSC_diagnosticCardDate,'show_capacity'=>$VSC_showCapacity,'show_volume'=>$VSC_showVolume];
$VSC_unitPayload[$VSC_role]=$VSC_payload;
if($VSC_payload['plate_number']==='')$VSC_errors['units'][$VSC_role]['plate_number']='Укажите госномер';
if($VSC_payload['vin']!==''&&!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/',strtoupper($VSC_payload['vin'])))$VSC_errors['units'][$VSC_role]['vin']='Формат VIN: 17 символов';}
$VSC_allowedExt=['pdf','jpg','jpeg','png','webp','doc','docx','rtf','odt','xls','xlsx','csv','ods','gif','bmp','tif','tiff','heic','heif'];$VSC_maxSize=20*1024*1024;
if(!empty($VSC_errors)){goto VSC_render;}
$VSC_totalSizeError=validateTotalUploadSize();if($VSC_totalSizeError!==''){$VSC_formError=$VSC_totalSizeError;goto VSC_render;}
$VSC_storeUpload=static function(PDO $lpdo,int $cid,string $et,int $eid,string $dtn,string $dc,array $file,string $cat,array &$paths)use($VSC_allowedExt,$VSC_maxSize){$ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));$sn=uniqid('doc_',true).'.'.$ext;$rd='companies/'.$cid.'/documents/'.$et.'/'.$eid;$ad=storage_path($rd);if(!is_dir($ad)&&!mkdir($ad,0755,true))throw new RuntimeException('mkdir_failed');$ap=$ad.DIRECTORY_SEPARATOR.$sn;if(!move_uploaded_file($file['tmp_name'],$ap))throw new RuntimeException('move_failed');$paths[]=$ap;$dtid=ensureDocumentTypeRecord($lpdo,$dtn,$dc,$et,$cat);$ins=$lpdo->prepare('INSERT INTO documents(entity_type,entity_id,document_type,document_type_id,original_name,stored_name,relative_path,mime_type,file_size,status,uploaded_by_user_id,uploaded_by_role,created_by_user_id,created_by_role)VALUES(:et,:eid,:dtype,:dtid,:oname,:sname,:rpath,:mime,:fsize,:status,:uid,:role,:cuid,:crole)');
$ins->execute([':et'=>$et,':eid'=>$eid,':dtype'=>$dtn,':dtid'=>$dtid,':oname'=>$file['name'],':sname'=>$sn,':rpath'=>$rd.'/'.$sn,':mime'=>$file['type'],':fsize'=>$file['size'],':status'=>'uploaded',':uid'=>(int)$_SESSION['user_id'],':role'=>$_SESSION['role_code'],':cuid'=>(int)$_SESSION['user_id'],':crole'=>$_SESSION['role_code']]);};
$VSC_lpdo->beginTransaction();
try{$VSC_insertUnit=$VSC_lpdo->prepare('INSERT INTO vehicle_units(plate_number,brand,model,unit_type,vin,capacity_tons,volume_m3,diagnostic_card_number,diagnostic_card_date,status,comments,created_by_user_id,created_by_role)VALUES(:plate_number,:brand,:model,:unit_type,:vin,:capacity_tons,:volume_m3,:diagnostic_card_number,:diagnostic_card_date,:status,:comments,:created_by_user_id,:created_by_role)');
foreach($VSC_activeRoles as $VSC_role){$VSC_payload=$VSC_unitPayload[$VSC_role];$VSC_insertUnit->execute([':plate_number'=>$VSC_payload['plate_number'],':brand'=>$VSC_payload['brand'],':model'=>$VSC_payload['model'],':unit_type'=>$VSC_payload['unit_type'],':vin'=>$VSC_payload['vin'],':capacity_tons'=>($VSC_payload['show_capacity']&&$VSC_payload['capacity_tons']!=='')?(float)$VSC_payload['capacity_tons']:null,':volume_m3'=>($VSC_payload['show_volume']&&$VSC_payload['volume_m3']!=='')?(float)$VSC_payload['volume_m3']:null,':diagnostic_card_number'=>$VSC_payload['diagnostic_card_number']!==''?$VSC_payload['diagnostic_card_number']:null,':diagnostic_card_date'=>$VSC_payload['diagnostic_card_date']!==''?$VSC_payload['diagnostic_card_date']:null,':status'=>$VSC_status,':comments'=>null,':created_by_user_id'=>(int)$_SESSION['user_id'],':created_by_role'=>$_SESSION['role_code']]);
$VSC_unitId=(int)$VSC_lpdo->lastInsertId();\App\Service\EntityAssignmentService::autoAssignCreator($VSC_lpdo,'vehicle_unit',$VSC_unitId,$VSC_membershipId);$VSC_createdUnitIds[$VSC_role]=$VSC_unitId;$VSC_createdUnits[$VSC_role]=$VSC_payload+['id'=>$VSC_unitId];}
$VSC_insertSet=$VSC_lpdo->prepare('INSERT INTO vehicle_sets(set_type,primary_vehicle_unit_id,secondary_vehicle_unit_id,status,comments,created_by_user_id,created_by_membership_id,created_by_role)VALUES(:set_type,:primary_id,:secondary_id,:status,:comments,:uid,:membership_id,:role)');
$VSC_insertSet->execute([':set_type'=>$VSC_setType,':primary_id'=>(int)($VSC_createdUnitIds['primary']??0),':secondary_id'=>isset($VSC_createdUnitIds['secondary'])?(int)$VSC_createdUnitIds['secondary']:null,':status'=>$VSC_status,':comments'=>$VSC_comments!==''?$VSC_comments:null,':uid'=>(int)$_SESSION['user_id'],':membership_id'=>$VSC_membershipId>0?$VSC_membershipId:null,':role'=>$_SESSION['role_code']]);
$VSC_newSetId=(int)$VSC_lpdo->lastInsertId();
foreach($VSC_activeRoles as $VSC_role){$VSC_docs=$VSC_docSections[$VSC_role]??[];foreach($VSC_docs as $VSC_docMeta){$VSC_files=vehicleSetNormalizeNamedFiles($_FILES['predef_doc']??[],$VSC_role,$VSC_docMeta['doc_code']);foreach($VSC_files as $VSC_file){if($VSC_file['error']===UPLOAD_ERR_NO_FILE||$VSC_file['name']==='')continue;$VSC_storeUpload($VSC_lpdo,$VSC_companyId,'vehicle_unit',(int)$VSC_createdUnitIds[$VSC_role],$VSC_docMeta['name'],$VSC_docMeta['doc_code'],$VSC_file,'predefined',$VSC_storedPaths);$VSC_uploadedDocs[]=($VSC_unitPayload[$VSC_role]['label']??'Транспортная единица').': '.$VSC_docMeta['name'].' ('.$VSC_file['name'].')';}}
$VSC_customSelected=$_POST['custom_doc_type'][$VSC_role]??[];$VSC_customNew=$_POST['custom_doc_type_new'][$VSC_role]??[];$VSC_customFiles=vehicleSetNormalizeRoleFiles($_FILES['custom_doc_file']??[],$VSC_role);$VSC_rowCount=max(count($VSC_customFiles),is_array($VSC_customSelected)?count($VSC_customSelected):0,is_array($VSC_customNew)?count($VSC_customNew):0);
for($VSC_idx=0;$VSC_idx<$VSC_rowCount;$VSC_idx++){$VSC_file=$VSC_customFiles[$VSC_idx]??['name'=>'','error'=>UPLOAD_ERR_NO_FILE,'size'=>0,'tmp_name'=>'','type'=>''];if($VSC_file['error']===UPLOAD_ERR_NO_FILE||$VSC_file['name']==='')continue;$VSC_selType=trim((string)((is_array($VSC_customSelected)?($VSC_customSelected[$VSC_idx]??''):'')));$VSC_newType=trim((string)((is_array($VSC_customNew)?($VSC_customNew[$VSC_idx]??''):'')));$VSC_docTypeName=$VSC_newType!==''?$VSC_newType:$VSC_selType;$VSC_storeUpload($VSC_lpdo,$VSC_companyId,'vehicle_unit',(int)$VSC_createdUnitIds[$VSC_role],$VSC_docTypeName,'',$VSC_file,'custom',$VSC_storedPaths);$VSC_uploadedDocs[]=($VSC_unitPayload[$VSC_role]['label']??'Транспортная единица').': '.$VSC_docTypeName.' ('.$VSC_file['name'].')';}}
$VSC_lpdo->commit();$VSC_createdVehicleSet=['id'=>$VSC_newSetId,'set_type'=>$VSC_setType,'status'=>$VSC_status,'comments'=>$VSC_comments,'units'=>$VSC_createdUnits,'primary_plate'=>$VSC_createdUnits['primary']['plate_number']??'','secondary_plate'=>$VSC_createdUnits['secondary']['plate_number']??null,'primary_vehicle_unit_id'=>$VSC_createdUnitIds['primary']??null,'secondary_vehicle_unit_id'=>$VSC_createdUnitIds['secondary']??null];$VSC_success=true;}
catch(\Throwable$e){if($VSC_lpdo->inTransaction())$VSC_lpdo->rollBack();foreach($VSC_storedPaths as $sp){if(is_string($sp)&&$sp!==''&&is_file($sp))@unlink($sp);}throw$e;}}
catch(\Exception$e){$VSC_company=$VSC_company??null;$VSC_formError='Ошибка: '.$e->getMessage();}
VSC_render:
$pageTitle=$VSC_pageTitle;
$pageContext=$VSC_pageContext;
$company=$VSC_company;
$errors=$VSC_errors;
$old=$VSC_old;
$formError=$VSC_formError;
$success=$VSC_success;
$createdVehicleSet=$VSC_createdVehicleSet;
$docErrors=$VSC_docErrors;
$uploadedDocs=$VSC_uploadedDocs;
$docTypes=$VSC_docTypes;
ob_start();require base_path('app/View/pages/company_vehicle_sets_create.php');$content=ob_get_clean();require base_path('app/View/layouts/main.php');
