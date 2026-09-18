<?php
$pageTitle='Выписки со счёта';
$pageContext='Финансы › Выписки со счёта';
$companyId=(int)(getSessionCompanyId()??0);
$bankFinanceSuccess=$_SESSION['bank_finance_success']??null;
$bankFinanceError=$_SESSION['bank_finance_error']??null;
unset($_SESSION['bank_finance_success'],$_SESSION['bank_finance_error']);

$company=null;$accounts=[];$imports=[];$transactions=[];$dailyBalances=[];$bankSettings=[];$reconciliation=[];$dbError=null;
$txTotal=0;$txPages=1;$txPage=1;$search='';$classificationStatus='';
$reconciliationControlDate='2026-07-24';

if($companyId>0){
    try{
        $central=$db->connection();
        $stmt=$central->prepare('SELECT * FROM companies WHERE id=?');
        $stmt->execute([$companyId]);
        $company=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
        if($company&&($company['status']??'')==='active'){
            $pageContext='Финансы › Выписки со счёта › Компания: '.e($company['name']);
            $localPdo=(new \App\Core\Database(companyDatabaseConfig($config,$company)))->connection();
            $accounts=\App\Service\BankFinanceService::getAccounts($localPdo);
            $imports=\App\Service\BankFinanceService::getImports($localPdo);
            $dateFrom=$_GET['date_from']??null;
            $dateTo=$_GET['date_to']??null;
            $txPage=max(1,(int)($_GET['tx_page']??1));
            $txPerPage=max(1,min(500,(int)($_GET['tx_per_page']??100)));
            $search=trim((string)($_GET['q']??''));
            $classificationStatus=(string)($_GET['classification_status']??'');
            if($classificationStatus===''&&$search!==''){
                if(preg_match('/вручн/iu',$search))$classificationStatus='MANUAL';
                elseif(preg_match('/автомат/iu',$search))$classificationStatus='AUTO';
                elseif(preg_match('/конфликт|провер/iu',$search))$classificationStatus='NEEDS_REVIEW';
                elseif(preg_match('/не разнес/iu',$search))$classificationStatus='UNALLOCATED';
            }
            $txResult=\App\Service\FinanceMatchingRuleService::fetchBankRegister($localPdo,[
                'date_from'=>$dateFrom,'date_to'=>$dateTo,'search'=>$search,
                'classification_status'=>$classificationStatus,'page'=>$txPage,'per_page'=>$txPerPage
            ]);
            $transactions=$txResult['data'];
            $txTotal=$txResult['total'];
            $txPages=max(1,$txResult['pages']);
            $dailyBalances=\App\Service\BankFinanceService::getDailyBalances($localPdo,null,1,100,$dateFrom,$dateTo)['data'];
            try{
                $reconciliation=\App\Service\FinanceBankReconciliationCutoffService::reconcileAll($localPdo,$reconciliationControlDate);
            }catch(Throwable $e){
                error_log('Bank reconciliation read error: '.$e->getMessage());
                $reconciliation=['accounts'=>[],'control_from_date'=>$reconciliationControlDate,'summary'=>['all_ok'=>false,'service_error'=>true]];
            }
        }
    }catch(Throwable $e){
        error_log('Bank accounts read error: '.$e->getMessage());
        $dbError='Не удалось загрузить банковские данные компании.';
    }
}

ob_start();
require base_path('app/View/pages/company_bank_accounts.php');
$content=ob_get_clean();

$replaceFirst=static function(string $haystack,string $needle,string $replacement):string{
    $pos=strpos($haystack,$needle);
    return $pos===false?$haystack:substr_replace($haystack,$replacement,$pos,strlen($needle));
};

