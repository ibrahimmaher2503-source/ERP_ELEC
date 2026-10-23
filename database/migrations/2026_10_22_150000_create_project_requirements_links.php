<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE project_source_links(id bigserial PRIMARY KEY,project_id bigint NOT NULL REFERENCES projects(id),source_type varchar(30) NOT NULL CHECK(source_type IN ('quotation','sales_order')),source_id bigint NOT NULL,created_by bigint NOT NULL REFERENCES users(id),created_at timestamptz NOT NULL,UNIQUE(source_type,source_id));
CREATE TABLE project_requirements(id bigserial PRIMARY KEY,project_id bigint NOT NULL REFERENCES projects(id),product_id bigint NOT NULL REFERENCES products(id),unit_id bigint NOT NULL REFERENCES units_of_measure(id),sku varchar(100) NOT NULL,product_name varchar(200) NOT NULL,quantity numeric(24,8) NOT NULL CHECK(quantity>0),notes text NOT NULL DEFAULT '',version integer NOT NULL DEFAULT 1,UNIQUE(project_id,product_id));
SQL);
    }

    public function down(): void {}
};
