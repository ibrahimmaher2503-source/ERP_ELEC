@props(['status'=>'draft'])
@php($labels=['draft'=>'مسودة','pending_approval'=>'بانتظار الموافقة','issued'=>'صادر','sent'=>'مرسل','accepted'=>'مقبول','rejected'=>'مرفوض','expired'=>'منتهي الصلاحية','cancelled'=>'ملغي'])
<span @class(['quote-status','quote-status-success'=>in_array($status,['accepted','issued']),'quote-status-warning'=>$status==='pending_approval','quote-status-danger'=>in_array($status,['cancelled','rejected','expired'])])>{{ $labels[$status] ?? $status }}</span>
