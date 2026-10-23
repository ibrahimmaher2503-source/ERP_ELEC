<?php
namespace App\Modules\Accounting\Application;

use App\Modules\System\Application\RecordAudit;
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;

final class AccountingActions
{
    public function lock(): void
    {
        DB::select('select pg_advisory_xact_lock(10401)');
        DB::select('select pg_advisory_xact_lock(11001)');
    }
    private function fail(string $message): never { throw ValidationException::withMessages(['form'=>$message]); }
    private function audit(int $actor, string $type, int $id, string $action, ?array $before, array $after): void
    { app(RecordAudit::class)->execute($actor,$type,$id,$action,$before,$after,'succeeded','Accounting'); }

    public function account(int $actor, ?int $id, int $version, array $data): int
    {
        $d=Validator::make($data,['code'=>'required|string|max:40|regex:/^[A-Za-z0-9_-]+$/D','name'=>'required|string|max:200|not_regex:/\x00/','kind'=>'required|in:asset,liability,equity,income,expense','parent_id'=>'nullable|integer|min:1','postable'=>'required|boolean','active'=>'required|boolean'])->validate();
        return DB::transaction(function() use($actor,$id,$version,$d) {
            $this->lock(); app(AccountingAccess::class)->authorize($actor,'account_manage');
            $old=$id?DB::table('accounting_accounts')->find($id):null; if($id&&!$old) abort(404);
            if($old&&$old->version!==$version) $this->fail('نسخة الحساب قديمة، أعد فتحه.');
            if(DB::table('accounting_accounts')->where('code',$d['code'])->when($id,fn($q)=>$q->where('id','<>',$id))->exists()) $this->fail('كود الحساب مستخدم.');
            if($old&&($old->code!==$d['code']||$old->kind!==$d['kind']||(string)$old->parent_id!==(string)$d['parent_id'])&&DB::table('accounting_journal_lines')->where('account_id',$id)->exists()) $this->fail('هوية الحساب وتصنيفه ثابتان بعد استخدامه.');
            if($d['parent_id']) {
                $parent=DB::table('accounting_accounts')->find($d['parent_id']);
                if(!$parent||!$parent->active||$parent->postable||$parent->kind!==$d['kind']) $this->fail('الحساب الأب يجب أن يكون تجميعيًا نشطًا من نفس النوع.');
                $seen=[]; while($parent) { if($parent->id===$id||isset($seen[$parent->id])) $this->fail('دورة غير مسموحة في شجرة الحسابات.'); $seen[$parent->id]=true; $parent=$parent->parent_id?DB::table('accounting_accounts')->find($parent->parent_id):null; }
            }
            if($id&&$d['postable']&&DB::table('accounting_accounts')->where('parent_id',$id)->exists()) $this->fail('الحساب الذي له أبناء لا يقبل الترحيل.');
            if($old&&!$d['postable']&&DB::table('accounting_journal_lines')->where('account_id',$id)->exists()) $this->fail('الحساب مستخدم في قيود.');
            $values=$d+['version'=>$old?$version+1:1,'updated_at'=>now()];
            if($old) DB::table('accounting_accounts')->where('id',$id)->update($values); else $id=DB::table('accounting_accounts')->insertGetId($values+['created_at'=>now()]);
            $this->audit($actor,'account',$id,'account.saved',$old?(array)$old:null,$values); return $id;
        });
    }

    public function period(int $actor, array $data): int
    {
        $d=Validator::make($data,['name'=>'required|string|max:100|not_regex:/\x00/','starts_on'=>'required|date_format:Y-m-d','ends_on'=>'required|date_format:Y-m-d|after_or_equal:starts_on'])->validate();
        return DB::transaction(function() use($actor,$d) {
            $this->lock(); app(AccountingAccess::class)->authorize($actor,'period_open');
            if(DB::table('accounting_periods')->where('starts_on','<=',$d['ends_on'])->where('ends_on','>=',$d['starts_on'])->exists()) $this->fail('توجد فترة مالية متداخلة.');
            $id=DB::table('accounting_periods')->insertGetId($d+['created_at'=>now(),'updated_at'=>now()]);
            $this->audit($actor,'period',$id,'period.opened',null,$d);return $id;
        });
    }

    public function reopen(int $actor,int $id,int $version,string $reason): void
    {
        Validator::make(['reason'=>$reason],['reason'=>'required|string|min:5|max:2000|not_regex:/\x00/'])->validate();
        DB::transaction(function()use($actor,$id,$version,$reason){
            $this->lock();app(AccountingAccess::class)->authorize($actor,'period_reopen');
            $p=DB::table('accounting_periods')->lockForUpdate()->find($id);abort_unless($p,404);
            if($p->version!==$version||$p->status!=='closed')$this->fail('الفترة ليست مغلقة أو نسختها قديمة.');
            DB::table('accounting_periods')->where('id',$id)->update(['status'=>'open','version'=>$version+1,'updated_at'=>now()]);
            $this->audit($actor,'period',$id,'period.reopened',(array)$p,['status'=>'open','reason'=>$reason]);
        });
    }

