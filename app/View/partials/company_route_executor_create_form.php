<?php
$routeExecutorCreateFormMode = $routeExecutorCreateFormMode ?? 'page';
$routeExecutorFormId = $routeExecutorFormId ?? 'route-executor-create-form';
$routeExecutorFormAction = $routeExecutorFormAction ?? app_url('/company/route-executors/create');
$formError = $formError ?? null;
$errors = $errors ?? [];
$old = $old ?? [];
$contractors = $contractors ?? [];
$drivers = $drivers ?? [];
$vehicleSets = $vehicleSets ?? [];
$responsibleLogists = $responsibleLogists ?? [];
$selectedDriverIds = $old['driver_ids'] ?? (($old['driver_id'] ?? '') !== '' ? [$old['driver_id']] : ['']);
if (!is_array($selectedDriverIds) || !$selectedDriverIds) $selectedDriverIds = [''];
$removeJs = "var f=this.closest('[data-crew-driver-form]'),r=this.closest('[data-crew-driver-row]');if(f.querySelectorAll('[data-crew-driver-row]').length>1){r.remove();f.querySelectorAll('[data-crew-driver-row]').forEach(function(x,i){var n=x.querySelector('.crew-driver-number');if(n)n.textContent='Водитель '+(i+1);});}";
$addJs = "var f=this.closest('[data-crew-driver-form]'),l=f.querySelector('[data-crew-driver-list]'),r=l.querySelector('[data-crew-driver-row]').cloneNode(true),s=r.querySelector('select[name=\"driver_ids[]\"]');s.value='';var n=r.querySelector('.crew-driver-number');n.textContent='Водитель '+(l.querySelectorAll('[data-crew-driver-row]').length+1);var b=r.querySelector('[data-remove-crew-driver]');if(!b){b=document.createElement('button');b.type='button';b.className='btn btn-ghost crew-driver-remove';b.setAttribute('data-remove-crew-driver','');b.textContent='Удалить';r.appendChild(b);}b.onclick=function(){var ff=this.closest('[data-crew-driver-form]'),rr=this.closest('[data-crew-driver-row]');if(ff.querySelectorAll('[data-crew-driver-row]').length>1){rr.remove();ff.querySelectorAll('[data-crew-driver-row]').forEach(function(x,i){var nn=x.querySelector('.crew-driver-number');if(nn)nn.textContent='Водитель '+(i+1);});}};l.appendChild(r);s.focus();";
?>
<?php if ($formError): ?><div class="notice warn"><?= e($formError) ?></div><?php endif; ?>

