<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$fail = static function (string $message): never { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); };
$must = static function (bool $ok, string $message) use ($fail): void { if (!$ok) $fail($message); };
$read = static function (string $rel) use ($root,$fail): string { $v=@file_get_contents($root.'/'.$rel); if(!is_string($v))$fail('cannot read '.$rel); return $v; };

require_once $root . '/app/Http/Controllers/Company/FeoUtilizerController.php';

$method = new ReflectionMethod(\App\Http\Controllers\Company\FeoUtilizerController::class, 'validateCoordinatePair');
$method->setAccessible(true);
$invoke = static function (array $payload) use ($method): ?string {
    try { $method->invoke(null, $payload); return null; }
    catch (ReflectionException $e) { return $e->getMessage(); }
    catch (Throwable $e) { return $e->getMessage(); }
};
$must($invoke([]) === null, 'empty utilizer coordinates must remain allowed');
$must($invoke(['latitude'=>'55,7558','longitude'=>'37,6176']) === null, 'valid comma decimal coordinates must pass');
$must(str_contains((string)$invoke(['latitude'=>'0','longitude'=>'0']), '0,0 недопустимы'), '0,0 must be rejected');
$must(str_contains((string)$invoke(['latitude'=>'55','longitude'=>'']), 'парой чисел'), 'partial coordinate pair must be rejected');
$must(str_contains((string)$invoke(['latitude'=>'91','longitude'=>'37']), 'допустимый диапазон'), 'out-of-range latitude must be rejected');

$view = $read('app/View/pages/company_feo_utilizers.php');
$must(str_contains($view, 'data-feo-utilizer-page-ready'), 'owner admin browser-ready marker missing');
$must(str_contains($view, "app_url('/company/feo/utilizers') ?>\" aria-current=\"page\">Утилизаторы"), 'Utilizers tab must be active');
$must(!str_contains($view, 'Master-data'), 'developer term Master-data must not be user-facing');
$must(!str_contains($view, 'Override имеет'), 'developer term Override must not be user-facing');
$must(!str_contains($view, 'rules/aliases'), 'developer term rules/aliases must not be user-facing');
$must(str_contains($view, 'Ручное назначение используется первым'), 'manual priority must be explained in operational Russian');
$must(substr_count($view, 'class="feo-field') >= 10, 'edit/create fields must have visible labels');
$must(str_contains($view, 'type="color"') && str_contains($view, 'data-color-value'), 'color picker and visible color value required');
$must(str_contains($view, '@media(max-width:650px)') && str_contains($view, 'grid-template-columns:1fr'), 'mobile stacked edit layout missing');
$must(str_contains($view, 'id="feo-utilizer-confirm-modal"') && str_contains($view, 'aria-hidden="true"'), 'system confirmation modal must be closed by default');
$must(!preg_match('/\b(?:alert|confirm)\s*\(/', $view), 'native alert/confirm must not be used');

echo "FEO_UTILIZER_ADMIN_UX_CONTRACT=PASS\n";
