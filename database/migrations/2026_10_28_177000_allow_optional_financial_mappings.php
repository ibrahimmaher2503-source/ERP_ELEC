<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION accounting_guard_mapping() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF EXISTS(SELECT 1 FROM accounting_activation WHERE id=1 AND activated_at IS NOT NULL) THEN
  IF TG_OP='INSERT' AND NEW.purpose IN ('customer_advance') THEN RETURN NEW; END IF;
  RAISE EXCEPTION 'Activated account mappings frozen';
 END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Operational optional mappings require forward migration.');
    }
};
