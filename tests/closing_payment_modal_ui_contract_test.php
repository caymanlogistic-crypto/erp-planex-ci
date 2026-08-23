<?php

declare(strict_types=1);
$main=(string)file_get_contents(__DIR__.'/../app/View/pages/company_finance_closing_documents.php');
foreach(['closing-document-modal','setRemoteHtml','credentials: \'same-origin\'','Closing payment modal load failed'] as $token){
    if(!str_contains($main,$token)) throw new RuntimeException('closing payment modal loader missing '.$token);
}
if(str_contains($main,'planexSetHtmlAndRunScripts')){
    throw new RuntimeException('closing payment modal must not depend on nonexistent global planexSetHtmlAndRunScripts helper');
}
echo "closing_payment_modal_ui_contract: PASS\n";
