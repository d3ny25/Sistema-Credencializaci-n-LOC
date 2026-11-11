@props(['columns', 'message' => 'No hay información cargada para mostrar.'])

<div class="overflow-x-auto rounded-lg bg-white shadow-card ring-1 ring-slate-900/5">
    <table class="min-w-full divide-y divide-slate-100">
        <thead class="bg-slate-50/80">
            <tr>
                @foreach ($columns as $column)
                    <th scope="col" class="whitespace-nowrap px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-tx-soft">
                        {{ $column }}
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            <tr>
                <td colspan="{{ count($columns) }}" class="px-4 py-10 text-center text-sm text-tx-soft">
                    {{ $message }}
                </td>
            </tr>
        </tbody>
    </table>
</div>
