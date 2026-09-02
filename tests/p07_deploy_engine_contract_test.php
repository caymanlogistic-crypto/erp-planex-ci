<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$script = $root . '/.github/p07/P07_deploy_erpv2.sh';
$fail = static fn(string $m) => throw new RuntimeException($m);
$ok = static function (bool $v, string $m) use ($fail): void { if (!$v) $fail($m); };
$rm = function (string $p) use (&$rm): void {
    if (!file_exists($p) && !is_link($p)) return;
    if (!is_dir($p) || is_link($p)) { @unlink($p); return; }
    foreach (array_diff(scandir($p) ?: [], ['.','..']) as $n) $rm($p . DIRECTORY_SEPARATOR . $n);
    @rmdir($p);
};
$run = static function (array $cmd, array $env = []): array {
    $parts = array_map('escapeshellarg', $cmd);
    $prefix = '';
    foreach ($env as $k => $v) $prefix .= $k . '=' . escapeshellarg($v) . ' ';
    $out = []; $rc = 0;
    exec($prefix . implode(' ', $parts) . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
};
[$rc,$out] = $run(['bash','-n',$script]);
$ok($rc === 0, 'bash syntax ' . $out);
$p07 = (string) file_get_contents($script);
foreach ([
    'PHP_BIN=/usr/bin/php8.5',
    'PLANEX_P07_HEALTH_CMD',
    'HOST_MANAGED_PUBLIC_FILES=(.htaccess .user.ini)',
    'recover_public_control() {',
    'P07_PRESERVED_PUBLIC_CONTROL=',
    'P07_RECOVERED_PUBLIC_CONTROL=',
    'P07_PUBLIC_CONTROL_UNAVAILABLE=',
    'P07_HEALTH_HTTP=',
    'ERPv2 post-swap health check failed',
    'rollback_live() {',
    'trap rollback_live EXIT INT TERM',
    'marker_tmp="$MARKER.tmp.$$"',
    'committed=1',
] as $needle) $ok(str_contains($p07, $needle), 'missing P07 transactional marker ' . $needle);
$ok(strpos($p07, 'P07_HEALTH_HTTP=') < strpos($p07, 'marker_tmp="$MARKER.tmp.$$"'), 'health must run before marker update');
$ok(strpos($p07, 'marker_tmp="$MARKER.tmp.$$"') < strpos($p07, 'committed=1'), 'marker must update before transaction commit');
$ok(!str_contains($p07, 'resolve_php_bin() {'), 'P07 must not select generic PHP versions');

$base = '/tmp/planex_p07_contract_' . getmypid() . '_' . bin2hex(random_bytes(3));
$live = "$base/public_html/erpv2"; $deploy = "$base/deploy"; $backups = "$base/deploy_backups"; $src = "$base/src";
$phpWrapper = "$base/php8.5"; $health200 = "$base/health200"; $health500 = "$base/health500";
$roots = ['app','bootstrap','config','database','public','scripts','tools','tests','resources'];
$env = static fn(string $health) => [
    'PLANEX_P07_TEST_MODE' => '1',
    'PLANEX_P07_ROOT_OVERRIDE' => $base,
    'PLANEX_P07_PHP_BIN' => $phpWrapper,
    'PLANEX_P07_HEALTH_CMD' => $health,
];
try {
    mkdir($live, 0700, true); mkdir($deploy, 0700, true); mkdir($backups, 0700, true); mkdir($src, 0700, true);
    $php = escapeshellarg(PHP_BINARY);
    file_put_contents($phpWrapper, "#!/usr/bin/env bash\nif [ \"\${1:-}\" = '-r' ]; then printf '8.5.99'; exit 0; fi\nexec $php \"\$@\"\n"); chmod($phpWrapper, 0700);
    file_put_contents($health200, "#!/usr/bin/env bash\nprintf '200'\n"); chmod($health200, 0700);
    file_put_contents($health500, "#!/usr/bin/env bash\nprintf '500'\n"); chmod($health500, 0700);
    file_put_contents("$live/.env", "ENV_KEEP\n"); mkdir("$live/storage",0700,true); file_put_contents("$live/storage/keep.txt",'storage');
    mkdir("$live/logs",0700,true); file_put_contents("$live/logs/keep.txt",'logs'); mkdir("$live/vendor",0700,true); file_put_contents("$live/vendor/keep.txt",'vendor');
    file_put_contents("$deploy/P07_erpv2_deployed_commit.txt", str_repeat('b',40) . "\n");
    foreach ($roots as $r) { mkdir("$live/$r",0700,true); file_put_contents("$live/$r/old.txt",'old'); mkdir("$src/$r",0700,true); file_put_contents("$src/$r/new.txt",'new'); }
    file_put_contents("$src/app/ok.php", "<?php echo 'ok';\n"); file_put_contents("$src/tests/ok.php", "<?php echo 'ok';\n");

    $knownGood = "$base/known_good"; mkdir("$knownGood/public",0700,true);
    file_put_contents("$knownGood/public/.htaccess", "KNOWN_GOOD_HTACCESS\n");
    file_put_contents("$knownGood/public/.user.ini", "KNOWN_GOOD_USER_INI\n");
    $knownGoodDir = "$backups/P21_20260902T102150Z_" . str_repeat('1',40); mkdir($knownGoodDir,0700,true);
    [$rc,$out] = $run(['tar','-C',$knownGood,'-czf',"$knownGoodDir/erpv2_current.tar.gz",'.']); $ok($rc===0,'known-good backup build '.$out);

    $artifact = "$base/ok.tar.gz"; [$rc,$out] = $run(['tar','-C',$src,'-czf',$artifact,...$roots]); $ok($rc===0,'artifact build '.$out);
    $sha = hash_file('sha256',$artifact); $markerBefore = trim((string)file_get_contents("$deploy/P07_erpv2_deployed_commit.txt"));

    [$rc,$out] = $run(['bash',$script,$artifact,$sha,str_repeat('f',40)], $env($health500));
    $ok($rc===70,'health 500 must fail transaction '.$out);
    foreach ($roots as $r) { $ok(is_file("$live/$r/old.txt"),'rollback old root '.$r); $ok(!file_exists("$live/$r/new.txt"),'rollback new root '.$r); }
    $ok(trim((string)file_get_contents("$deploy/P07_erpv2_deployed_commit.txt"))===$markerBefore,'marker changed after failed health');
    $ok(!file_exists("$live/public/.htaccess"),'failed transaction must restore original missing htaccess state');
    $ok(!file_exists("$live/public/.user.ini"),'failed transaction must restore original missing user.ini state');

    [$rc,$out] = $run(['bash',$script,$artifact,$sha,str_repeat('a',40)], $env($health200));
    $ok($rc===0,'successful deploy '.$out);
    $ok(str_contains($out,'P07_RECOVERED_PUBLIC_CONTROL=.htaccess'),'htaccess must recover from controlled backup');
    $ok(str_contains($out,'P07_RECOVERED_PUBLIC_CONTROL=.user.ini'),'user.ini must recover from controlled backup');
    $ok(file_get_contents("$live/public/.htaccess")==="KNOWN_GOOD_HTACCESS\n",'wrong recovered htaccess');
    $ok(file_get_contents("$live/public/.user.ini")==="KNOWN_GOOD_USER_INI\n",'wrong recovered user.ini');
    $ok(trim((string)file_get_contents("$deploy/P07_erpv2_deployed_commit.txt"))===str_repeat('a',40),'marker not updated after healthy deploy');
    $ok(file_get_contents("$live/.env")==="ENV_KEEP\n",'.env changed');
    $ok(file_get_contents("$live/storage/keep.txt")==='storage','storage changed');
    $ok(file_get_contents("$live/logs/keep.txt")==='logs','logs changed');
    $ok(file_get_contents("$live/vendor/keep.txt")==='vendor','vendor changed');
    echo "P07_DEPLOY_ENGINE_CONTRACT=PASS\n";
} finally { $rm($base); }
