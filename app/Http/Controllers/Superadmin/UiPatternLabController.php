<?php

namespace App\Http\Controllers\Superadmin;

final class UiPatternLabController
{
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
}
