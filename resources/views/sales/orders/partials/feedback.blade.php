@if(session('status'))
    <flux:callout variant="success">{{ session('status') }}</flux:callout>
@endif
@if($errors->any())
    <flux:callout variant="danger">
        <ul class="list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </flux:callout>
@endif
