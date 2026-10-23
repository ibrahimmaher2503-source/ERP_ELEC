<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_self_approval_policies', function (Blueprint $t) {
            $t->id();
            $t->string('target_type', 10);
            $t->unsignedBigInteger('target_id');
            $t->boolean('enabled')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->unique(['target_type', 'target_id']);
        });
        DB::statement("ALTER TABLE quotation_self_approval_policies ADD CONSTRAINT quotation_self_approval_type CHECK(target_type IN ('user','role'))");
        Schema::create('sales_quotation_approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('quotation_id')->constrained('sales_quotations')->restrictOnDelete();
            $t->unsignedBigInteger('requested_by');
            $t->unsignedInteger('target_version');
            $t->unsignedInteger('policy_version');
            $t->string('content_hash', 64);
            $t->jsonb('exceptions');
            $t->jsonb('quotation_snapshot');
            $t->string('status', 12)->default('pending');
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->text('decision_reason')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->timestampsTz();
        });
        DB::statement("CREATE UNIQUE INDEX quotation_one_pending_approval ON sales_quotation_approvals(quotation_id) WHERE status='pending'");
        DB::statement("ALTER TABLE sales_quotation_approvals ADD CONSTRAINT quotation_approval_status CHECK(status IN ('pending','approved','rejected','withdrawn'))");
        DB::unprepared("CREATE OR REPLACE FUNCTION guard_quotation_approval_history() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF TG_OP='DELETE' OR OLD.status <> 'pending' THEN RAISE EXCEPTION 'Quotation approval history is immutable'; END IF; IF NEW.quotation_id<>OLD.quotation_id OR NEW.requested_by<>OLD.requested_by OR NEW.target_version<>OLD.target_version OR NEW.policy_version<>OLD.policy_version OR NEW.content_hash<>OLD.content_hash OR NEW.exceptions<>OLD.exceptions OR NEW.quotation_snapshot<>OLD.quotation_snapshot THEN RAISE EXCEPTION 'Approval snapshot is immutable'; END IF; RETURN NEW; END $$; CREATE TRIGGER quotation_approval_history BEFORE UPDATE OR DELETE ON sales_quotation_approvals FOR EACH ROW EXECUTE FUNCTION guard_quotation_approval_history();");
    }

    public function down(): void
    {
        throw new RuntimeException('Approval evidence is append-only.');
    }
};
