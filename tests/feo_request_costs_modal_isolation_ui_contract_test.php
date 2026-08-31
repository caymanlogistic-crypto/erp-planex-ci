<?php
$root = dirname(__DIR__);
$js = file_get_contents($root . '/public/assets/js/feo-request-costs.js');
if ($js === false) {
    fwrite(STDERR, "Cannot read FEO request costs JS.\n");
    exit(1);
}
$checks = [
    "function bindCostBlockRow(row,idKey)" => 'cost rows must use a page-local event isolation helper',
    "row.addEventListener('click',e=>{if(interactive(e))return;e.stopPropagation();});" => 'single clicks must not reach shared delegated popup handlers',
    "row.addEventListener('dblclick',e=>{if(interactive(e))return;e.preventDefault();e.stopPropagation();openBlock(row.dataset[idKey]);});" => 'double click must open only the intended cost block modal',
    "qa('[data-cost-block-id]').forEach(row=>bindCostBlockRow(row,'costBlockId'));" => 'main cost rows must be isolated',
    "qa('[data-cost-search-block-id]',searchResults).forEach(row=>bindCostBlockRow(row,'costSearchBlockId'));" => 'dynamic search-result rows must be isolated too',
    "function showDetailModal(){return new Promise(resolve=>requestAnimationFrame(()=>{openModal('feo-cost-detail-modal');resolve();}));}" => 'detail modal must be revealed on a frame only after content is prepared',
    "syncEditSummary();await showDetailModal();" => 'successful detail loading must fully hydrate the edit UI before revealing the modal',
];
foreach ($checks as $needle => $message) {
    if (!str_contains($js, $needle)) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}
if (str_contains($js, "window.openModal=")) {
    fwrite(STDERR, "FAIL: cost page must not replace the shared modal implementation.\n");
    exit(1);
}
if (str_contains($js, "body.textContent='Загрузка…';err?.classList.add('is-hidden');openModal('feo-cost-detail-modal');")) {
    fwrite(STDERR, "FAIL: detail modal must not open in a partially loaded state.\n");
    exit(1);
}
echo "FEO_REQUEST_COSTS_MODAL_ISOLATION_UI=PASS\n";
