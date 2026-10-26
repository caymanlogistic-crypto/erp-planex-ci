<?php
require_once base_path('app/View/components/registry_tabler_icons.php');
require base_path('app/View/partials/registry_ui_state_scope.php');
$contactCount = static function (int $count): string {
    $word = ($count % 100 >= 11 && $count % 100 <= 14) ? 'контактов' : match ($count % 10) {
        1 => 'контакт', 2, 3, 4 => 'контакта', default => 'контактов',
    };
    return $count.' '.$word;
};
?>
<link rel="stylesheet" href="<?= app_url('/assets/css/warehouse-directory.css') ?>?v=<?= filemtime(base_path('public/assets/css/warehouse-directory.css')) ?>">
<link rel="stylesheet" href="<?= app_url('/assets/css/registry-components.css') ?>?v=<?= filemtime(base_path('public/assets/css/registry-components.css')) ?>">
<div data-warehouse-directory>
    <?php
    $registryHeadTitle = 'Адреса складов';
    $registryHeadSummary = 'Адреса и контакты существующих складов.';
    $registryHeadIconHtml = registry_tabler_header_icon('map-pin');
    $registryHeadActionsHtml = '';
    require base_path('app/View/partials/registry_ui_head.php');
    ?>
    <?php if ($successFlash): ?><div class="notice success" role="status"><?= e((string)$successFlash) ?></div><?php endif; ?>
    <?php if ($errorFlash): ?><div class="notice warn" role="alert"><?= e((string)$errorFlash) ?></div><?php endif; ?>
    <?php if ($dbError): ?><div class="notice warn" role="alert"><?= e($dbError) ?></div><?php else: ?>
    <div class="table-card table-card--standard registry-ui-card registry-ui-sticky-table registry-ui-sticky-table--wide-scroll registry-ui-scroll"
         data-registry-id="warehouse-addresses" data-registry-state-scope="<?= e($registryUiStateScope) ?>">
        <div class="table-toolbar registry-ui-toolbar">
            <div class="found-label">Найдено: <b data-warehouse-count><?= count($warehouses) ?></b></div>
            <div class="toolbar-right">
                <button type="button" class="registry-ui-reset" hidden>Сбросить фильтр</button>
                <div class="registry-ui-search-group"><input class="toolbar-search registry-ui-search" type="search" data-registry-field="query" data-registry-default="" placeholder="Поиск на странице" aria-label="Поиск складов"></div>
            </div>
        </div>
        <div class="table-scroll"><table class="table registry-ui-table registry-ui-data">
            <colgroup><col class="registry-ui-id"><col class="warehouse-directory-name"><col><col class="warehouse-directory-contacts"><col class="warehouse-directory-requests"><col class="warehouse-directory-weight"></colgroup>
            <thead><tr><th class="registry-ui-id">ID</th><th>Склад</th><th>Адрес</th><th>Контакты</th><th>Заявки на складе</th><th>Вес на складе, кг</th></tr></thead>
            <tbody>
            <?php foreach ($warehouses as $warehouse):
                $id = (int)$warehouse['id'];
                $contacts = $warehouse['contacts'];
                $formattedWeight = number_format((int)$warehouse['weight_kg'], 0, ',', ' ');
                $search = (string)$warehouse['name'].' '.(string)$warehouse['address'].' '.(int)$warehouse['request_count'].' '.$formattedWeight;
                foreach ($contacts as $contact) $search .= ' '.implode(' ', array_map('strval', array_values($contact)));
            ?>
                <tr data-warehouse-row data-registry-row-id="<?= $id ?>" data-registry-search="<?= e($search) ?>">
                    <td class="registry-ui-id registry-ui-open-detail" role="button" tabindex="0" data-warehouse-open="<?= $id ?>" aria-label="Открыть адрес и контакты склада <?= e((string)$warehouse['name']) ?>">#<?= $id ?></td>
                    <td><span class="registry-ui-name"><?= e((string)$warehouse['name']) ?></span></td>
                    <td><span class="registry-ui-primary"><?= $warehouse['address'] !== '' ? e((string)$warehouse['address']) : '<span class="registry-ui-missing">—</span>' ?></span></td>
                    <td><span class="registry-ui-secondary"><?= e($contactCount(count($contacts))) ?></span></td>
                    <td><span class="registry-ui-primary"><?= (int)$warehouse['request_count'] ?></span></td>
                    <td><span class="registry-ui-primary"><?= e($formattedWeight) ?> кг</span></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($warehouses === []): ?><tr data-warehouse-empty><td colspan="6"><span class="registry-ui-secondary">Склады не найдены.</span></td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
    <?php foreach ($warehouses as $warehouse):
        $id = (int)$warehouse['id'];
        $contacts = $warehouse['contacts'];
    ?>
    <dialog class="modal modal-lg registry-ui-modal registry-ui-modal--warehouse-directory" data-warehouse-dialog="<?= $id ?>" aria-labelledby="warehouse-title-<?= $id ?>">
        <div class="modal-head"><span class="modal-title" id="warehouse-title-<?= $id ?>"><?= e((string)$warehouse['name']) ?></span><button type="button" class="modal-close" data-warehouse-close aria-label="Закрыть">&times;</button></div>
        <?php if ($isOwner): ?>
        <form class="registry-ui-modal-form" method="post" action="<?= app_url('/company/warehouses/addresses') ?>" data-warehouse-form>
            <?= csrfField() ?>
            <input type="hidden" name="warehouse_id" value="<?= $id ?>">
            <input type="hidden" name="expected_address" value="<?= e((string)$warehouse['address']) ?>">
            <input type="hidden" name="expected_contacts_hash" value="<?= e(hash('sha256', json_encode($contacts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))) ?>">
            <input type="hidden" name="expected_coordinates_hash" value="<?= e(hash('sha256', json_encode([$warehouse['latitude'], $warehouse['longitude']], JSON_THROW_ON_ERROR))) ?>">
            <div class="modal-body"><div class="registry-ui-modal-grid">
                <label class="field"><span class="field-label">Полный адрес</span><input class="field-input" name="address" maxlength="500" required value="<?= e((string)$warehouse['address']) ?>"></label>
                <label class="field"><span class="field-label">Широта</span><input class="field-input" name="latitude" type="text" inputmode="decimal" value="<?= e((string)($warehouse['latitude'] ?? '')) ?>" aria-label="Широта склада"></label>
                <label class="field"><span class="field-label">Долгота</span><input class="field-input" name="longitude" type="text" inputmode="decimal" value="<?= e((string)($warehouse['longitude'] ?? '')) ?>" aria-label="Долгота склада"></label>
                <div class="field"><span class="field-label">Контакты склада</span><div class="registry-ui-modal-grid" data-warehouse-contacts>
                    <?php foreach ($contacts as $index => $contact): ?>
                    <fieldset class="registry-ui-modal-grid" data-warehouse-contact>
                        <legend class="field-label">Контакт <?= $index + 1 ?></legend>
                        <label class="field"><span class="field-label">ФИО</span><input class="field-input" name="contact_name[]" maxlength="255" value="<?= e((string)($contact['name'] ?? '')) ?>"></label>
                        <label class="field"><span class="field-label">Должность</span><input class="field-input" name="contact_position[]" maxlength="255" value="<?= e((string)($contact['position'] ?? '')) ?>"></label>
                        <label class="field"><span class="field-label">Телефон</span><input class="field-input" name="contact_phone[]" type="tel" maxlength="80" value="<?= e((string)($contact['phone'] ?? '')) ?>"></label>
                        <label class="field"><span class="field-label">Email</span><input class="field-input" name="contact_email[]" type="email" maxlength="255" value="<?= e((string)($contact['email'] ?? '')) ?>"></label>
                        <button type="button" class="btn btn-ghost" data-warehouse-remove-contact>Удалить контакт</button>
                    </fieldset>
                    <?php endforeach; ?>
                </div><button type="button" class="btn btn-secondary" data-warehouse-add-contact>Добавить контакт</button></div>
            </div></div>
            <div class="modal-foot"><div class="modal-foot-actions"><button type="button" class="btn btn-ghost" data-warehouse-close>Отменить</button><button type="submit" class="btn btn-primary">Сохранить</button></div></div>
        </form>
        <?php else: ?>
        <div class="modal-body"><div class="registry-ui-modal-grid">
            <div class="field"><span class="field-label">Полный адрес</span><span class="registry-ui-modal-text"><?= $warehouse['address'] !== '' ? e((string)$warehouse['address']) : '—' ?></span></div>
            <div class="field"><span class="field-label">Координаты</span><span class="registry-ui-modal-text"><?= $warehouse['latitude'] !== null ? e($warehouse['latitude'].', '.$warehouse['longitude']) : '—' ?></span></div>
            <div class="field"><span class="field-label">Контакты склада</span>
                <?php if ($contacts === []): ?><span class="registry-ui-modal-text">Контакты не указаны.</span><?php endif; ?>
                <?php foreach ($contacts as $contact): ?><div class="registry-ui-modal-grid">
                    <span class="registry-ui-modal-text"><?= e((string)($contact['name'] ?? '')) ?></span>
                    <?php if (($contact['position'] ?? '') !== ''): ?><span class="registry-ui-modal-secondary"><?= e((string)$contact['position']) ?></span><?php endif; ?>
                    <?php if (($contact['phone'] ?? '') !== ''): ?><span class="registry-ui-modal-secondary"><?= e((string)$contact['phone']) ?></span><?php endif; ?>
                    <?php if (($contact['email'] ?? '') !== ''): ?><span class="registry-ui-modal-secondary"><?= e((string)$contact['email']) ?></span><?php endif; ?>
                </div><?php endforeach; ?>
            </div>
        </div></div>
        <div class="modal-foot"><div class="modal-foot-actions"><button type="button" class="btn btn-ghost" data-warehouse-close>Закрыть</button></div></div>
        <?php endif; ?>
    </dialog>
    <?php endforeach; ?>
    <template data-warehouse-contact-template><fieldset class="registry-ui-modal-grid" data-warehouse-contact>
        <legend class="field-label">Контакт</legend>
        <label class="field"><span class="field-label">ФИО</span><input class="field-input" name="contact_name[]" maxlength="255"></label>
        <label class="field"><span class="field-label">Должность</span><input class="field-input" name="contact_position[]" maxlength="255"></label>
        <label class="field"><span class="field-label">Телефон</span><input class="field-input" name="contact_phone[]" type="tel" maxlength="80"></label>
        <label class="field"><span class="field-label">Email</span><input class="field-input" name="contact_email[]" type="email" maxlength="255"></label>
        <button type="button" class="btn btn-ghost" data-warehouse-remove-contact>Удалить контакт</button>
    </fieldset></template>
    <?php endif; ?>
</div>
<script src="<?= app_url('/assets/js/registry-components.js') ?>?v=<?= filemtime(base_path('public/assets/js/registry-components.js')) ?>" defer></script>
<script src="<?= app_url('/assets/js/warehouse-directory.js') ?>?v=<?= filemtime(base_path('public/assets/js/warehouse-directory.js')) ?>" defer></script>
