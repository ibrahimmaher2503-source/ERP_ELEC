<?php

namespace App\Modules\Inventory\Presentation;

use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Application\InventoryConditions;
use App\Modules\Inventory\Application\InventoryCorrections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

final class InventoryOperationsPage extends Component
{
    use WithPagination;

    #[Locked] public string $screen='isolations';
    #[Locked] public string $operationKey='';
    public string $warehouse='';
    public string $search='';
    public array $form=['source_movement_id'=>'','quantity'=>'','reason'=>'','serials'=>''];
    public array $releaseQuantities=[];
    public array $releaseSerials=[];
    #[Locked] public array $releaseKeys=[];
    #[Locked] public ?int $selectedSerial=null;

    private function ids(): array
    {
        $actions=match($this->screen) {'serials'=>['serial_view'],'corrections'=>['correction_execute'],'isolations'=>['isolation_manage','isolation_release'],default=>abort(404)};
        $ids=[];
        foreach($actions as $action) { $ids=array_merge($ids,app(InventoryAccess::class)->ids(auth()->id(),$action)); }
        abort_unless($ids,403);
        return array_values(array_unique($ids));
    }

    public function mount(string $screen='isolations'): void
    {
        $this->screen=$screen; $this->ids(); $this->operationKey=(string)Str::uuid();
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedWarehouse(): void { $this->resetPage(); $this->selectedSerial=null; }

    private function serials(string $text): array { return preg_split('/\R/u',$text,-1,PREG_SPLIT_NO_EMPTY); }

    public function execute(): void
    {
        $this->ids();
        $this->validate(['form.source_movement_id'=>'required|integer|min:1','form.quantity'=>'required|string','form.reason'=>'required|string|max:2000','form.serials'=>'string|max:200000']);
        $source=DB::table('inventory_movements')->find((int)$this->form['source_movement_id']); abort_unless($source,404);
        $data=['quantity'=>$this->form['quantity'],'reason'=>$this->form['reason'],'idempotency_key'=>$this->operationKey,'serials'=>$this->serials($this->form['serials'])];
        if ($this->screen==='isolations') {
            $out=app(InventoryConditions::class)->isolate(auth()->id(),$data+['warehouse_id'=>$source->warehouse_id,'product_id'=>$source->product_id,'source_movement_id'=>$source->id]);
            $message='تم عزل الكمية؛ الرصيد الفعلي ثابت والمتاح انخفض.';
        } else {
            abort_unless($this->screen==='corrections',422);
            $out=app(InventoryCorrections::class)->reverse(auth()->id(),$data+['movement_id'=>$source->id]);
            $message=$out['status']==='posted'?'تم ترحيل الحركة التصحيحية مع بقاء الأصل ثابتًا.':'حفظ طلب التصحيح بانتظار التكامل المالي؛ لم تتغير الكميات.';
        }
        $this->operationKey=(string)Str::uuid();
        $this->form=['source_movement_id'=>'','quantity'=>'','reason'=>'','serials'=>''];
        session()->flash('status',$message);
    }

    public function release(int $id): void
    {
        abort_unless($this->screen==='isolations',422);
        $this->ids();
        app(InventoryConditions::class)->release(auth()->id(),$id,$this->releaseQuantities[$id]??'',$this->releaseKeys[$id]??= (string)Str::uuid(),$this->serials($this->releaseSerials[$id]??''));
        unset($this->releaseQuantities[$id],$this->releaseSerials[$id]);
        $this->releaseKeys[$id]=(string)Str::uuid();
        session()->flash('status','تم تسجيل الإفراج عن الكمية المعزولة.');
    }

    public function inspectSerial(int $id): void
    {
        abort_unless($this->screen==='serials',422);
        $this->serialQuery()->where('s.id',$id)->firstOrFail();
        $this->selectedSerial=$id;
    }

    private function serialQuery()
    {
        $ids=$this->ids();
        return DB::table('inventory_serials as s')->where(function($q) use($ids) {
            $q->whereIn('s.warehouse_id',$ids)->orWhereIn('s.transfer_id',DB::table('inventory_documents')->whereIn('destination_id',$ids)->select('id'));
        });
    }

    public function render()
    {
        $ids=$this->ids(); $scope=$this->warehouse===''?$ids:array_values(array_intersect($ids,[(int)$this->warehouse]));
        $history=collect();
        if ($this->screen==='serials') {
            $q=$this->serialQuery();
            if ($this->warehouse!=='') { $q->where('s.warehouse_id',(int)$this->warehouse); }
            if ($this->search!=='') { $q->where(fn($q)=>$q->where('s.normalized','ilike','%'.mb_substr($this->search,0,200).'%')->orWhere('s.entered','ilike','%'.mb_substr($this->search,0,200).'%')); }
            $rows=$q->orderByDesc('s.id')->select('s.*')->paginate(15);
            if ($this->selectedSerial) { $this->serialQuery()->where('s.id',$this->selectedSerial)->firstOrFail(); $history=DB::table('inventory_serial_events')->where('serial_id',$this->selectedSerial)->orderByDesc('id')->get(); }
        } else {
            $q=$this->screen==='isolations'?DB::table('inventory_isolations')->whereIn('warehouse_id',$scope):DB::table('inventory_corrections')->whereIn('movement_id',DB::table('inventory_movements')->whereIn('warehouse_id',$scope)->select('id'));
            if ($this->search!=='') { $q->where('reason','ilike','%'.mb_substr($this->search,0,200).'%'); }
            $rows=$q->orderByDesc('id')->paginate(15);
        }
        $executeIds=$this->screen==='serials'?[]:app(InventoryAccess::class)->ids(auth()->id(),$this->screen==='isolations'?'isolation_manage':'correction_execute');
        return view('inventory.operations',['canExecute'=>$executeIds!==[],'rows'=>$rows,'history'=>$history,'warehouses'=>DB::table('inventory_warehouses')->whereIn('id',$ids)->get(),'access'=>app(InventoryAccess::class),'sources'=>DB::table('inventory_movements')->whereIn('warehouse_id',array_values(array_intersect($scope,$executeIds)))->when($this->screen==='isolations',fn($q)=>$q->where('quantity','>','0'))->orderByDesc('id')->limit(100)->get()])->layout('layouts::app',['title'=>match($this->screen){'serials'=>'الأرقام المسلسلة','isolations'=>'المخزون غير المتاح',default=>'تصحيح حركات المخزون'}]);
    }
}
