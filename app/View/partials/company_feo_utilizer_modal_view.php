<?php if ($modalError || !$utilizer): ?>
<div class="modal-body driver-modal-body">
    <div class="notice warn"><?= e((string)($modalError ?: 'Утилизатор не найден.')) ?></div>
</div>
<div class="modal-foot is-spaced">
    <div></div>
    <div class="modal-foot-actions"><button type="button" class="btn btn-secondary" data-feo-utilizer-close-btn>Закрыть</button></div>
</div>
<?php else: ?>
<?php
$coords = (($utilizer['latitude'] ?? null) !== null && ($utilizer['longitude'] ?? null) !== null)
    ? trim((string)$utilizer['latitude']) . ', ' . trim((string)$utilizer['longitude'])
    : '—';
$aliases = is_array($utilizer['aliases'] ?? null) ? $utilizer['aliases'] : [];
$aliasText = $aliases === [] ? '—' : implode("\n", array_values(array_filter(array_map(
    static fn(array $row): string => trim((string)($row['alias'] ?? '')),
    $aliases
))));
?>
<div class="modal-body driver-modal-body">
    <div class="driver-view-name feo-utilizer-view-name"><?= e((string)$utilizer['short_name']) ?></div>
    <div class="driver-view-card">
        <div class="driver-view-grid">
            <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Полное название</div><div class="driver-view-cell driver-view-cell-value"><?= e((string)$utilizer['full_name']) ?></div></div>
            <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Синонимы</div><div class="driver-view-cell driver-view-cell-value<?= $aliases === [] ? ' is-na' : '' ?> feo-utilizer-alias-view"><?= e($aliasText) ?></div></div>
            <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Адрес</div><div class="driver-view-cell driver-view-cell-value<?= empty($utilizer['address']) ? ' is-na' : '' ?>"><?= e((string)($utilizer['address'] ?: '—')) ?></div></div>
            <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Координаты</div><div class="driver-view-cell driver-view-cell-value<?= $coords === '—' ? ' is-na' : '' ?>"><?= e($coords) ?></div></div>
            <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">ФККО / классификация</div><div class="driver-view-cell driver-view-cell-value<?= empty($utilizer['fkko_classification']) ? ' is-na' : '' ?>"><?= e((string)($utilizer['fkko_classification'] ?: '—')) ?></div></div>
            <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">ADR</div><div class="driver-view-cell driver-view-cell-value"><?= (int)$utilizer['adr'] === 1 ? 'Да' : 'Нет' ?></div></div>
            <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Цвет</div><div class="driver-view-cell driver-view-cell-value"><span class="feo-utilizer-color"><span class="feo-utilizer-color-dot" style="background:<?= e((string)$utilizer['color_hex']) ?>"></span><span class="col-mono"><?= e((string)$utilizer['color_hex']) ?></span></span></div></div>
            <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Статус</div><div class="driver-view-cell driver-view-cell-value"><?= (int)$utilizer['is_active'] === 1 ? 'Активен' : 'Отключён' ?></div></div>
            <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">Порядок</div><div class="driver-view-cell driver-view-cell-value col-mono"><?= (int)$utilizer['sort_order'] ?></div></div>
            <div class="driver-view-row"><div class="driver-view-cell driver-view-cell-label">ID</div><div class="driver-view-cell driver-view-cell-value col-mono">#<?= (int)$utilizer['id'] ?></div></div>
        </div>
    </div>
</div>
<div class="modal-foot is-spaced">
    <div class="modal-foot-actions"><button type="button" class="btn btn-danger" data-feo-utilizer-delete-btn>Удалить</button></div>
    <div class="modal-foot-actions"><button type="button" class="btn btn-secondary" data-feo-utilizer-close-btn>Закрыть</button><button type="button" class="btn btn-primary" data-feo-utilizer-edit-btn>Редактировать</button></div>
</div>
<?php endif; ?>