    public function journal(int $actor,?int $id,int $version,string $key,array $data): int
    {
        $d=Validator::make($data,[
            'branch_id'=>'required|integer|min:1','journal_date'=>'required|date_format:Y-m-d','description'=>'required|string|max:3000|not_regex:/\x00/',
            'lines'=>'required|array|min:2|max:100','lines.*.account_id'=>'required|integer|min:1',
            'lines.*.debit'=>['required','string','regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,2})?$/D'],
            'lines.*.credit'=>['required','string','regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,2})?$/D'],
            'lines.*.description'=>'nullable|string|max:500|not_regex:/\x00/'
        ])->validate();Validator::make(['key'=>$key],['key'=>'required|uuid'])->validate();
        return DB::transaction(function()use($actor,$id,$version,$key,$d){
            $this->lock();$a=app(AccountingAccess::class);$branch=(int)$d['branch_id'];$a->authorize($actor,'journal_create',$branch);
            $old=$id?DB::table('accounting_journals')->lockForUpdate()->find($id):DB::table('accounting_journals')->where('creation_key',$key)->first();
            if($id&&!$old)abort(404);
            if($old) {
                $a->authorize($actor,'journal_create',$old->branch_id);
                if($old->created_by!==$actor||$old->branch_id!==$branch||$old->creation_key!==$key)$this->fail('هوية القيد أو منشئه لا يمكن تغييرهما.');
                if(!$id) $this->fail('هذا الطلب محفوظ بالفعل. افتح القيد من القائمة.');
                if($old->status!=='draft'||$old->version!==$version)$this->fail('القيد ثابت بعد الإرسال أو نسخته قديمة.');
            }
            $lines=[];
            foreach($d['lines'] as $line){
                $account=DB::table('accounting_accounts')->find($line['account_id']);
                if(!$account||!$account->active||!$account->postable)$this->fail('اختر حسابًا نشطًا يقبل الترحيل.');
                $dr=bcadd($line['debit'],'0',2);$cr=bcadd($line['credit'],'0',2);
                if(!((bccomp($dr,'0',2)>0&&bccomp($cr,'0',2)===0)||(bccomp($cr,'0',2)>0&&bccomp($dr,'0',2)===0)))$this->fail('كل بند يجب أن يكون مدينًا أو دائنًا بمبلغ موجب.');
                $lines[]=['account_id'=>$account->id,'account_code'=>$account->code,'account_name'=>$account->name,'debit'=>$dr,'credit'=>$cr,'description'=>$line['description']??''];
            }
            $header=['journal_date'=>$d['journal_date'],'description'=>$d['description'],'version'=>$old?$version+1:1,'updated_at'=>now()];
            if($old){DB::table('accounting_journals')->where('id',$id)->update($header);DB::table('accounting_journal_lines')->where('journal_id',$id)->delete();}
            else $id=DB::table('accounting_journals')->insertGetId($header+['branch_id'=>$branch,'creation_key'=>$key,'created_by'=>$actor,'created_at'=>now()]);
            foreach($lines as $line)DB::table('accounting_journal_lines')->insert($line+['journal_id'=>$id]);
            $this->audit($actor,'journal',$id,'journal.saved',$old?(array)$old:null,$header+['lines'=>$lines]);return $id;
        });
    }

    public function advance(int $actor,int $id,int $version,string $step): void
    {
        abort_unless(in_array($step,['submit','approve','post'],true),404);
        DB::transaction(function()use($actor,$id,$version,$step){
            $this->lock();$j=DB::table('accounting_journals')->lockForUpdate()->find($id);abort_unless($j,404);
            app(AccountingAccess::class)->authorize($actor,'journal_'.$step,$j->branch_id);
            if($j->version!==$version)$this->fail('نسخة القيد قديمة.');
            if($step==='post')$this->fail('الترحيل محجوب: تاريخ التشغيل العام وإصدار PDF رسمي محفوظ وترقيم System غير مكتملة.');
            if(($step==='submit'&&($j->status!=='draft'||$j->created_by!==$actor))||($step==='approve'&&($j->status!=='submitted'||$j->created_by===$actor)))$this->fail('حالة غير صالحة أو محاولة اعتماد ذاتي.');
            $lines=DB::table('accounting_journal_lines')->where('journal_id',$id)->get();$dr='0.00';$cr='0.00';
            foreach($lines as $line){
                $account=DB::table('accounting_accounts')->find($line->account_id);if(!$account||!$account->active||!$account->postable)$this->fail('يوجد حساب غير مؤهل.');
                $dr=bcadd($dr,$line->debit,2);$cr=bcadd($cr,$line->credit,2);
            }
            if($lines->count()<2||bccomp($dr,'0',2)<=0||bccomp($dr,$cr,2)!==0)$this->fail('القيد غير متوازن. يجب تساوي المدين والدائن.');
            $after=['status'=>$step==='submit'?'submitted':'approved','version'=>$version+1,'updated_at'=>now()];
            if($step==='approve')$after+=['approved_by'=>$actor,'approved_at'=>now()];
            DB::table('accounting_journals')->where('id',$id)->update($after);
            $this->audit($actor,'journal',$id,'journal.'.$step,(array)$j,$after);
        });
    }
}
