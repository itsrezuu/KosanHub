<?php

namespace Tests\Feature;

use App\Jobs\GenerateMonthlyInvoicesJob;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\ContractService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContractInvoiceFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_sample_contract_invoice_and_partial_payment_requests_work(): void
    {
        $property = Property::create([
            'name' => 'Test Property',
            'address' => '123 Test Street',
        ]);
        $room = Room::create([
            'property_id' => $property->id,
            'room_number' => 'A-1',
            'monthly_rent' => 1000000,
        ]);
        $tenant = Tenant::create(['name' => 'Test Tenant']);

        $contractResponse = $this->postJson('/api/contracts', [
            'room_id' => $room->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-11-15',
            'monthly_rent' => 1000000,
        ]);

        $contractResponse->assertCreated();
        $contractId = $contractResponse->json('id');

        $invoiceResponse = $this->postJson('/api/invoices/generate', [
            'contract_id' => $contractId,
            'period' => '2026-11',
        ]);

        $invoiceResponse->assertCreated();
        $invoiceId = $invoiceResponse->json('id');

        $paymentResponse = $this->postJson("/api/invoices/{$invoiceId}/pay", [
            'amount' => 500000,
            'method' => 'transfer',
            'reference' => 'TRX-001',
        ]);

        $paymentResponse
            ->assertOk()
            ->assertJsonPath('payment.reference', 'TRX-001');

        $this->assertNotSame('paid', $paymentResponse->json('invoice.status'));
    }

    public function test_monthly_invoice_generation_is_queued_on_redis(): void
    {
        Bus::fake();

        $this->postJson('/api/invoices/generate-monthly', [
            'period' => '2026-11',
        ])
            ->assertAccepted()
            ->assertJsonPath('period', '2026-11');

        Bus::assertDispatched(
            GenerateMonthlyInvoicesJob::class,
            fn (GenerateMonthlyInvoicesJob $job) => $job->period === '2026-11'
                && $job->connection === 'redis'
        );
    }

    public function test_monthly_invoice_job_runs_on_redis_worker_and_persists_active_contracts(): void
    {
        if (env('RUN_REDIS_QUEUE_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set RUN_REDIS_QUEUE_INTEGRATION=1 to run against Redis.');
        }

        $queue = 'invoice-integration-'.Str::uuid();
        Config::set('queue.connections.redis.queue', $queue);
        $activeContractIds = [];

        foreach ([1000000, 1200000] as $monthlyRent) {
            ['room' => $room, 'tenant' => $tenant] = $this->createContractFixture();

            $activeContractIds[] = $this->postJson('/api/contracts', [
                'room_id' => $room->id,
                'tenant_id' => $tenant->id,
                'start_date' => '2026-11-01',
                'monthly_rent' => $monthlyRent,
            ])->assertCreated()->json('id');
        }

        ['room' => $endedRoom, 'tenant' => $endedTenant] = $this->createContractFixture();
        $endedContractId = $this->postJson('/api/contracts', [
            'room_id' => $endedRoom->id,
            'tenant_id' => $endedTenant->id,
            'start_date' => '2026-11-01',
            'monthly_rent' => 900000,
        ])->assertCreated()->json('id');
        $this->postJson("/api/contracts/{$endedContractId}/end")->assertOk();

        $this->postJson('/api/invoices/generate-monthly', [
            'period' => '2026-11',
        ])->assertAccepted();

        $this->assertSame(0, Artisan::call('queue:work', [
            'connection' => 'redis',
            '--queue' => $queue,
            '--once' => true,
            '--tries' => 3,
            '--timeout' => 120,
        ]));

        $this->assertDatabaseCount('invoices', 2);
        foreach ($activeContractIds as $contractId) {
            $this->assertDatabaseHas('invoices', [
                'contract_id' => $contractId,
                'period' => '2026-11',
            ]);
        }
        $this->assertDatabaseMissing('invoices', [
            'contract_id' => $endedContractId,
            'period' => '2026-11',
        ]);
    }

    public function test_monthly_invoice_generation_rejects_an_invalid_period(): void
    {
        Bus::fake();

        $this->postJson('/api/invoices/generate-monthly', [
            'period' => '2026-13',
        ])->assertUnprocessable();

        Bus::assertNotDispatched(GenerateMonthlyInvoicesJob::class);
    }

    public function test_property_crud_endpoints_work(): void
    {
        $property = $this->postJson('/api/properties', [
            'name' => 'CRUD Property',
            'address' => '1 Test Street',
        ])->assertCreated()
            ->assertJsonPath('name', 'CRUD Property')
            ->json();

        $this->getJson('/api/properties')
            ->assertOk()
            ->assertJsonFragment(['name' => 'CRUD Property']);
        $this->getJson("/api/properties/{$property['id']}")
            ->assertOk()
            ->assertJsonPath('address', '1 Test Street');
        $this->putJson("/api/properties/{$property['id']}", [
            'name' => 'Updated Property',
        ])->assertOk()
            ->assertJsonPath('name', 'Updated Property');
        $this->deleteJson("/api/properties/{$property['id']}")
            ->assertOk();

        $this->assertDatabaseMissing('properties', ['id' => $property['id']]);
    }

    public function test_room_crud_endpoints_work(): void
    {
        $property = Property::create([
            'name' => 'Room Property',
            'address' => '2 Test Street',
        ]);
        $room = $this->postJson('/api/rooms', [
            'property_id' => $property->id,
            'room_number' => 'B-1',
            'monthly_rent' => 1200000,
        ])->assertCreated()
            ->assertJsonPath('room_number', 'B-1')
            ->json();

        $this->getJson('/api/rooms')
            ->assertOk()
            ->assertJsonFragment(['room_number' => 'B-1']);
        $this->getJson("/api/rooms/{$room['id']}")
            ->assertOk()
            ->assertJsonPath('property.id', $property->id);
        $this->putJson("/api/rooms/{$room['id']}", [
            'room_number' => 'B-2',
        ])->assertOk()
            ->assertJsonPath('room_number', 'B-2');
        $this->deleteJson("/api/rooms/{$room['id']}")
            ->assertOk();

        $this->assertDatabaseMissing('rooms', ['id' => $room['id']]);
    }

    public function test_tenant_crud_endpoints_work(): void
    {
        $tenant = $this->postJson('/api/tenants', [
            'name' => 'CRUD Tenant',
            'email' => 'crud@example.test',
        ])->assertCreated()
            ->assertJsonPath('name', 'CRUD Tenant')
            ->json();

        $this->getJson('/api/tenants')
            ->assertOk()
            ->assertJsonFragment(['name' => 'CRUD Tenant']);
        $this->getJson("/api/tenants/{$tenant['id']}")
            ->assertOk()
            ->assertJsonPath('email', 'crud@example.test');
        $this->putJson("/api/tenants/{$tenant['id']}", [
            'name' => 'Updated Tenant',
        ])->assertOk()
            ->assertJsonPath('name', 'Updated Tenant');
        $this->deleteJson("/api/tenants/{$tenant['id']}")
            ->assertOk();

        $this->assertDatabaseMissing('tenants', ['id' => $tenant['id']]);
    }

    public function test_health_endpoint_reports_database_connection(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database', 'connected');
    }

    public function test_health_endpoint_returns_service_unavailable_when_database_is_disconnected(): void
    {
        DB::shouldReceive('connection')
            ->once()
            ->andThrow(new \PDOException('Database unavailable'));

        $this->getJson('/health')
            ->assertServiceUnavailable()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('database', 'disconnected');
    }

    public function test_a_room_cannot_have_two_active_contracts(): void
    {
        ['room' => $room, 'tenant' => $tenant] = $this->createContractFixture();

        $payload = [
            'room_id' => $room->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-11-15',
            'monthly_rent' => 1000000,
        ];

        $this->postJson('/api/contracts', $payload)->assertCreated();

        $secondTenant = Tenant::create(['name' => 'Second Test Tenant']);
        $this->postJson('/api/contracts', [
            ...$payload,
            'tenant_id' => $secondTenant->id,
        ])->assertUnprocessable();

        $this->assertSame(1, $room->contracts()->where('status', 'active')->count());
    }

    public function test_ending_a_contract_allows_a_new_contract_for_the_room(): void
    {
        ['room' => $room, 'tenant' => $tenant] = $this->createContractFixture();
        $contract = $this->postJson('/api/contracts', [
            'room_id' => $room->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-11-15',
        ])->assertCreated()->json();

        $this->postJson("/api/contracts/{$contract['id']}/end")
            ->assertOk()
            ->assertJsonPath('status', 'ended');

        $this->assertDatabaseHas('rooms', [
            'id' => $room->id,
            'status' => 'available',
        ]);

        $newTenant = Tenant::create(['name' => 'New Tenant']);
        $this->postJson('/api/contracts', [
            'room_id' => $room->id,
            'tenant_id' => $newTenant->id,
            'start_date' => '2026-12-01',
        ])->assertCreated();
    }

    public function test_first_month_invoice_is_prorated_from_the_contract_start_date(): void
    {
        ['room' => $room, 'tenant' => $tenant] = $this->createContractFixture();
        $contract = $this->postJson('/api/contracts', [
            'room_id' => $room->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-11-16',
            'monthly_rent' => 1000000,
        ])->assertCreated()->json();

        $this->postJson('/api/invoices/generate', [
            'contract_id' => $contract['id'],
            'period' => '2026-11',
        ])
            ->assertCreated()
            ->assertJsonPath('amount', '500000.00');

        $this->postJson('/api/invoices/generate', [
            'contract_id' => $contract['id'],
            'period' => '2026-11',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_first_month_proration_handles_start_and_end_of_month(): void
    {
        $service = new ContractService;

        foreach ([
            ['2026-11-01', 1000000.0],
            ['2026-11-16', 500000.0],
            ['2026-11-30', 33333.33],
        ] as [$startDate, $expectedAmount]) {
            $contract = new Contract([
                'start_date' => $startDate,
                'monthly_rent' => 1000000,
            ]);

            $this->assertSame(
                $expectedAmount,
                $service->calculateProportionalAmount($contract, '2026-11')
            );
        }
    }

    public function test_database_rejects_duplicate_invoice_period_for_a_contract(): void
    {
        ['room' => $room, 'tenant' => $tenant] = $this->createContractFixture();
        $contract = $this->postJson('/api/contracts', [
            'room_id' => $room->id,
            'tenant_id' => $tenant->id,
            'start_date' => '2026-11-01',
            'monthly_rent' => 1000000,
        ])->assertCreated()->json();

        $this->postJson('/api/invoices/generate', [
            'contract_id' => $contract['id'],
            'period' => '2026-11',
        ])->assertCreated();

        $this->expectException(QueryException::class);

        Invoice::create([
            'contract_id' => $contract['id'],
            'period' => '2026-11',
            'due_date' => '2026-12-07',
            'amount' => 1000000,
            'penalty' => 0,
            'total' => 1000000,
            'status' => 'unpaid',
        ]);
    }

    public function test_overdue_endpoint_lists_invoices_with_calculated_penalty(): void
    {
        Carbon::setTestNow('2026-11-14 12:00:00');

        try {
            ['room' => $room, 'tenant' => $tenant] = $this->createContractFixture();
            $contract = $this->postJson('/api/contracts', [
                'room_id' => $room->id,
                'tenant_id' => $tenant->id,
                'start_date' => '2026-10-01',
                'monthly_rent' => 100000,
            ])->assertCreated()->json();

            $this->postJson('/api/invoices/generate', [
                'contract_id' => $contract['id'],
                'period' => '2026-10',
            ])->assertCreated();

            $this->getJson('/api/invoices/overdue')
                ->assertOk()
                ->assertJsonPath('0.period', '2026-10')
                ->assertJsonPath('0.calculated_penalty', 2000);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_late_payment_penalty_is_two_percent_per_week_and_capped_at_ten_percent(): void
    {
        Carbon::setTestNow('2026-11-21 12:00:00');

        try {
            $service = new ContractService;

            $this->assertSame(
                2000.0,
                $service->calculatePenalty(100000, Carbon::parse('2026-11-14'))
            );
            $this->assertSame(
                6000.0,
                $service->calculatePenalty(100000, Carbon::parse('2026-10-31'))
            );
            $this->assertSame(
                10000.0,
                $service->calculatePenalty(100000, Carbon::parse('2026-10-10'))
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    private function createContractFixture(): array
    {
        $property = Property::create([
            'name' => 'Test Property',
            'address' => '123 Test Street',
        ]);
        $room = Room::create([
            'property_id' => $property->id,
            'room_number' => 'A-1',
            'monthly_rent' => 1000000,
        ]);
        $tenant = Tenant::create(['name' => 'Test Tenant']);

        return ['room' => $room, 'tenant' => $tenant];
    }
}
