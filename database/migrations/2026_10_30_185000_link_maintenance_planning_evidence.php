<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE project_tasks ADD CONSTRAINT maintenance_task_project_identity UNIQUE(project_id,id);
ALTER TABLE project_milestones ADD CONSTRAINT maintenance_milestone_project_identity UNIQUE(project_id,id);
ALTER TABLE maintenance_requests ADD COLUMN project_task_id bigint, ADD COLUMN project_milestone_id bigint;
ALTER TABLE maintenance_work_orders ADD COLUMN project_task_id bigint, ADD COLUMN project_milestone_id bigint;
ALTER TABLE maintenance_requests ADD CONSTRAINT maintenance_request_task FOREIGN KEY(project_id,project_task_id) REFERENCES project_tasks(project_id,id), ADD CONSTRAINT maintenance_request_milestone FOREIGN KEY(project_id,project_milestone_id) REFERENCES project_milestones(project_id,id), ADD CONSTRAINT maintenance_request_planning CHECK(project_id IS NOT NULL OR (project_task_id IS NULL AND project_milestone_id IS NULL));
ALTER TABLE maintenance_work_orders ADD CONSTRAINT maintenance_work_task FOREIGN KEY(project_id,project_task_id) REFERENCES project_tasks(project_id,id), ADD CONSTRAINT maintenance_work_milestone FOREIGN KEY(project_id,project_milestone_id) REFERENCES project_milestones(project_id,id), ADD CONSTRAINT maintenance_work_planning CHECK(project_id IS NOT NULL OR (project_task_id IS NULL AND project_milestone_id IS NULL));
ALTER TABLE maintenance_visits ADD COLUMN template_id bigint REFERENCES maintenance_checklist_templates;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Maintenance planning links require forward migrations.');
    }
};
