<?php
$assignmentEntityType = (string) ($assignmentEntityType ?? '');
$assignmentEntityId = (int) ($assignmentEntityId ?? 0);
$assignmentAllowedTypes = ['client', 'contractor', 'vehicle_unit', 'driver'];
$assignmentVisible = class_exists(\App\Security\AccessRuntime::class)
    && \App\Security\AccessRuntime::current()->isSystemOwner()
    && in_array($assignmentEntityType, $assignmentAllowedTypes, true)
    && $assignmentEntityId > 0;
?>
<?php if ($assignmentVisible): ?>
<section class="roles-access__inline-assignments" data-entity-assignment-block data-entity-type="<?= e($assignmentEntityType) ?>" data-entity-id="<?= $assignmentEntityId ?>">
    <h4>Закреплено за</h4>
    <p class="roles-access-modal__help">Определяет объекты, которые пользователь видит в режиме «Только свои».</p>
    <div data-entity-assignment-content><div class="roles-access__loading">Загрузка закреплений…</div></div>
</section>
<?php endif; ?>
