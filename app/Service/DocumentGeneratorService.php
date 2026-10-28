<?php

namespace App\Service;

require_once __DIR__ . '/DocumentNumberSequenceService.php';
require_once __DIR__ . '/GoogleSheetsRequestContactService.php';
require_once __DIR__ . '/RouteExecutorService.php';
require_once __DIR__ . '/DateCalculationService.php';
require_once __DIR__ . '/LinearRouteService.php';
require_once __DIR__ . '/RegulatedDataScopeService.php';
require_once __DIR__ . '/WasteTripGeneralPaymentService.php';
require_once __DIR__ . '/WasteTripCustomerPaymentService.php';
require_once __DIR__ . '/WasteTripCarrierPaymentService.php';
require_once __DIR__ . '/WasteTripService.php';
require_once __DIR__ . '/FinanceAuditLogService.php';
require_once __DIR__ . '/FinanceSettlementCascadeService.php';
require_once __DIR__ . '/FinanceInvoiceService.php';
require_once __DIR__ . '/FinanceObligationService.php';
require_once __DIR__ . '/FinanceInvoicePrepaymentService.php';
require_once __DIR__ . '/ClientPackageInvoiceNumberPolicy.php';
require_once __DIR__ . '/ClientPackageInvoiceSyncService.php';
require_once __DIR__ . '/DocumentGeneratorTemplateTrait.php';
require_once __DIR__ . '/DocumentGeneratorWorkflowTrait.php';
require_once __DIR__ . '/DocumentGeneratorAccessTrait.php';
require_once __DIR__ . '/DocumentGeneratorDataTrait.php';
require_once __DIR__ . '/DocumentGeneratorTripPackageTrait.php';
require_once __DIR__ . '/DocumentGeneratorWarehouseLoadingService.php';
require_once __DIR__ . '/DocumentGeneratorWarehouseUnloadingService.php';

final class DocumentGeneratorService
{
    use DocumentGeneratorTemplateTrait;
    use DocumentGeneratorWorkflowTrait;
    use DocumentGeneratorAccessTrait;
    use DocumentGeneratorDataTrait;
    use DocumentGeneratorTripPackageTrait;
}
