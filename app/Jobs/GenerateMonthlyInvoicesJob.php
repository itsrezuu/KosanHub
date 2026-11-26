<?php

namespace App\Jobs;

use App\Services\InvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateMonthlyInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 120;

    public function __construct(
        public ?string $period = null
    ) {
        $this->period = $period ?? now()->format('Y-m');
    }

    public function handle(InvoiceService $invoiceService): void
    {
        Log::info('GenerateMonthlyInvoicesJob started', [
            'period' => $this->period,
        ]);

        $result = $invoiceService->generateMonthly($this->period);

        Log::info('GenerateMonthlyInvoicesJob finished', [
            'period' => $this->period,
            'total_created' => $result['total_created'],
            'total_skipped' => count($result['skipped']),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('GenerateMonthlyInvoicesJob failed', [
            'period' => $this->period,
            'exception_class' => $exception ? $exception::class : null,
        ]);
    }
}
