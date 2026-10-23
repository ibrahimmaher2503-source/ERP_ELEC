<?php

namespace App\Modules\Projects\Presentation;

use App\Modules\Projects\Application\ProjectAccess;
use App\Modules\Projects\Domain\Project;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class ProjectClosurePage extends Component
{
    #[Locked]
    public int $projectId;

    public function mount(int $project): void
    {
        $this->projectId = $project;
    }

    public function render()
    {
        $p = Project::findOrFail($this->projectId);
        $a = app(ProjectAccess::class);
        $a->authorize(auth()->id(), $p);
        $progress = $a->progress($p);
        $complete = $progress['eligible'] > 0;
        foreach ($progress['phases']->where('cancelled', false) as $phase) {
            $complete = $complete && $phase->total > 0 && $phase->total === $phase->complete;
        }
        $pending = DB::table('project_changes')->where('project_id', $p->id)->where('status', 'pending')->count();
        $docs = DB::table('project_documents')->where('project_id', $p->id)->where('required', true)->get();
        $docsOk = true;
        foreach ($docs as $doc) {
            if (DB::table('project_document_revisions')->where('document_id', $doc->id)->orderByDesc('revision')->value('status') !== 'approved') {
                $docsOk = false;
            }
        }
        $checklist = [['name' => 'اكتمال المراحل والمهام', 'state' => $complete ? 'مستوفى' : 'غير مستوفى', 'evidence' => $progress['percent'].'% من متوسط المراحل'], ['name' => 'إكمال العمل التشغيلي', 'state' => $p->status === 'completed' ? 'مستوفى' : 'غير مستوفى', 'evidence' => 'حالة المشروع: '.$p->status], ['name' => 'المستندات المطلوبة', 'state' => $docsOk ? 'مستوفى' : 'غير مستوفى', 'evidence' => 'عدد المستندات المحددة كمطلوبة: '.$docs->count()], ['name' => 'حسم طلبات التغيير', 'state' => $pending ? 'غير مستوفى' : 'مستوفى', 'evidence' => 'طلبات معلقة: '.$pending], ['name' => 'تسوية حجوزات وصرف المخزون', 'state' => 'تكامل غير متاح', 'evidence' => 'لا يوجد عقد تسوية مخزون خاص بالمشروع من M06'], ['name' => 'تسوية التزامات المشتريات والعمليات', 'state' => 'تكامل غير متاح', 'evidence' => 'تحتاج شهادة تسوية مصدرية؛ الارتباط بمستند لا يثبت تسويته'], ['name' => 'تسجيل ومطابقة التكاليف المعترف بها', 'state' => 'تكامل غير متاح', 'evidence' => 'عقد التكاليف والمطابقة المالية غير منفذ'], ['name' => 'اعتماد مدير المشروع', 'state' => 'بانتظار الشروط', 'evidence' => 'لا يسمح بالإغلاق قبل اكتمال الأدلة السابقة']];

        return view('projects.closure', ['project' => $p, 'checklist' => $checklist])->layout('layouts::app', ['title' => 'متطلبات إغلاق المشروع']);
    }
}