<form id="<?= e($routeExecutorFormId) ?>" method="post" action="<?= e($routeExecutorFormAction) ?>" class="<?= $routeExecutorCreateFormMode === 'modal' ? '' : 'panel' ?>" data-crew-driver-form>
    <div class="panel-body">
        <div class="form-section">
            <h3 class="panel-head-title">Исполнитель рейса</h3>
            <p class="text-muted mb-section">Подрядчик + экипаж водителей + ТС</p>

            <div class="field">
                <label class="field-label">Ответственный логист <span class="req">*</span></label>
                <select name="responsible_logist_membership_id" class="field-input" required<?= empty($responsibleLogists) ? ' disabled' : '' ?>>
                    <option value="">— Выберите логиста —</option>
                    <?php foreach ($responsibleLogists as $logist): ?><option value="<?= (int)$logist['membership_id'] ?>" <?= ($old['responsible_logist_membership_id'] ?? '') == $logist['membership_id'] ? 'selected' : '' ?>><?= e($logist['full_name']) ?> (<?= e($logist['login']) ?>)</option><?php endforeach; ?>
                </select>
                <?php if (empty($responsibleLogists)): ?><p class="field-hint">Нет доступных сотрудников для назначения. Обратитесь к руководителю компании.</p><?php endif; ?>
                <?php if (!empty($errors['responsible_logist_membership_id'])): ?><div class="field-msg is-error"><?= e($errors['responsible_logist_membership_id']) ?></div><?php endif; ?>
            </div>

            <div class="field">
                <label class="field-label">Подрядчик <span class="req">*</span></label>
                <select name="contractor_id" class="field-input" required<?= empty($contractors) ? ' disabled' : '' ?>>
                    <option value="">— Выберите подрядчика —</option>
                    <?php foreach ($contractors as $ctr): ?><option value="<?= (int)$ctr['id'] ?>" <?= ($old['contractor_id'] ?? '') == $ctr['id'] ? 'selected' : '' ?>><?= e($ctr['name']) ?> (ИНН: <?= e($ctr['inn']) ?>)</option><?php endforeach; ?>
                </select>
                <?php if (empty($contractors)): ?><p class="field-hint">Нет доступных подрядчиков. <a href="<?= app_url('/company/contractors/create') ?>">Создать подрядчика</a></p><?php endif; ?>
                <?php if (!empty($errors['contractor_id'])): ?><div class="field-msg is-error"><?= e($errors['contractor_id']) ?></div><?php endif; ?>
            </div>

            <div class="field" data-crew-drivers-field>
                <label class="field-label">Экипаж <span class="req">*</span></label>
                <div data-crew-driver-list>
                    <?php foreach (array_values($selectedDriverIds) as $i => $selectedDriverId): ?>
                    <div class="crew-driver-row" data-crew-driver-row>
                        <div class="crew-driver-main">
                            <span class="crew-driver-number">Водитель <?= $i + 1 ?></span>
                            <select name="driver_ids[]" class="field-input" required<?= empty($drivers) ? ' disabled' : '' ?> onchange="var f=this.closest('[data-crew-driver-form]'),v=this.value;if(v&&Array.from(f.querySelectorAll('select[name=&quot;driver_ids[]&quot;]')).filter(function(x){return x.value===v;}).length>1){this.value='';this.setCustomValidity('Этот водитель уже добавлен в экипаж.');this.reportValidity();var s=this;setTimeout(function(){s.setCustomValidity('');},0);}">
                                <option value="">— Выберите водителя —</option>
                                <?php foreach ($drivers as $drv): ?><option value="<?= (int)$drv['id'] ?>" <?= (string)$selectedDriverId === (string)$drv['id'] ? 'selected' : '' ?>><?= e($drv['full_name']) ?><?= !empty($drv['phone']) ? ' — ' . e($drv['phone']) : '' ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($i > 0): ?><button type="button" class="btn btn-ghost crew-driver-remove" data-remove-crew-driver onclick="<?= e($removeJs) ?>">Удалить</button><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-ghost mt-2" onclick="<?= e($addJs) ?>"<?= empty($drivers) ? ' disabled' : '' ?>>+ Добавить водителя</button>
                <p class="field-hint">Минимум один водитель. Можно добавить второго, третьего и следующих водителей на эту же машину.</p>
                <?php if (empty($drivers)): ?><p class="field-hint">Нет доступных водителей. <a href="<?= app_url('/company/drivers') ?>">Создать водителя</a></p><?php endif; ?>
                <?php if (!empty($errors['driver_ids'])): ?><div class="field-msg is-error"><?= e($errors['driver_ids']) ?></div><?php endif; ?>
            </div>

            <div class="field">
                <label class="field-label">Транспорт (ТС) <span class="req">*</span></label>
                <select name="vehicle_set_id" class="field-input" required<?= empty($vehicleSets) ? ' disabled' : '' ?>>
                    <option value="">— Выберите транспортный комплект —</option>
                    <?php foreach ($vehicleSets as $vs): ?><option value="<?= (int)$vs['id'] ?>" <?= ($old['vehicle_set_id'] ?? '') == $vs['id'] ? 'selected' : '' ?>><?= e(ui_set_type($vs['set_type'] ?? null)) ?> — <?= e($vs['primary_plate'] ?? '—') ?><?= !empty($vs['secondary_plate']) ? ' + ' . e($vs['secondary_plate']) : '' ?></option><?php endforeach; ?>
                </select>
                <?php if (empty($vehicleSets)): ?><p class="field-hint">Нет доступных транспортных комплектов. <a href="<?= app_url('/company/vehicle-sets') ?>">Создать ТС</a></p><?php endif; ?>
                <?php if (!empty($errors['vehicle_set_id'])): ?><div class="field-msg is-error"><?= e($errors['vehicle_set_id']) ?></div><?php endif; ?>
            </div>
        </div>

        <div class="form-section"><h3 class="panel-head-title">Дополнительно</h3><div class="field"><label class="field-label">Комментарий</label><textarea name="comments" class="field-textarea" rows="3"><?= e($old['comments'] ?? '') ?></textarea></div></div>
        <?php if ($routeExecutorCreateFormMode === 'page'): ?><div class="form-actions"><button type="submit" class="btn btn-primary">Создать исполнителя рейса</button></div><?php endif; ?>
        <?php if ($routeExecutorCreateFormMode === 'modal'): ?><input type="hidden" name="_is_modal" value="1"><?php endif; ?>
    </div>
</form>
