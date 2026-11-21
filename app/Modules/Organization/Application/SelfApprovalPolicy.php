<?php

namespace App\Modules\Organization\Application;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class SelfApprovalPolicy
{
    /** @var array<string, array{0: string, 1: string}> */
    public const SCOPES = [
        'quotations' => ['عروض الأسعار', 'اعتماد استثناءات عروض الأسعار (متوافق مع الإعداد السابق)'],
        'sales_orders' => ['أوامر البيع', 'اعتماد أوامر البيع وقرارات الاستثناءات التجارية'],
        'workshops' => ['الورش والإصلاحات', 'اعتماد تقديرات الإصلاح، التعاقدي وغير المدفوع، معدلات التكلفة، التحقق الفني، والبدائل'],
        'purchasing' => ['المشتريات وأسعار البيع', 'اعتماد طلبات وأوامر الشراء، الترسية، ترحيل الاستلام، وأسعار البيع المعتمدة'],
        'inventory' => ['المخزون', 'اعتماد مستندات الصرف والتسويات والجرد والتحويلات'],
        'accounting' => ['المحاسبة والافتتاح المالي', 'اعتماد القيود اليدوية وحزمة الأرصدة الافتتاحية وترحيل المصادر'],
        'treasury_ar_ap' => ['الخزينة والذمم', 'اعتماد مدفوعات الموردين، إشعارات الدائن، مطابقة كشف البنك، وتصحيحات الخزينة'],
        'operations_other' => ['الصيانة والعقود والمشاريع والمناقصات والحضور', 'اعتماد مقترحات الصيانة، عقود الخدمة وفوترتها، ميزانيات ووثائق المشاريع، المناقصات، وتعديلات الحضور'],
    ];

    public function allows(int $actorId, string $scope): bool
    {
        if (! isset(self::SCOPES[$scope])) {
            return false;
        }
        $actor = app(PermissionAccess::class)->actor($actorId);
        if (! $actor?->active || ! $actor->email_verified_at) {
            return false;
        }
        $roleIds = DB::table('user_roles')->where('user_id', $actorId)->pluck('role_id')->all();

        $allowed = DB::table('organization_self_approval_policies')
            ->where('scope', $scope)
            ->where('enabled', true)
            ->where(function ($q) use ($actorId, $roleIds): void {
                $q->where(fn ($q) => $q->where('target_type', 'user')->where('target_id', $actorId));
                if ($roleIds !== []) {
                    $q->orWhere(fn ($q) => $q->where('target_type', 'role')->whereIn('target_id', $roleIds));
                }
            })
            ->exists();

        if ($allowed) {
            return true;
        }

        if ($scope === 'quotations') {
            return DB::table('quotation_self_approval_policies')
                ->where('enabled', true)
                ->where(function ($q) use ($actorId, $roleIds): void {
                    $q->where(fn ($q) => $q->where('target_type', 'user')->where('target_id', $actorId));
                    if ($roleIds !== []) {
                        $q->orWhere(fn ($q) => $q->where('target_type', 'role')->whereIn('target_id', $roleIds));
                    }
                })
                ->exists();
        }

        return false;
    }

    /** @return array<string, array{enabled: bool, version: ?int}> */
    public function forTarget(string $type, int $targetId): array
    {
        $rows = DB::table('organization_self_approval_policies')
            ->where('target_type', $type)
            ->where('target_id', $targetId)
            ->get()
            ->keyBy('scope');

        $legacyQuotation = DB::table('quotation_self_approval_policies')
            ->where('target_type', $type)
            ->where('target_id', $targetId)
            ->first();

        $result = [];
        foreach (array_keys(self::SCOPES) as $scope) {
            $row = $rows->get($scope);
            if ($row) {
                $result[$scope] = ['enabled' => (bool) $row->enabled, 'version' => (int) $row->version];
            } elseif ($scope === 'quotations' && $legacyQuotation) {
                $result[$scope] = ['enabled' => (bool) $legacyQuotation->enabled, 'version' => (int) $legacyQuotation->version];
            } else {
                $result[$scope] = ['enabled' => false, 'version' => null];
            }
        }

        return $result;
    }