if(($company['status']??'')==='active'&&$dbError===null){
    if($bankFinanceSuccess){$content='<div class="notice success">'.e($bankFinanceSuccess).'</div>'.$content;}
    if($bankFinanceError){$content='<div class="notice warn">'.e($bankFinanceError).'</div>'.$content;}

    $statusOptions=[''=>'Все статусы','UNALLOCATED'=>'Не разнесено','AUTO'=>'Разнесено автоматически','MANUAL'=>'Разнесено вручную','NEEDS_REVIEW'=>'Конфликт / требует проверки'];
    $statusHtml='<label class="bank-date-field"><span class="bank-control-label">Статус</span><select name="classification_status" class="field-select">';
    foreach($statusOptions as $value=>$label){
        $statusHtml.='<option value="'.e($value).'"'.($classificationStatus===$value?' selected':'').'>'.e($label).'</option>';
    }
    $statusHtml.='</select></label>';
    $statusHtml.='<label class="bank-date-field"><span class="bank-control-label">Поиск</span><input type="search" name="q" class="field-input" value="'.e($search).'" placeholder="№, контрагент, ИНН, назначение, ЦФУ, ДДС"></label>';
    $submit='<button type="submit" class="btn btn-secondary btn-toolbar">Применить</button>';
    $content=str_replace($submit,$statusHtml.$submit,$content);

    $hasFilters=!empty($_GET['date_from'])||!empty($_GET['date_to'])||$classificationStatus!==''||$search!=='';
    $toolbarSearch='<input type="text" class="toolbar-search" placeholder="Поиск по таблице">';
    $toolbarReplacement=$hasFilters?'<a href="'.e(app_url('/company/finance/bank-accounts')).'" class="btn btn-ghost btn-toolbar">Сбросить фильтры</a>':'';
    $content=str_replace($toolbarSearch,$toolbarReplacement,$content);

    $debitHeader='<th class="col-tight">Дебет</th>';
    $creditHeader='<th class="col-tight">Кредит</th>';
    $content=str_replace($debitHeader,'<th class="col-tight" style="text-align:right">Дебет</th>',$content);
    $content=str_replace($creditHeader,'<th class="col-tight" style="text-align:right">Кредит</th>',$content);
    $creditHeaderRight='<th class="col-tight" style="text-align:right">Кредит</th>';
    $extraHeaders='<th>ЦФУ</th><th>Статья ДДС</th><th>Статус</th>';
    $content=$replaceFirst($content,$creditHeaderRight,$creditHeaderRight.$extraHeaders);

    foreach($transactions as $tx){
        $id=(int)($tx['id']??0);
        if($id<=0)continue;
        $rowStart=strpos($content,'<tr data-tx-id="'.$id.'"');
        if($rowStart===false)continue;
        $rowTagEnd=strpos($content,'>',$rowStart);
        if($rowTagEnd===false)continue;
        $classifyUrl=e(app_url('/company/finance/bank-transactions/'.$id.'/classify'));
        $content=substr_replace($content,' data-classify-url="'.$classifyUrl.'"'.(!empty($tx['company_funded_attribution'])?' data-company-funded="1"':''),$rowTagEnd,0);
        $rowEnd=strpos($content,'</tr>',$rowStart);
        if($rowEnd===false)continue;
        $status=(string)($tx['classification_status']??'UNALLOCATED');
        $dds=trim((string)($tx['dds_category_name']??''));
        $attribution=$tx['company_funded_attribution']??null;
        $cells='<td>'.e($tx['cash_flow_center_name']?:'—').'</td>';
        $cells.='<td>'.e($dds!==''?$dds:'—').'</td>';
        if($attribution!==null){
            $employeeUrl=app_url('/company/finance/employee-payments').'?employee_ref='.rawurlencode((string)$attribution['employee_ref']);
            $cells='<td>'.e($tx['cash_flow_center_name']?:'Взаиморасчёты с сотрудниками');
            $cells.='<div class="field-note"><a href="'.e($employeeUrl).'">'.e($attribution['employee_name']).'</a></div></td>';
            $cells.='<td>'.e($dds!==''?$dds:'Оплачено компанией').'<div class="field-note">Учтено за сотрудником: '.e(number_format((float)$attribution['amount'],2,',',' ')).' ₽</div></td>';
        }
        $cells.='<td><span class="'.e(\App\Service\FinanceMatchingRuleService::classificationBadgeClass($status)).'"><span class="dot"></span>'.e(\App\Service\FinanceMatchingRuleService::classificationStatusLabel($status)).'</span>';
        if(!empty($tx['is_internal_transfer'])){$cells.='<div class="field-note">Внутренний перевод</div>';}
        $cells.='</td>';
        $content=substr_replace($content,$cells,$rowEnd,0);
    }

    $content=str_replace('class="col-mono text-danger col-tight','style="text-align:right" class="col-mono text-danger col-tight',$content);
    $content=str_replace('class="col-mono text-success col-tight','style="text-align:right" class="col-mono text-success col-tight',$content);

    $content=str_replace("row.addEventListener('click', function() {","row.addEventListener('dblclick', function() {",$content);
    $detailLoader=<<<'JS'
            var classificationBody = modal.querySelector('[data-tx-detail-classification]');
            if (classificationBody) {
                classificationBody.innerHTML = '<div class="empty-state compact"><p>Загрузка разнесения...</p></div>';
                var classificationUrl = this.getAttribute('data-classify-url');
                if (classificationUrl) {
                    fetch(classificationUrl, {credentials:'same-origin'})
                        .then(function(r) {
                            return r.text().then(function(html) {
                                if (!r.ok) throw new Error(html || ('HTTP ' + r.status));
                                return html;
                            });
                        })
                        .then(function(html) {
                            classificationBody.innerHTML = html;
                            var cfu = classificationBody.querySelector('#bank-cfu-select');
                            var dds = classificationBody.querySelector('#bank-dds-select');
                            if (!cfu || !dds) return;
                            var map = {};
                            try { map = JSON.parse(cfu.getAttribute('data-dds-map') || '{}'); } catch (e) { map = {}; }
                            var selectedDds = Number(cfu.getAttribute('data-selected-dds') || 0);
                            var original = Array.from(dds.querySelectorAll('option[data-dds-id]')).map(function(option) {
                                return {id:Number(option.value), text:option.textContent};
                            });
                            var rebuildDds = function(preserveExisting) {
                                var centerId = Number(cfu.value || 0);
                                var previous = preserveExisting ? Number(dds.value || selectedDds || 0) : 0;
                                var allowed = (map[centerId] || []).map(Number);
                                dds.innerHTML = '';
                                var placeholder = document.createElement('option');
                                placeholder.value = '';
                                placeholder.textContent = centerId
                                    ? (allowed.length ? '— Выберите статью —' : '— Для этого ЦФУ статьи не настроены —')
                                    : '— Сначала выберите ЦФУ —';
                                dds.appendChild(placeholder);
                                original.forEach(function(item) {
                                    if (allowed.indexOf(item.id) === -1) return;
                                    var option = document.createElement('option');
                                    option.value = String(item.id);
                                    option.textContent = item.text;
                                    if (item.id === previous) option.selected = true;
                                    dds.appendChild(option);
                                });
                                dds.disabled = !centerId || allowed.length === 0;
                                if (previous && allowed.indexOf(previous) === -1) dds.value = '';
                            };
                            cfu.addEventListener('change', function() { rebuildDds(false); });
                            rebuildDds(true);
                        })
                        .catch(function(err) {
                            var message = String(err && err.message ? err.message : 'Не удалось загрузить разнесение.').replace(/<[^>]*>/g,'').trim();
                            classificationBody.innerHTML = '<div class="form-alert alert-error">' + (message || 'Не удалось загрузить разнесение.') + '</div>';
                        });
                } else {
                    classificationBody.innerHTML = '<div class="form-alert alert-error">Не определён адрес разнесения операции.</div>';
                }
            }
            window.openModal('tx-detail-modal');
JS;
    $content=$replaceFirst($content,"            window.openModal('tx-detail-modal');",$detailLoader);

    $detailClosing="                </div>\n            </div>\n        </div>\n    </div>\n</div>";
    $detailClosingPos=strrpos($content,$detailClosing);
    if($detailClosingPos!==false){
        $detailReplacement="                </div>\n            </div>\n            <div class=\"tx-detail-classification mt-section\" data-tx-detail-classification><div class=\"empty-state compact\"><p>Двойной щелчок по операции загружает разнесение.</p></div></div>\n        </div>\n    </div>\n</div>";
        $content=substr_replace($content,$detailReplacement,$detailClosingPos,strlen($detailClosing));
    }

    $reconSummary=$reconciliation['summary']??[];
    $controlDateLabel=date('d.m.Y',strtotime($reconciliationControlDate));
    $reconHasError=!($reconSummary['all_ok']??false);
    if($reconHasError){
        $reconBanner='<div class="notice warn bank-reconciliation-banner" data-reconciliation-banner>';
        $reconBanner.='<div><b>Независимая сверка: есть расхождения</b>';
        $reconBanner.=' <span class="field-note">Строгий контроль с '.$controlDateLabel.'. История до этой даты сохранена, но не влияет на текущий статус.</span>';
        if($reconSummary['service_error']??false){$reconBanner.='<div class="text-danger">Ошибка выполнения проверки.</div>';}
        if(($reconSummary['invalid_arithmetic']??0)>0){$reconBanner.='<div class="text-danger">Ошибок арифметики: '.(int)$reconSummary['invalid_arithmetic'].'</div>';}
        if(($reconSummary['gap']??0)>0){$reconBanner.='<div class="text-danger">Разрывов: '.(int)$reconSummary['gap'].'</div>';}
        if(($reconSummary['duplicate']??0)>0){$reconBanner.='<div class="text-danger">Дубликатов: '.(int)$reconSummary['duplicate'].'</div>';}
        if(($reconSummary['mismatch']??0)>0){$reconBanner.='<div class="text-danger">Несовпадений оборотов: '.(int)$reconSummary['mismatch'].'</div>';}
        $reconBanner.='</div><div><button type="button" class="btn btn-secondary btn-toolbar" onclick="window.openModal(\'bank-reconciliation-modal\')">Детали сверки</button></div></div>';
        $tableAnchor='<div class="table-card table-card--standard bank-finance-card bank-transactions-card">';
        $content=$replaceFirst($content,$tableAnchor,$tableAnchor.$reconBanner);
    }

    $legacyReconStart=strpos($content,"<div class=\"panel-section\">\n    <div class=\"section-title\">Независимая сверка выписок</div>");
    if($legacyReconStart!==false){
        $legacyReconEnd=strpos($content,'<div id="bank-statement-upload-modal"',$legacyReconStart);
        if($legacyReconEnd!==false){
            $content=substr_replace($content,'',$legacyReconStart,$legacyReconEnd-$legacyReconStart);
        }
    }

    $nativeApplyConfirm=' onsubmit="return confirm(\'Применить действующие правила ко всем неразнесённым банковским операциям? Ручные разнесения не изменятся.\');"';
    $content=str_replace($nativeApplyConfirm,' data-apply-rules-form',$content);

    $applyRulesModal=<<<'HTML'
<div id="bank-apply-rules-confirm-modal" class="modal-overlay" role="dialog" aria-modal="true" data-close-on-overlay="1" data-close-on-escape="1">
    <div class="modal modal-sm">
        <div class="modal-head">
            <span class="modal-title">Разнести по правилам</span>
            <button type="button" class="modal-close" data-close-modal="bank-apply-rules-confirm-modal">&times;</button>
        </div>
        <div class="modal-body">
            <div class="driver-delete-confirm-title">Применить действующие правила ко всем неразнесённым банковским операциям?</div>
            <div class="driver-delete-confirm-text">Ручные разнесения не изменятся.</div>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-ghost" data-close-modal="bank-apply-rules-confirm-modal">Отмена</button>
            <button type="button" class="btn btn-primary" id="bank-apply-rules-confirm-btn">Разнести</button>
        </div>
    </div>
</div>
HTML;

    $applyRulesScript=<<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function() {
    var pendingApplyRulesForm = null;
    document.querySelectorAll('form[data-apply-rules-form]').forEach(function(form) {
        form.addEventListener('submit', function(event) {
            event.preventDefault();
            pendingApplyRulesForm = form;
            window.openModal('bank-apply-rules-confirm-modal');
        });
    });

    var confirmButton = document.getElementById('bank-apply-rules-confirm-btn');
    if (confirmButton) {
        confirmButton.addEventListener('click', function() {
            if (!pendingApplyRulesForm) return;
            var form = pendingApplyRulesForm;
            pendingApplyRulesForm = null;
            confirmButton.disabled = true;
            form.submit();
        });
    }
});
</script>
HTML;
    $content.=$applyRulesModal.$applyRulesScript;
}

require base_path('app/View/layouts/main.php');
