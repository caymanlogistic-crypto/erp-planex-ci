<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
$script=$root.'/.github/p07/P07_deploy_erpv2.sh';
$fail=static fn(string $m)=>throw new RuntimeException($m);
$ok=static function(bool $v,string $m)use($fail){if(!$v)$fail($m);};
$rm=function(string $p)use(&$rm){if(!file_exists($p)&&!is_link($p))return;if(!is_dir($p)||is_link($p)){@unlink($p);return;}foreach(array_diff(scandir($p)?:[],['.','..'])as$n)$rm($p.DIRECTORY_SEPARATOR.$n);@rmdir($p);};
$run=static function(array $cmd,array $env=[]):array{$parts=array_map('escapeshellarg',$cmd);$prefix='';foreach($env as$k=>$v)$prefix.=$k.'='.escapeshellarg($v).' ';$out=[];$rc=0;exec($prefix.implode(' ',$parts).' 2>&1',$out,$rc);return[$rc,implode("\n",$out)];};
[$rc,$out]=$run(['bash','-n',$script]);$ok($rc===0,'bash syntax '.$out);
$base='/tmp/planex_p07_contract_'.getmypid().'_'.bin2hex(random_bytes(3));$live="$base/public_html/erpv2";$deploy="$base/deploy";$backups="$base/deploy_backups";$src="$base/src";
$roots=['app','bootstrap','config','database','public','scripts','tools','tests','resources'];
try{
    mkdir($live,0700,true);mkdir($deploy,0700,true);mkdir($backups,0700,true);mkdir($src,0700,true);
    file_put_contents("$live/.env","ENV_KEEP\n");mkdir("$live/storage",0700,true);file_put_contents("$live/storage/keep.txt",'storage');mkdir("$live/logs",0700,true);file_put_contents("$live/logs/keep.txt",'logs');file_put_contents("$deploy/P07_erpv2_deployed_commit.txt",str_repeat('b',40)."\n");
    foreach($roots as$r){mkdir("$live/$r",0700,true);file_put_contents("$live/$r/old.txt",'old');mkdir("$src/$r",0700,true);file_put_contents("$src/$r/new.txt",'new');}
    file_put_contents("$src/app/ok.php","<?php echo 'ok';\n");file_put_contents("$src/tests/ok.php","<?php echo 'ok';\n");
    $artifact="$base/ok.tar.gz";[$rc,$out]=$run(['tar','-C',$src,'-czf',$artifact,...$roots]);$ok($rc===0,'build archive '.$out);$sha=hash_file('sha256',$artifact);$commit=str_repeat('a',40);
    [$rc,$out]=$run(['bash',$script,$artifact,$sha,$commit],['PLANEX_P07_TEST_MODE'=>'1','PLANEX_P07_ROOT_OVERRIDE'=>$base]);$ok($rc===0,'deploy '.$out);$ok(str_contains($out,'Preflight passed.'),'preflight marker');$ok(str_contains($out,'P07_DEPLOY_OK'),'success marker');
    foreach($roots as$r){$ok(is_file("$live/$r/new.txt"),'new root '.$r);$ok(!file_exists("$live/$r/old.txt"),'stale root '.$r);}
    $ok(file_get_contents("$live/.env")==="ENV_KEEP\n",'.env preserved');$ok(file_get_contents("$live/storage/keep.txt")==='storage','storage preserved');$ok(file_get_contents("$live/logs/keep.txt")==='logs','logs preserved');$ok(trim((string)file_get_contents("$deploy/P07_erpv2_deployed_commit.txt"))===$commit,'marker updated');
    $before=[];foreach($roots as$r)$before[$r]=hash_file('sha256',"$live/$r/new.txt");$markerBefore=trim((string)file_get_contents("$deploy/P07_erpv2_deployed_commit.txt"));
    $badSrc="$base/badsrc";mkdir($badSrc,0700,true);foreach(array_slice($roots,0,-1)as$r){mkdir("$badSrc/$r",0700,true);file_put_contents("$badSrc/$r/newer.txt",'newer');}$bad="$base/bad.tar.gz";[$rc,$out]=$run(['tar','-C',$badSrc,'-czf',$bad,...array_slice($roots,0,-1)]);$ok($rc===0,'bad archive build');[$rc,$out]=$run(['bash',$script,$bad,hash_file('sha256',$bad),str_repeat('c',40)],['PLANEX_P07_TEST_MODE'=>'1','PLANEX_P07_ROOT_OVERRIDE'=>$base]);$ok($rc!==0,'missing root must fail');foreach($roots as$r)$ok(hash_file('sha256',"$live/$r/new.txt")===$before[$r],'live unchanged '.$r);$ok(trim((string)file_get_contents("$deploy/P07_erpv2_deployed_commit.txt"))===$markerBefore,'marker unchanged after fail');
    $forbiddenSrc="$base/forbidden";mkdir("$forbiddenSrc/app",0700,true);mkdir("$forbiddenSrc/storage",0700,true);file_put_contents("$forbiddenSrc/app/x.php",'<?php');file_put_contents("$forbiddenSrc/storage/no.txt",'no');$forbidden="$base/forbidden.tar.gz";[$rc,$out]=$run(['tar','-C',$forbiddenSrc,'-czf',$forbidden,'app','storage']);$ok($rc===0,'forbidden archive build');[$rc,$out]=$run(['bash',$script,$forbidden,hash_file('sha256',$forbidden),str_repeat('d',40)],['PLANEX_P07_TEST_MODE'=>'1','PLANEX_P07_ROOT_OVERRIDE'=>$base]);$ok($rc!==0&&str_contains($out,'Forbidden runtime or secret path'),'forbidden runtime fail closed');
    echo "P07_DEPLOY_ENGINE_CONTRACT=PASS\n";
}finally{$rm($base);}
