<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Invoice\CancelInvoiceActionRequest;
use App\Http\Requests\Admin\V2\Invoice\ListInvoicesRequest;
use App\Http\Requests\Admin\V2\Invoice\ShowInvoiceRequest;
use App\Http\Resources\Admin\V2\AdminActionResultResource;
use App\Http\Resources\Admin\V2\AdminInvoiceDetailResource;
use App\Http\Resources\Admin\V2\AdminInvoiceSummaryResource;
use App\Models\Invoice;
use App\Services\Admin\V2\AdminInvoiceActionV2Service;
use App\Services\Finance\InvoiceV2QueryService;
use Illuminate\Http\JsonResponse;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceV2QueryService $invoices,
        private readonly AdminInvoiceActionV2Service $actions,
    ) {}

    public function index(ListInvoicesRequest $request): JsonResponse
    {
        return $this->paginate(
            $this->invoices->paginateAdminInvoices($request->validated()),
            AdminInvoiceSummaryResource::class
        );
    }

    public function show(ShowInvoiceRequest $request, int $invoice): JsonResponse
    {
        return $this->success([
            'invoice' => (new AdminInvoiceDetailResource($this->invoices->adminInvoiceDetail($invoice)))->resolve(),
        ]);
    }

    public function cancel(CancelInvoiceActionRequest $request, Invoice $invoice): JsonResponse
    {
        $result = $this->actions->cancel($invoice, $request);

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }
}
