<?php

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Organization\Application\Contracts\QuotationDelegation;
use Illuminate\Support\Facades\DB;

final class QuotationDelegationService implements QuotationDelegation
{
    public function authorizeApproval(int $actorId, int $requesterId): void
    {
        app(OrganizationApi::class)->authorize(new AuthorizeRequest($actorId, 'M04.quotation_approve'));
        if ($actorId !== $requesterId) {
            return;
        }
        $roles = DB::table('user_roles')->where('user_id', $actorId)->pluck('role_id');
        $allowed = DB::table('quotation_self_approval_policies')->where('enabled', true)->where(function ($q) use ($actorId, $roles) {
            $q->where(fn ($q) => $q->where('target_type', 'user')->where('target_id', $actorId))->orWhere(fn ($q) => $q->where('target_type', 'role')->whereIn('target_id', $roles));
        })->exists();
        abort_unless($allowed, 403, 'الموافقة الذاتية معطلة لهذا المستخدم وأدواره.');
    }
}
