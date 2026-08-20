<?php

namespace App\Http\Controllers\Superadmin;

final class UiPatternLabController
{
    private const LINEAR_TABLE_VARIANTS = [
        'v1' => ['label' => 'V1 — Baseline / FINAL3 strict', 'file' => 'linear-table-v1-baseline.html'],
        'v2' => ['label' => 'V2 — Information hierarchy', 'file' => 'linear-table-v2-hierarchy.html'],
        'v3' => ['label' => 'V3 — Maximum operational density', 'file' => 'linear-table-v3-compact.html'],
        'v4' => ['label' => 'V4 — Structured / semantic', 'file' => 'linear-table-v4-structured.html'],
    ];

    public function index(): void
    {
        requireRole('superadmin');

        $pageTitle = 'UI Pattern Lab';
        $pageContext = 'UI Pattern Lab';
        $topbarCrumbs = [
            ['label' => 'UI Pattern Lab', 'url' => null],
        ];

        ob_start();
        require base_path('app/View/pages/ui_pattern_lab.php');
        $content = ob_get_clean();

        require base_path('app/View/layouts/main.php');
    }

    public function linearTableIndex(): void
    {
        requireRole('superadmin');

        $pageTitle = 'Linear Table Design Test';
        $pageContext = 'UI Pattern Lab › Линейные рейсы';
        $topbarCrumbs = [
            ['label' => 'UI Pattern Lab', 'url' => app_url('/ui-library')],
            ['label' => 'Линейные рейсы — design test', 'url' => null],
        ];
        $variants = self::LINEAR_TABLE_VARIANTS;

        ob_start();
        ?>
        <div class="page-head">
            <div class="page-head-left">
                <h1 class="page-title">Линейные рейсы — design test</h1>
                <div class="page-summary"><span>Четыре варианта одной и той же реальной ERP-таблицы. Только OWNER review, без внедрения в рабочий экран.</span></div>
            </div>
            <a class="btn btn-secondary" href="<?= e(app_url('/ui-library')) ?>">Назад в UI Pattern Lab</a>
        </div>
        <div class="panel" style="margin-top:10px">
            <div class="panel-body">
                <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px">
                    <?php foreach ($variants as $key => $variant): ?>
                        <a class="btn btn-secondary" style="justify-content:flex-start;min-height:38px" target="_blank" rel="noopener" href="<?= e(app_url('/ui-library/linear-table-design-test/' . $key)) ?>">
                            <?= e($variant['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <p style="margin:10px 0 0;color:var(--text-faint);font-size:11px">Доступ только SUPERADMIN. HTML хранится внутри deploy artifact, вне public web-root, и отдаётся только после ERP role gate.</p>
            </div>
        </div>
        <?php
        $content = ob_get_clean();

        require base_path('app/View/layouts/main.php');
    }

    public function linearTableVariant(string $variant): void
    {
        requireRole('superadmin');

        if (!isset(self::LINEAR_TABLE_VARIANTS[$variant])) {
            http_response_code(404);
            echo 'Design test variant not found.';
            return;
        }

        $file = base_path('app/View/ui-tests/linear-table-design-test/' . self::LINEAR_TABLE_VARIANTS[$variant]['file']);
        if (!is_file($file)) {
            http_response_code(404);
            echo 'Design test artifact not found.';
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; base-uri 'none'; frame-ancestors 'self'");
        readfile($file);
    }
}
