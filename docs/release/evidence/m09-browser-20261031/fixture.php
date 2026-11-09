<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Products\Domain\Product;
use App\Modules\WorkshopRepairs\Application\WorkshopActions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';
putenv('DB_HOST=127.0.0.1');
$_ENV['DB_HOST'] = $_SERVER['DB_HOST'] = '127.0.0.1';
$expectedPort = (int) (getenv('PHASE5_WORKSHOP_DB_PORT') ?: 55475);
$storageRoot = getenv('PHASE5_WORKSHOP_STORAGE_ROOT');
if ($expectedPort !== 55475 || ! $storageRoot
    || ! str_contains(str_replace('\\', '/', $storageRoot), '/.tmp/m09-browser-20261031/storage-')) {
    throw new RuntimeException('ABORT: M09 browser fixture requires PostgreSQL 55475 and isolated private workspace storage.');
}
foreach (['app/private', 'framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
    if (! is_dir($storageRoot.'/'.$directory) && ! mkdir($storageRoot.'/'.$directory, 0700, true) && ! is_dir($storageRoot.'/'.$directory)) {
        throw new RuntimeException('Unable to create isolated M09 browser storage.');
    }
}
$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($storageRoot);
$app->make(Kernel::class)->bootstrap();

$db = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test'
    || $db->db !== 'erp_elec_new_test' || ! in_array($db->address, ['127.0.0.1', '127.0.0.1/32'], true)
    || (int) $db->port !== $expectedPort || ! in_array(config('database.connections.pgsql.url'), [null, ''], true)
    || ! DB::table('migrations')->where('migration', '2026_10_31_195000_link_company_repair_inventory_identity')->exists()) {
    throw new RuntimeException('ABORT before M09 browser writes: expected isolated PostgreSQL erp_elec_new_test schema 195 on 127.0.0.1:55475.');
}

