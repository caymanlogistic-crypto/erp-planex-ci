<?php

declare(strict_types=1);

$main = (string) file_get_contents(__DIR__ . '/../app/View/pages/company_finance_closing_documents.php');

$required = [
    '.closing-documents-table th.col-check,.closing-documents-table td.col-check{text-align:center;vertical-align:middle!important;padding-left:2px;padding-right:2px}',
    '.closing-doc-status{display:inline-flex;align-items:center;justify-content:center;width:1em;height:1em;font-size:24px;font-weight:700;line-height:1;white-space:nowrap}',
    '.closing-doc-status.table-check--yes{color:#2f7d32!important}',
    '.closing-doc-status.table-check--no{color:#b3261e!important}',
    '<colgroup><col style="width:8%"><col style="width:42%"><col style="width:20%"><col style="width:10%"><col style="width:7%"><col style="width:7%"><col style="width:6%"></colgroup>',
    '<td class="col-check"><?= $renderRequirementStatus($row, \'agreement_or_application\') ?></td>',
    '<td class="col-check"><?= $renderRequirementStatus($row, \'act_or_upd\') ?></td>',
    '<td class="col-check"><?= $renderRequirementStatus($row, \'npd_receipt\') ?></td>',
];

foreach ($required as $token) {
    if (!str_contains($main, $token)) {
        throw new RuntimeException('closing documents status layout missing: ' . $token);
    }
}

foreach (['table-check table-check--yes', 'table-check table-check--no', '>✓</span>', '>×</span>'] as $token) {
    if (!str_contains($main, $token)) {
        throw new RuntimeException('closing documents status icon contract missing: ' . $token);
    }
}

echo "closing_documents_status_layout_ui_contract: PASS\n";