    public function save(User $actor, string $type, int $targetId, array $desiredScopes, array $expectedVersions): void
    {
        app(RbacGuard::class)->authorize($actor);
        Validator::make(
            ['type' => $type, 'target' => $targetId],
            ['type' => ['required', Rule::in(['user', 'role'])], 'target' => ['required', 'integer', 'min:1']]
        )->validate();
        foreach (array_keys($desiredScopes) as $scope) {
            abort_unless(isset(self::SCOPES[$scope]), 422);
        }

        DB::transaction(function () use ($actor, $type, $targetId, $desiredScopes, $expectedVersions): void {
            $guard = app(RbacGuard::class);
            $guard->lock();
            $guard->authorize($actor);
            abort_unless(DB::table($type === 'user' ? 'users' : 'roles')->where('id', $targetId)->exists(), 404);

            $rows = DB::table('organization_self_approval_policies')
                ->where('target_type', $type)
                ->where('target_id', $targetId)
                ->lockForUpdate()
                ->get()
                ->keyBy('scope');

            $legacy = DB::table('quotation_self_approval_policies')
                ->where('target_type', $type)
                ->where('target_id', $targetId)
                ->lockForUpdate()
                ->first();

            $before = [];
            $after = [];
            $now = now();

            foreach (array_keys(self::SCOPES) as $scope) {
                $existing = $rows->get($scope);
                $currentVer = $existing ? (int) $existing->version : ($scope === 'quotations' && $legacy ? (int) $legacy->version : null);
                $expectedVer = array_key_exists($scope, $expectedVersions) && $expectedVersions[$scope] !== null
                    ? (int) $expectedVersions[$scope]
                    : null;

                if ($currentVer !== $expectedVer) {
                    throw ValidationException::withMessages(['selfApproval' => 'تغير إعداد الاعتماد الذاتي من جلسة أخرى. أعد تحميل الصفحة.']);
                }

                $oldEnabled = $existing ? (bool) $existing->enabled : ($scope === 'quotations' ? (bool) ($legacy?->enabled ?? false) : false);
                $newEnabled = array_key_exists($scope, $desiredScopes) ? (bool) $desiredScopes[$scope] : $oldEnabled;
                $before[$scope] = $oldEnabled;
                $after[$scope] = $newEnabled;

                if ($existing || $newEnabled !== $oldEnabled) {
                    $nextVer = ($existing?->version ?? 0) + 1;
                    $payload = [
                        'target_type' => $type,
                        'target_id' => $targetId,
                        'scope' => $scope,
                        'enabled' => $newEnabled,
                        'version' => $existing && $newEnabled === $oldEnabled ? (int) $existing->version : $nextVer,
                        'updated_by' => $actor->id,
                        'updated_at' => $now,
                    ];
                    if ($existing) {
                        if ($newEnabled !== $oldEnabled) {
                            DB::table('organization_self_approval_policies')->where('id', $existing->id)->update($payload);
                        }
                    } else {
                        DB::table('organization_self_approval_policies')->insert($payload + ['created_at' => $now]);
                    }
                }

                if ($scope === 'quotations' && ($legacy || $newEnabled !== $oldEnabled)) {
                    $legacyNextVer = ($legacy?->version ?? 0) + 1;
                    if ($legacy) {
                        if ($newEnabled !== $oldEnabled) {
                            DB::table('quotation_self_approval_policies')->where('id', $legacy->id)->update([
                                'enabled' => $newEnabled,
                                'version' => $legacyNextVer,
                                'updated_at' => $now,
                            ]);
                        }
                    } else {
                        DB::table('quotation_self_approval_policies')->insert([
                            'target_type' => $type,
                            'target_id' => $targetId,
                            'enabled' => $newEnabled,
                            'version' => 1,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }

            $guard->audit(
                $actor,
                $type === 'user' ? $targetId : $actor->id,
                'self_approval.policy_changed',
                ['target_type' => $type, 'target_id' => $targetId, 'scopes' => $before],
                ['target_type' => $type, 'target_id' => $targetId, 'role_id' => $type === 'role' ? $targetId : null, 'scopes' => $after]
            );
        });
    }
}
