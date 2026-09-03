<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$view = (string)file_get_contents($root . '/app/View/pages/company_document_generator.php');
$workflow = (string)file_get_contents($root . '/.github/workflows/document_generator_production_browser_qa.yml');
$qa = (string)file_get_contents($root . '/.github/document-generator/production_check_qa.js');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($view, 'data-docgen-form novalidate'), 'native required validation cannot silently block the Проверить flow');
$assert(str_contains($view, 'data-docgen-check-result'), 'visible aria-live result container exists');
$assert(str_contains($view, 'data-docgen-check>Проверить</button>'), 'check submitter is explicitly identifiable');
$assert(str_contains($view, "makeNotice('danger'"), 'validation errors use the standard ERP notice danger variant');
$assert(str_contains($view, "classList.add('is-error')"), 'invalid fields use the shared is-error state');
$assert(str_contains($view, "setAttribute('aria-invalid','true')"), 'invalid controls expose aria-invalid');
$assert(str_contains($view, 'Не выбран исполнитель рейса.'), 'missing executor has clear message');
$assert(str_contains($view, 'Не указаны заявки ФЭО.'), 'missing FEO request input has clear message');
$assert(str_contains($view, 'Не указана дата начала.'), 'missing start date has clear message');
$assert(str_contains($view, 'Не указана стоимость.'), 'missing price has clear message');
$assert(str_contains($view, "showErrorResult([],'Не удалось выполнить проверку')"), 'AJAX/HTTP/JS failure always shows required generic red message');
$assert(str_contains($view, "checkButton.disabled=active"), 'check button is disabled while request is active');
$assert(str_contains($view, "checkButton.setAttribute('aria-busy',active?'true':'false')"), 'check button exposes loading accessibility state');
$assert(str_contains($view, "checkButton.textContent=active?'Проверяем…':'Проверить'"), 'check button exposes visible loading label');
$assert(str_contains($view, 'await fetch(checkButton.formAction || form.action'), 'AJAX posts to existing canonical preview endpoint');
$assert(str_contains($view, "new DOMParser().parseFromString(html,'text/html')"), 'AJAX consumes the canonical server-rendered preview instead of duplicating backend prepare logic');
$assert(str_contains($view, "event.submitter?.matches('[data-docgen-check]')"), 'AJAX intercepts preview only and leaves generation submit untouched');
$assert(str_contains($view, 'button[formaction*="/company/document-generator/generate"]'), 'successful preview preserves canonical DOCX generation action');
$assert(str_contains($view, "panel.className='panel'"), 'successful result uses standard ERP panel');
$assert(str_contains($view, 'class="notice danger mb-3"'), 'no-JS server preview also uses standard red error notice');

$assert(str_contains($workflow, 'qa/document-generator-check-production-20260903'), 'production browser QA has isolated exact-SHA trigger branch');
$assert(str_contains($workflow, 'erpv2/deploy'), 'production browser QA requires exact deploy success');
$assert(str_contains($workflow, '.github/feo-map-stage1/qa_session.php'), 'production browser QA reuses canonical temporary authenticated-session helper');
$assert(str_contains($workflow, 'node .github/document-generator/production_check_qa.js'), 'production browser QA runs executable Playwright scenario');
$assert(str_contains($qa, "mark('EMPTY_FORM')"), 'production browser QA covers empty form');
$assert(str_contains($qa, "mark('PARTIAL_FORM')"), 'production browser QA covers partial form');
$assert(str_contains($qa, "mark('HTTP_JS_FAILURE')"), 'production browser QA covers request failure fallback');
$assert(str_contains($qa, "mark('CORRECT_CONTRACT_PREVIEW')"), 'production browser QA covers successful contract preview');
$assert(str_contains($qa, "mark('DOCX_GENERATION'"), 'production browser QA covers successful real DOCX generation');
$assert(str_contains($qa, 'waybill_driver_assignment'), 'real production generation uses waybill path and does not consume automatic contract P-number');

fwrite(STDOUT, "DOCUMENT_GENERATOR_CHECK_UX=PASS assertions={$assertions}\n");
