<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$view = (string)file_get_contents($root . '/app/View/pages/company_document_generator.php');
$workflow = (string)file_get_contents($root . '/.github/workflows/document_generator_production_browser_qa.yml');
$qa = (string)file_get_contents($root . '/.github/document-generator/production_check_qa.js');
$qaSession = (string)file_get_contents($root . '/.github/document-generator/qa_session.php');

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
$assert(str_contains($view, "n.className='notice danger'"), 'validation errors use the standard ERP notice danger variant');
$assert(str_contains($view, "classList.add('is-error')"), 'invalid fields use the shared is-error state');
$assert(str_contains($view, "setAttribute('aria-invalid','true')"), 'invalid controls expose aria-invalid');
$assert(str_contains($view, 'Не выбран рейс.'), 'missing trip has clear message');
$assert(str_contains($view, 'Не указана дата начала.'), 'missing start date has clear message');
$assert(str_contains($view, 'Не указана стоимость.'), 'missing price has clear message');
$assert(str_contains($view, 'Не выбран клиент.'), 'client package has clear missing-client message');
$assert(str_contains($view, 'Не указана дата договора.') && str_contains($view, 'Не указана дата счёта.') && str_contains($view, 'Не указана дата акта.'), 'client package validates all three dates');
$assert(!str_contains($view, 'name="package_date"'), 'shared package date field is removed');
$assert(!str_contains($view, 'name="crew_id"'), 'manual executor selector is removed');
$assert(!str_contains($view, 'name="request_input"'), 'manual FEO request input is removed');
$assert(str_contains($view, 'data-docgen-carrier-trip'), 'carrier flow selects a Waste Trip');
$assert(str_contains($view, 'data-docgen-client-trip'), 'client package selects a Waste Trip');
$assert(str_contains($view, "result.dataset.docgenState='error'"), 'visible validation failure exposes error state');
$assert(str_contains($view, "result.dataset.docgenState='checking'"), 'active preview exposes checking state');
$assert(str_contains($view, "result.dataset.docgenState='success'"), 'successful preview exposes success state');
$assert(str_contains($view, "notice([],'Не удалось выполнить проверку')"), 'AJAX/HTTP/JS failure always shows required generic red message');
$assert(str_contains($view, 'check.disabled=v'), 'check button is disabled while request is active');
$assert(str_contains($view, "check.setAttribute('aria-busy',v?'true':'false')"), 'check button exposes loading accessibility state');
$assert(str_contains($view, "check.textContent=v?'Проверяем…':'Проверить'"), 'check button exposes visible loading label');
$assert(str_contains($view, 'await fetch(check.formAction||form.action'), 'AJAX posts to existing canonical preview endpoint');
$assert(str_contains($view, "new DOMParser().parseFromString(await r.text(),'text/html')"), 'AJAX consumes canonical server-rendered preview instead of duplicating backend prepare logic');
$assert(str_contains($view, "e.submitter?.matches('[data-docgen-check]')"), 'AJAX intercepts preview only and leaves generation submit untouched');
$assert(str_contains($view, '[data-docgen-generate]'), 'successful preview preserves canonical generation action');
$assert(str_contains($view, 'Комплект №') && str_contains($view, 'уже был создан.'), 'existing package preview explains the conflict');
$assert(str_contains($view, 'data-docgen-overwrite name="overwrite_existing" value="1"'), 'overwrite action carries explicit server confirmation');
$assert(str_contains($view, "'Комплект уже был создан. Перезаписать договор, счёт и акт?'"), 'package overwrite asks for confirmation');
$assert(str_contains($view, 'if(!confirm(message))'), 'overwrite action requires user confirmation');
$assert(str_contains($view, "'Перезаписать комплект'"), 'existing package action has an explicit overwrite label');
$assert(str_contains($view, "'contract_exists'"), 'existing carrier contract is shown as a warning');
$assert(str_contains($view, 'data-docgen-overwrite-kind='), 'overwrite confirmation identifies the document type');
$assert(str_contains($view, "'Перезаписать договор'"), 'existing carrier contract has an explicit overwrite action');
$assert(str_contains($view, 'Договор уже был создан. Перезаписать договор?'), 'carrier overwrite asks for confirmation');
$assert(str_contains($view, "panel.className='panel'"), 'successful result uses standard ERP panel');
$assert(str_contains($view, 'class="notice danger mb-3"'), 'no-JS server preview also uses standard red error notice');

$assert(str_contains($workflow, 'qa/document-generator-check-production-20260903'), 'production browser QA has isolated exact-SHA trigger branch');
$assert(str_contains($workflow, 'erpv2/deploy'), 'production browser QA requires exact deploy success');
$assert(str_contains($workflow, '.github/document-generator/qa_session.php'), 'production browser QA uses current account-based temporary authenticated-session helper');
$assert(str_contains($qaSession, "r.system_key='company_owner'"), 'temporary QA session resolves a current active company-owner membership');
$assert(str_contains($qaSession, "'account_id' => \$accountId"), 'temporary QA session carries current global account identity');
$assert(str_contains($qaSession, "'user' => ["), 'temporary QA session mirrors canonical nested compatibility identity');
$assert(!str_contains($qaSession, "'role_code' => 'company_owner'"), 'temporary QA session does not grant authority through legacy role metadata');
$assert(str_contains($workflow, 'node .github/document-generator/production_check_qa.js'), 'production browser QA runs executable Playwright scenario');
$assert(str_contains($qa, "mark('EMPTY_FORM')"), 'production browser QA covers empty form');
$assert(str_contains($qa, "mark('PARTIAL_FORM')"), 'production browser QA covers partial form');
$assert(str_contains($qa, "mark('HTTP_JS_FAILURE')"), 'production browser QA covers request failure fallback');
$assert(str_contains($qa, "mark('CORRECT_CONTRACT_PREVIEW'"), 'production browser QA covers successful contract preview');
$assert(str_contains($qa, "mark('CLIENT_PACKAGE_PREVIEW'"), 'production browser QA covers client package preview when eligible data exists');
$assert(str_contains($qa, 'data-docgen-carrier-trip'), 'production browser QA selects a Waste Trip instead of manual executor/FEO fields');
$assert(!str_contains($qa, 'select[name="crew_id"]'), 'production browser QA no longer depends on manual executor selection');
$assert(!str_contains($qa, "set('request_input'"), 'production browser QA no longer depends on manual FEO request entry');
$assert(str_contains($qa, "mark('DOCX_GENERATION'"), 'production browser QA covers successful real DOCX generation');
$assert(str_contains($qa, 'waybill_driver_assignment'), 'real production generation uses waybill path and does not consume automatic contract P-number');
$assert(str_contains($qa, 'waitForResultState'), 'production QA uses CSP-safe locator polling');
$assert(!str_contains($qa, 'waitForFunction'), 'production QA must not use CSP-blocked waitForFunction/eval');

fwrite(STDOUT, "DOCUMENT_GENERATOR_CHECK_UX=PASS assertions={$assertions}\n");