$mode = getenv('PHASE5_WORKSHOP_FIXTURE_MODE') ?: 'prepare';
if ($mode === 'prepare_tender') {
    $password = (string) (getenv('PHASE5_WORKSHOP_PASSWORD') ?: '');
    $tag = (string) (getenv('PHASE5_WORKSHOP_TAG') ?: '');
    if (strlen($password) < 24 || ! preg_match('/^[A-F0-9]{10}$/', $tag)) {
        throw new RuntimeException('M07 browser fixture tag/password environment is missing.');
    }
    $company = Company::query()->firstOrFail();
    $branch = Branch::query()->where('id', 1)->where('company_id', $company->id)->where('active', true)->firstOrFail();
    $roles = [
        'maker' => ['M07.tender_view', 'M07.tender_manage', 'M07.tender_technical_manage', 'M07.tender_financial_manage', 'M07.tender_financial_view', 'M07.tender_submit', 'M07.tender_award', 'M07.tender_handoff', 'M07.tender_document_view', 'M07.tender_report', 'M07.tender_export', 'M07.project_create', 'M07.project_view', 'M07.project_update', 'M09.view', 'M09.deliver', 'M09.document_view', 'M09.reports_view'],
        'technical_reviewer' => ['M07.tender_view', 'M07.tender_technical_approve', 'M07.tender_document_view'],
        'financial_reviewer' => ['M07.tender_view', 'M07.tender_financial_approve', 'M07.tender_financial_view', 'M07.tender_handoff', 'M07.tender_document_view'],
        'outsider' => [],
    ];
    $actors = [];
    foreach ($roles as $role => $permissions) {
        $user = User::factory()->create([
            'name' => 'DEMO-EG M07 '.$role.' '.$tag,
            'email' => 'tender-'.$role.'-'.$tag.'@example.test',
            'password' => Hash::make($password),
            'active' => true,
            'email_verified_at' => now(),
        ]);
        $actors[$role] = ['id' => (int) $user->id, 'email' => $user->email];
        if ($role !== 'outsider') {
            DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($permissions as $permission) {
                DB::table('access_grants')->insert(['user_id' => $user->id, 'permission_code' => $permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
    foreach (['M03.customer_view', 'M02.product_view', 'M11.tax_calculate'] as $permission) {
        DB::table('access_grants')->insert(['user_id' => $actors['maker']['id'], 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    $customer = Customer::query()->where('id', 1)->where('status', 'active')->firstOrFail();
    echo json_encode([
        'database' => $db->db,
        'port' => (int) $db->port,
        'tag' => $tag,
        'branch_id' => (int) $branch->id,
        'customer_id' => (int) $customer->id,
        'customer_code' => $customer->code,
        'actors' => $actors,
        'm09_job' => (int) DB::table('workshop_jobs')->orderByDesc('id')->value('id'),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit(0);
}
if ($mode === 'populate_tender_boq') {
    $target = str_replace('\\', '/', (string) (getenv('PHASE5_WORKSHOP_WORKBOOK_PATH') ?: ''));
    if (! str_contains($target, '/.tmp/m09-browser-20261031/') || ! is_file($target) || ! class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
        throw new RuntimeException('ABORT: BOQ fixture needs an isolated downloaded System workbook and PhpSpreadsheet.');
    }
    $workbook = \PhpOffice\PhpSpreadsheet\IOFactory::load($target);
    $sheet = $workbook->getSheetByName('Data');
    if (! $sheet) { $workbook->disconnectWorksheets(); throw new RuntimeException('System BOQ workbook has no Data sheet.'); }
    foreach (['Supplementary imported enclosure assembly', '3.00000000', 'piece', 'IP65, copper busbar and verified thermal rise', 'compliant', 'none', 'none', 'DEMO-EG-CAT-ACCEPTANCE'] as $index => $value) {
        $sheet->setCellValueExplicit([$index + 1, 2], $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($workbook))->save($target);
    $workbook->disconnectWorksheets();
    echo json_encode(['database' => $db->db, 'port' => (int) $db->port, 'bytes' => filesize($target), 'row' => 'Supplementary imported enclosure assembly'], JSON_THROW_ON_ERROR);
    exit(0);
}
if ($mode === 'supplemental_readback') {
    $id = (int) (getenv('PHASE5_WORKSHOP_TENDER_ID') ?: 0);
    $tender = $id ? DB::table('project_tenders')->find($id) : null;
    $technical = $tender?->technical_id ? DB::table('project_tender_technical')->find($tender->technical_id) : null;
    $guarantees = $id ? DB::table('project_tender_guarantees as g')->leftJoin('project_tender_guarantee_events as e', 'e.guarantee_id', '=', 'g.id')->where('g.tender_id', $id)->orderBy('g.id')->get(['g.id', 'g.reference', 'g.amount', 'g.kind', 'g.recorded_by', 'e.status as settled_status', 'e.recorded_by as settled_by']) : collect();
    $counts = [];
    foreach (['inventory_movements', 'accounting_journals', 'treasury_movements'] as $table) { $counts[$table] = DB::table($table)->count(); }
    echo json_encode([
        'database' => $db->db, 'port' => (int) $db->port,
        'tender' => $tender ? ['id' => (int) $tender->id, 'number' => $tender->number, 'status' => $tender->status, 'version' => (int) $tender->version, 'technical_id' => $tender->technical_id ? (int) $tender->technical_id : null, 'financial_id' => $tender->financial_id ? (int) $tender->financial_id : null] : null,
        'technical' => $technical ? ['id' => (int) $technical->id, 'revision' => (int) $technical->revision, 'snapshot' => json_decode($technical->snapshot, true)] : null,
        'handoff_count' => $id ? DB::table('project_tender_handoffs')->where('tender_id', $id)->count() : null,
        'events' => $id ? DB::table('project_tender_events')->where('tender_id', $id)->orderByDesc('id')->limit(12)->get(['action', 'actor_id', 'snapshot'])->map(fn ($row) => ['action' => $row->action, 'actor_id' => (int) $row->actor_id, 'snapshot' => json_decode($row->snapshot, true)])->all() : [],
        'documents' => $id ? DB::table('project_tender_documents')->where('tender_id', $id)->orderBy('id')->get(['id', 'title', 'classification', 'sha256'])->map(fn ($row) => (array) $row)->all() : [],
        'guarantees' => $guarantees->map(fn ($row) => ['id' => (int) $row->id, 'reference' => $row->reference, 'amount' => $row->amount, 'kind' => $row->kind, 'recorded_by' => (int) $row->recorded_by, 'settled_status' => $row->settled_status, 'settled_by' => $row->settled_by ? (int) $row->settled_by : null])->all(),
        'ledger_counts' => $counts,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit(0);
}
if ($mode === 'inspect_tender_export') {
    $target = str_replace('\\', '/', (string) (getenv('PHASE5_WORKSHOP_EXPORT_PATH') ?: ''));
    if (! str_contains($target, '/.tmp/m09-browser-20261031/') || ! is_file($target) || ! class_exists(ZipArchive::class)) {
        throw new RuntimeException('ABORT: tender export inspection needs a private workspace XLSX and ZipArchive.');
    }
    $zip = new ZipArchive();
    if ($zip->open($target) !== true) { throw new RuntimeException('Unable to open tender workbook export.'); }
    $xml = ($zip->getFromName('xl/sharedStrings.xml') ?: '').($zip->getFromName('xl/worksheets/sheet1.xml') ?: '');
    $zip->close();
    echo json_encode(['database' => $db->db, 'port' => (int) $db->port, 'zip_entries' => true, 'contains_awarded_tender' => str_contains($xml, 'TN-2027-000006'), 'bytes' => filesize($target)], JSON_THROW_ON_ERROR);
    exit(0);
}
if ($mode === 'inspect_livewire_upload_times') {
    $virtualTime = strtotime((string) (getenv('PHASE5_WORKSHOP_TEST_NOW') ?: ''));
    $uploadRoot = $storageRoot.'/app/private/livewire-tmp';
    if ($virtualTime === false || $virtualTime < strtotime('2027-01-01T00:00:00Z') || ! is_dir($uploadRoot)) {
        throw new RuntimeException('ABORT: livewire upload timestamp alignment requires the guarded virtual clock and isolated temp directory.');
    }
    $aligned = [];
    foreach (glob($uploadRoot.'/*') ?: [] as $file) {
        if (! is_file($file)) { continue; }
        $aligned[] = ['name' => basename($file), 'bytes' => filesize($file), 'mtime' => filemtime($file)];
    }
    echo json_encode(['database' => $db->db, 'port' => (int) $db->port, 'aligned' => $aligned], JSON_THROW_ON_ERROR);
    exit(0);
}
if ($mode === 'tender_readback') {
    $id = (int) (getenv('PHASE5_WORKSHOP_TENDER_ID') ?: 0);
    $tender = $id ? DB::table('project_tenders')->find($id) : null;
    $technical = $tender?->technical_id ? DB::table('project_tender_technical')->find($tender->technical_id) : null;
    $financial = $tender?->financial_id ? DB::table('project_tender_financial')->find($tender->financial_id) : null;
    $submission = $tender?->submitted_id ? DB::table('project_tender_submissions')->find($tender->submitted_id) : null;
    $result = $tender ? DB::table('project_tender_results')->where('tender_id', $id)->orderByDesc('id')->first() : null;
    $plan = $tender ? DB::table('project_tender_handoff_plans')->where('tender_id', $id)->orderByDesc('id')->first() : null;
    $handoff = $tender ? DB::table('project_tender_handoffs')->where('tender_id', $id)->orderByDesc('id')->first() : null;
    $bidDocument = $financial ? DB::table('project_tender_bid_documents')->where('financial_id', $financial->id)->first() : null;
    $project = $handoff && $handoff->destination === 'project' ? DB::table('projects')->find($handoff->target_id) : null;
    echo json_encode([
        'database' => $db->db,
        'port' => (int) $db->port,
        'tender' => $tender ? ['id' => (int) $tender->id, 'number' => $tender->number, 'status' => $tender->status, 'version' => (int) $tender->version, 'title' => $tender->title, 'technical_id' => $tender->technical_id ? (int) $tender->technical_id : null, 'financial_id' => $tender->financial_id ? (int) $tender->financial_id : null, 'submitted_id' => $tender->submitted_id ? (int) $tender->submitted_id : null] : null,
        'technical' => $technical ? ['id' => (int) $technical->id, 'revision' => (int) $technical->revision, 'prepared_by' => (int) $technical->prepared_by, 'snapshot' => json_decode($technical->snapshot, true)] : null,
        'financial' => $financial ? ['id' => (int) $financial->id, 'revision' => (int) $financial->revision, 'prepared_by' => (int) $financial->prepared_by, 'net' => $financial->net, 'tax' => $financial->tax, 'total' => $financial->total, 'snapshot' => json_decode($financial->snapshot, true)] : null,
        'approvals' => $tender ? DB::table('project_tender_approvals')->where('tender_id', $id)->get(['kind', 'approved_by', 'reference'])->map(fn ($row) => (array) $row)->all() : [],
        'submission' => $submission ? ['id' => (int) $submission->id, 'method' => $submission->method, 'reference' => $submission->reference, 'financial_id' => (int) $submission->financial_id, 'technical_id' => (int) $submission->technical_id, 'document_id' => (string) $submission->document_id, 'evidence_id' => (int) $submission->evidence_id] : null,
        'bid_document' => $bidDocument ? ['financial_id' => (int) $bidDocument->financial_id, 'document_id' => (string) $bidDocument->document_id, 'issued_by' => (int) $bidDocument->issued_by] : null,
        'result' => $result ? ['id' => (int) $result->id, 'outcome' => $result->outcome, 'award_amount' => $result->award_amount, 'submission_id' => (int) $result->submission_id] : null,
        'plan' => $plan ? ['id' => (int) $plan->id, 'result_id' => (int) $plan->result_id, 'proposed_by' => (int) $plan->proposed_by, 'snapshot' => json_decode($plan->snapshot, true)] : null,
        'plan_approvals' => $plan ? DB::table('project_tender_handoff_approvals')->where('plan_id', $plan->id)->get(['approved_by', 'reference'])->map(fn ($row) => (array) $row)->all() : [],
        'handoff' => $handoff ? ['id' => (int) $handoff->id, 'destination' => $handoff->destination, 'target_id' => (int) $handoff->target_id, 'plan_id' => (int) $handoff->plan_id] : null,
        'project' => $project ? ['id' => (int) $project->id, 'code' => $project->code, 'name' => $project->name, 'customer_id' => (int) $project->customer_id, 'branch_id' => (int) $project->branch_id] : null,
        'documents' => $tender ? DB::table('project_tender_documents')->where('tender_id', $id)->get(['id', 'title', 'classification', 'sha256'])->map(fn ($row) => (array) $row)->all() : [],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit(0);
}
if ($mode === 'inspect_workshop_export') {
    $target = str_replace('\\', '/', (string) (getenv('PHASE5_WORKSHOP_EXPORT_PATH') ?: ''));
    if (! str_contains($target, '/.tmp/m09-browser-20261031/') || ! is_file($target) || ! class_exists(ZipArchive::class)) {
        throw new RuntimeException('ABORT: export inspection needs a private workspace XLSX and ZipArchive.');
    }
    $zip = new ZipArchive();
    if ($zip->open($target) !== true) { throw new RuntimeException('Unable to open exported workshop workbook.'); }
    $xml = ($zip->getFromName('xl/sharedStrings.xml') ?: '').($zip->getFromName('xl/worksheets/sheet1.xml') ?: '');
    $zip->close();
    echo json_encode(['database' => $db->db, 'port' => (int) $db->port, 'zip_entries' => true, 'contains_target_job' => str_contains($xml, 'WJ-2027-000001'), 'bytes' => filesize($target)], JSON_THROW_ON_ERROR);
    exit(0);
}
if ($mode === 'readback') {
    $intakeId = (int) (getenv('PHASE5_WORKSHOP_INTAKE_ID') ?: 0);
    $jobId = (int) (getenv('PHASE5_WORKSHOP_JOB_ID') ?: 0);
    $workshopCode = (string) (getenv('PHASE5_WORKSHOP_CODE') ?: '');
    $intake = $intakeId ? DB::table('workshop_intakes')->find($intakeId) : null;
    $job = $jobId ? DB::table('workshop_jobs')->find($jobId) : null;
    $workshop = $workshopCode !== '' ? DB::table('workshops')->where('code', $workshopCode)->first() : null;
    echo json_encode([
        'database' => $db->db,
        'port' => (int) $db->port,
        'workshop' => $workshop ? ['id' => (int) $workshop->id, 'code' => $workshop->code, 'version' => (int) $workshop->version, 'active' => (bool) $workshop->active] : null,
        'workshop_count' => $workshopCode !== '' ? DB::table('workshops')->where('code', 'like', $workshopCode.'%')->count() : null,
        'intake' => $intake ? ['id' => (int) $intake->id, 'number' => $intake->number, 'status' => $intake->status, 'version' => (int) $intake->version, 'accessories' => $intake->accessories, 'customer_owned' => (bool) $intake->customer_owned, 'branch_id' => (int) $intake->branch_id] : null,
        'job' => $job ? ['id' => (int) $job->id, 'number' => $job->number, 'status' => $job->status, 'version' => (int) $job->version, 'diagnosis' => $job->diagnosis, 'planned_work' => $job->planned_work, 'required_tests' => json_decode($job->required_tests, true), 'technician_id' => $job->technician_id ? (int) $job->technician_id : null] : null,
        'estimates' => $job ? DB::table('workshop_estimates')->where('job_id', $jobId)->orderBy('revision')->get(['id', 'revision', 'billable_amount', 'tax_amount', 'proposed_by'])->map(fn ($row) => (array) $row)->all() : [],
        'quotes' => $job ? DB::table('workshop_quotes')->where('job_id', $jobId)->get(['id', 'estimate_id', 'document_id', 'issued_by'])->map(fn ($row) => (array) $row)->all() : [],
        'invoice' => $job ? DB::table('rp_customer_invoices')->where('workshop_job_id', $jobId)->first(['id', 'status', 'net_amount', 'tax_amount', 'total_amount', 'official_document_id', 'official_number']) : null,
        'approvals' => $job ? DB::table('workshop_estimate_approvals as a')->join('workshop_estimates as e', 'e.id', '=', 'a.estimate_id')->where('e.job_id', $jobId)->get(['a.approved_by', 'a.customer_accepted'])->map(fn ($row) => (array) $row)->all() : [],
        'quote_approvals' => $job ? DB::table('workshop_quote_approvals as a')->join('workshop_estimates as e', 'e.id', '=', 'a.estimate_id')->where('e.job_id', $jobId)->get(['a.approved_by', 'a.reference'])->map(fn ($row) => (array) $row)->all() : [],
        'parts' => $job ? [
            'requirements' => DB::table('workshop_part_requirements')->where('job_id', $jobId)->get(['id', 'product_id', 'warehouse_id', 'quantity'])->map(fn ($row) => (array) $row)->all(),
            'reservations' => DB::table('workshop_part_reservations as r')->join('workshop_part_requirements as q', 'q.id', '=', 'r.requirement_id')->where('q.job_id', $jobId)->get(['r.reservation_id', 'r.requirement_id'])->map(fn ($row) => (array) $row)->all(),
            'issues' => DB::table('workshop_part_issues as i')->join('workshop_part_requirements as q', 'q.id', '=', 'i.requirement_id')->where('q.job_id', $jobId)->get(['i.id', 'i.quantity', 'i.movement_id', 'i.reservation_id'])->map(fn ($row) => (array) $row)->all(),
            'returns' => DB::table('workshop_part_returns as r')->join('workshop_part_issues as i', 'i.id', '=', 'r.issue_id')->join('workshop_part_requirements as q', 'q.id', '=', 'i.requirement_id')->where('q.job_id', $jobId)->get(['r.issue_id', 'r.quantity', 'r.movement_id'])->map(fn ($row) => (array) $row)->all(),
            'usage' => DB::table('workshop_part_usage as u')->join('workshop_part_requirements as q', 'q.id', '=', 'u.requirement_id')->where('q.job_id', $jobId)->get(['u.work_record_id', 'u.requirement_id', 'u.quantity'])->map(fn ($row) => (array) $row)->all(),
        ] : [],
        'work_records' => $job ? DB::table('workshop_work_records')->where('job_id', $jobId)->count() : 0,
        'work_record_rows' => $job ? DB::table('workshop_work_records')->where('job_id', $jobId)->get(['id', 'technician_id', 'started_at', 'ended_at'])->map(fn ($row) => (array) $row)->all() : [],
        'tests' => $job ? DB::table('workshop_tests')->where('job_id', $jobId)->orderBy('id')->get(['name', 'required', 'outcome', 'performed_by'])->map(fn ($row) => (array) $row)->all() : [],
        'verification' => $job ? DB::table('workshop_verifications')->where('job_id', $jobId)->first(['verified_by', 'document_id']) : null,
        'delivery' => $job ? DB::table('workshop_deliveries')->where('job_id', $jobId)->first(['recipient', 'accessories', 'unrepaired', 'delivered_by']) : null,
        'documents' => $intake ? DB::table('workshop_documents')->where('intake_id', $intakeId)->get(['id', 'original_name', 'mime', 'sha256'])->map(fn ($row) => (array) $row)->all() : [],
        'custody_events' => $intake ? DB::table('workshop_custody_events')->where('intake_id', $intakeId)->orderBy('id')->pluck('status')->all() : [],
        'inventory' => [
            'movements' => DB::table('inventory_movements')->count(),
            'fifo_layers' => DB::table('inventory_fifo_layers')->count(),
            'balances' => DB::table('inventory_balances')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'test_stock_on_hand' => $job ? DB::table('inventory_balances')->where('warehouse_id', 1)->where('product_id', 1)->value('on_hand') : null,
            'test_stock_reserved' => $job ? DB::table('inventory_balances')->where('warehouse_id', 1)->where('product_id', 1)->value('reserved') : null,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit(0);
}

if ($mode !== 'prepare') {
    throw new RuntimeException('Unsupported M09 browser fixture mode.');
}
$password = (string) (getenv('PHASE5_WORKSHOP_PASSWORD') ?: '');
$tag = (string) (getenv('PHASE5_WORKSHOP_TAG') ?: '');
if (strlen($password) < 24 || ! preg_match('/^[A-F0-9]{10}$/', $tag)) {
    throw new RuntimeException('M09 browser fixture tag/password environment is missing.');
}

$company = Company::query()->firstOrFail();
$branch = Branch::query()->where('id', 1)->where('company_id', $company->id)->where('active', true)->firstOrFail();
$customer = Customer::query()->where('id', 1)->where('status', 'active')->firstOrFail();
$warehouseId = (int) DB::table('inventory_warehouses')->where('id', 1)->where('branch_id', $branch->id)->where('active', true)->value('id');
$stockProductId = (int) DB::table('inventory_balances')->where('warehouse_id', $warehouseId)->where('product_id', 1)->whereRaw('on_hand - reserved >= 2')->value('product_id');
if (! $warehouseId || ! $stockProductId) { throw new RuntimeException('ABORT: activated isolated clone lacks verified source stock.'); }
$actors = [];
foreach ([
    'dispatcher' => ['M09.view', 'M09.manage', 'M09.intake_manage', 'M09.custody', 'M09.job_manage', 'M09.assign', 'M09.estimate_manage', 'M09.quote_issue', 'M09.customer_acceptance', 'M09.billing_request', 'M09.deliver', 'M09.document_manage', 'M09.document_view', 'M09.cost_view', 'M09.reports_view', 'M09.parts_request', 'M09.parts_issue', 'M09.parts_return', 'M06.reservation_manage', 'M06.balance_view', 'M06.issue_post', 'M06.movement_view', 'M06.correction_execute', 'M06.isolation_manage', 'M06.isolation_release', 'M11.journal_post', 'M11.source_post', 'M12.customer_invoice_view', 'M12.customer_invoice_manage', 'M12.customer_invoice_post'],
    'technician' => ['M09.view', 'M09.execute', 'M09.labor_record', 'M09.parts_request', 'M09.parts_issue', 'M09.parts_return'],
    'supervisor' => ['M09.view', 'M09.estimate_approve', 'M09.nonbillable_approve', 'M09.verify', 'M09.document_view', 'M09.cost_view', 'M09.rate_approve'],
    'outsider' => [],
] as $role => $permissions) {
    $user = User::factory()->create([
        'name' => 'DEMO-EG M09 '.$role.' '.$tag,
        'email' => 'workshop-'.$role.'-'.$tag.'@example.test',
        'password' => Hash::make($password),
        'active' => true,
        'email_verified_at' => now(),
    ]);
    $actors[$role] = ['id' => (int) $user->id, 'email' => $user->email];
    if ($role !== 'outsider') {
        DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        if ($role === 'dispatcher' || $role === 'technician') { DB::table('user_warehouse_scopes')->insert(['user_id' => $user->id, 'warehouse_id' => $warehouseId, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]); }
        foreach ($permissions as $permission) {
            DB::table('access_grants')->insert(['user_id' => $user->id, 'permission_code' => $permission, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
foreach ([['dispatcher', 'M03.customer_view'], ['dispatcher', 'M02.product_view'], ['dispatcher', 'M11.tax_calculate']] as [$role, $permission]) {
    DB::table('access_grants')->insert(['user_id' => $actors[$role]['id'], 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
}
foreach (['dispatcher', 'technician', 'supervisor'] as $role) {
    DB::table('user_branch_scopes')->insertOrIgnore(['user_id' => $actors[$role]['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
}

$service = Product::query()->where('id', 240)->where('kind', 'service')->where('active', true)->firstOrFail();

$workshopId = null;

echo json_encode([
    'database' => $db->db,
    'port' => (int) $db->port,
    'tag' => $tag,
    'branch_id' => (int) $branch->id,
    'customer_id' => (int) $customer->id,
    'customer_code' => $customer->code,
    'service_product_id' => (int) $service->id,
    'warehouse_id' => $warehouseId,
    'stock_product_id' => $stockProductId,
    'warehouse_id' => $warehouseId,
    'stock_product_id' => $stockProductId,
    'workshop_id' => $workshopId,
    'workshop_code' => 'DEMO-EG-M09-WORKSHOP-'.$tag,
    'actors' => $actors,
    'inventory_before' => ['movements' => DB::table('inventory_movements')->count(), 'fifo_layers' => DB::table('inventory_fifo_layers')->count(), 'balances' => DB::table('inventory_balances')->count(), 'on_hand' => DB::table('inventory_balances')->where('warehouse_id', $warehouseId)->where('product_id', $stockProductId)->value('on_hand'), 'reserved' => DB::table('inventory_balances')->where('warehouse_id', $warehouseId)->where('product_id', $stockProductId)->value('reserved')],
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
