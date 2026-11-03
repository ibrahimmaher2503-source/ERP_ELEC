<?php

namespace Tests\Unit;

use App\Modules\Organization\Application\PermissionCatalog;
use PHPUnit\Framework\TestCase;

class ModuleIdentityRegistryTest extends TestCase
{
    public function test_projects_keeps_the_m07_runtime_identity_and_permission_ownership(): void
    {
        $modules = require dirname(__DIR__, 2).'/config/modules.php';

        self::assertSame([
            'id' => 'M07',
            'permission_prefix' => 'M07',
            'implemented' => true,
            'capabilities' => ['M07.Tenders' => ['implemented' => true, 'permission_prefix' => 'M07.tender_']],
        ], $modules['Projects']);
        self::assertSame([
            'M07.project_view',
            'M07.project_create',
            'M07.project_update',
            'M07.project_manage',
            'M07.project_activate',
            'M07.project_close',
            'M07.project_reopen',
            'M07.budget_approve',
            'M07.financial_view',
            'M07.material_manage',
            'M07.material_issue',
            'M07.document_view',
            'M07.document_approve',
            'M07.budget_view',
            'M07.budget_manage',
            'M07.document_manage',
            'M07.report_view',
            'M07.export',
        ], array_values(array_filter(
            array_keys(PermissionCatalog::DEFINITIONS),
            static fn (string $permission): bool => str_starts_with($permission, 'M07.') && ! str_starts_with($permission, 'M07.tender_'),
        )));
        self::assertSame([
            'M07.tender_view',
            'M07.tender_manage',
            'M07.tender_technical_manage',
            'M07.tender_technical_approve',
            'M07.tender_financial_manage',
            'M07.tender_financial_approve',
            'M07.tender_financial_view',
            'M07.tender_submit',
            'M07.tender_award',
            'M07.tender_handoff',
            'M07.tender_document_view',
            'M07.tender_report',
            'M07.tender_export',
        ], array_values(array_filter(
            array_keys(PermissionCatalog::DEFINITIONS),
            static fn (string $permission): bool => str_starts_with($permission, 'M07.tender_'),
        )));
    }

    public function test_projects_feature_gate_is_independent_from_the_v2_release_gate(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Projects/Application/ProjectAccess.php');

        self::assertIsString($source);
        self::assertStringContainsString("config('release.projects_enabled')", $source);
        self::assertStringNotContainsString("config('release.v2_enabled')", $source);
    }

    public function test_delivery_returns_m07_is_historical_only_and_owns_no_runtime_permissions(): void
    {
        $modules = require dirname(__DIR__, 2).'/config/modules.php';

        self::assertSame([
            'id' => null,
            'historical_id' => 'M07',
            'permission_prefix' => null,
            'implemented' => false,
        ], $modules['DeliveryReturns']);
        self::assertArrayNotHasKey('M07.return_view', PermissionCatalog::DEFINITIONS);
        self::assertArrayNotHasKey('M07.return_create', PermissionCatalog::DEFINITIONS);
    }
}
