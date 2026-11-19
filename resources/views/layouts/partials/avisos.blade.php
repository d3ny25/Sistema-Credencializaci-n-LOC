{{-- Avisos de sesión del layout; también se reenvían en las respuestas de navegación dinámica. --}}
@if (session('status'))
    <div role="status" class="alert alert-success mb-5">
        <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0"/>
        <span>{{ session('status') }}</span>
    </div>
@endif
@if ($errors->any())
    <div role="alert" class="alert alert-danger mb-5">
        <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0"/>
        @if ($errors->count() > 1)
            <div>
                <p class="font-semibold">Se presentaron los siguientes inconvenientes:</p>
                <ul class="mt-1 list-inside list-disc space-y-0.5 text-xs">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @else
            <span>{{ $errors->first() }}</span>
        @endif
    </div>
@endif
