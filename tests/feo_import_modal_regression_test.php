<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$view = file_get_contents($root . '/app/View/pages/company_feo_requests.php') ?: '';
$pass = 0;
$fail = 0;
$ok = static function (string $name, bool $value) use (&$pass, &$fail): void {
    if ($value) { $pass++; return; }
    $fail++;
    fwrite(STDERR, "FAIL: {$name}\n");
};

$payloadPos = strpos($view, 'var payload=new FormData(analyzeForm);');
$disablePos = strpos($view, 'fileInput.disabled=true;overwriteInput.disabled=true;');
$ok('multipart payload is captured before file input is disabled', $payloadPos !== false && $disablePos !== false && $payloadPos < $disablePos);
$ok('analyze request sends captured multipart payload', str_contains($view, "body:payload,credentials:'same-origin'"));
$analyzeStart = strpos($view, "var analyzeForm=document.getElementById('feo-analyze-form')");
$analyzeSource = $analyzeStart === false ? '' : substr($view, $analyzeStart);
$ok('analyze flow never builds FormData after disabling controls', !str_contains($analyzeSource, 'body:new FormData(analyzeForm)'));
$ok('automatic analysis remains bound to file selection', str_contains($view, "fileInput.addEventListener('change',analyzeSelectedFile)"));
$ok('no separate analyze button is introduced', !str_contains($view, 'feo-analyze-btn'));
$ok('overwrite remains opt-in and unchecked by default', str_contains($view, 'name="overwrite_existing" value="1"') && !str_contains($view, 'name="overwrite_existing" value="1" checked'));
$ok('import modal uses compact dedicated layout', str_contains($view, 'modal modal-lg feo-import-modal') && str_contains($view, '.feo-import-modal{width:820px'));
$ok('overwrite checkbox is compact and left aligned', str_contains($view, '.feo-overwrite-option{display:grid;grid-template-columns:20px minmax(0,1fr)') && str_contains($view, '.feo-overwrite-option input{width:18px;height:18px'));
$ok('import state is placed inside import form after options', str_contains($view, 'data-feo-import-state>Файл не выбран.</div>'));
$ok('analysis summary stays responsive', str_contains($view, '.feo-import-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr))') && str_contains($view, '@media(max-width:640px)'));

printf("FEO_IMPORT_MODAL_REGRESSION: %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
