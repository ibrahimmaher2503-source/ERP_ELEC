<!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><style>
body { font-family: dejavusans; color: #202b3d; font-size: 10pt; }
h1 { font-size: 17pt; } table { border-collapse: collapse; width: 100%; margin-top: 14px; }
td, th { border: 1px solid #cbd5e1; padding: 6px; text-align: right; } th { background: #edf3f8; }
.meta { font-size: 9pt; color: #475569; } .value { direction: ltr; text-align: left; }
</style></head><body>
<h1>{{ $snapshot['company']['legal_name'] }}</h1>
@if($snapshot['company']['logo_data_uri'] ?? null)<img src="{{ $snapshot['company']['logo_data_uri'] }}" style="max-width: 120px; max-height: 70px" alt="شعار الشركة" />@endif
<p>{{ $snapshot['company']['address'] }}</p>
@if($snapshot['company']['tax_registration'])<p>التسجيل الضريبي: {{ $snapshot['company']['tax_registration'] }}</p>@endif
<h2>{{ $snapshot['content']['title'] }} · {{ $snapshot['number'] }}</h2>
<p class="meta">التاريخ: {{ $snapshot['date'] }} · الإصدار: {{ $snapshot['revision'] }} · العملة: EGP</p>
@foreach($snapshot['content']['fields'] ?? [] as $label => $value)<p><strong>{{ $label }}:</strong> {{ $value }}</p>@endforeach
<table><thead><tr>@foreach($snapshot['content']['columns'] as $label)<th>{{ $label }}</th>@endforeach</tr></thead><tbody>
@foreach($snapshot['content']['rows'] as $row)<tr>@foreach($row as $value)<td>{{ $value }}</td>@endforeach</tr>@endforeach
</tbody></table>
@foreach($snapshot['content']['totals'] ?? [] as $label => $value)<p><strong>{{ $label }}: {{ $value }} EGP</strong></p>@endforeach
@if(isset($snapshot['content']['tax']))
<p>وضع الضريبة: {{ $snapshot['content']['tax']['mode']==='inclusive'?'شامل':'غير شامل' }}</p>
<table><thead><tr><th>الصنف</th><th>الكود</th><th>النسبة %</th><th>الصافي</th><th>الضريبة</th><th>الإجمالي</th></tr></thead><tbody>
@foreach($snapshot['content']['tax']['lines'] as $line)<tr><td>{{ $line['product_id'] }}</td><td>{{ $line['tax_code'] }}</td><td>{{ $line['rate'] }}</td><td>{{ $line['net'] }}</td><td>{{ $line['tax'] }}</td><td>{{ $line['total'] }}</td></tr>@endforeach
</tbody></table>
@endif
@if($snapshot['content']['signature_area'] ?? false)<p>التوقيع المعتمد: ____________________</p>@endif
<p class="meta">نسخة محفوظة ثابتة في النظام. هذا المستند لا يمثل بمفرده تسجيلًا أو توقيعًا إلكترونيًا لدى مصلحة الضرائب.</p>
</body></html>
