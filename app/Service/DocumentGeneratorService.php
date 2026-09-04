<?php

namespace App\Service;

require_once __DIR__ . '/DocumentNumberSequenceService.php';
require_once __DIR__ . '/GoogleSheetsRequestContactService.php';
require_once __DIR__ . '/DocumentGeneratorTemplateTrait.php';
require_once __DIR__ . '/DocumentGeneratorWorkflowTrait.php';
require_once __DIR__ . '/DocumentGeneratorAccessTrait.php';
require_once __DIR__ . '/DocumentGeneratorDataTrait.php';

final class DocumentGeneratorService
{
    use DocumentGeneratorTemplateTrait;
    use DocumentGeneratorWorkflowTrait;
    use DocumentGeneratorAccessTrait;
    use DocumentGeneratorDataTrait;
}
