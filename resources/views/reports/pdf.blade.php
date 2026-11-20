@php
    use App\Actions\Reports\ReportExporter;

    /** @var \App\Actions\Reports\ReportTable $table */
    $logo = 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('images/logo-print.png')));
    $rightAligned = ['money', 'number', 'percent'];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $table->title }}</title>
    <style>
        @page { margin: 22px 26px 30px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; color: #1f2937; }
        .header { width: 100%; border-bottom: 2px solid #00a651; padding-bottom: 6px; margin-bottom: 8px; }
        .header td { vertical-align: middle; }
        h1 { font-size: 14px; color: #00a651; margin: 0; }
        .muted { color: #6b7280; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #e8f6ee; text-align: left; padding: 4px 5px; border-bottom: 1px solid #00a651; }
        table.data td { padding: 3px 5px; border-bottom: 1px solid #e5e7eb; }
        table.data tr.total td { font-weight: bold; border-top: 1px solid #00a651; border-bottom: none; background: #f9fafb; }
        .right, table.data th.right, table.data td.right { text-align: right; }
        .summary { margin-top: 10px; width: 45%; border-collapse: collapse; }
        .summary td { padding: 2px 5px; border-bottom: 1px dotted #d1d5db; }
        .footer { position: fixed; bottom: -16px; left: 0; right: 0; font-size: 7.5px; color: #9ca3af; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 30%"><img src="{{ $logo }}" style="height: 40px"></td>
            <td class="right">
                <h1>{{ $table->title }}</h1>
                <div>{{ $table->subtitle }}</div>
            </td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                @foreach ($table->columns as $column)
                    <th @class(['right' => in_array($column['type'], $rightAligned, true)])>{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($table->rows as $row)
                <tr>
                    @foreach ($table->columns as $column)
                        <td @class(['right' => in_array($column['type'], $rightAligned, true)])>{{ ReportExporter::display($row[$column['key']] ?? null, $column['type']) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($table->columns) }}" class="muted">Tidak ada data untuk filter ini.</td></tr>
            @endforelse
            @if ($table->totals)
                <tr class="total">
                    @foreach ($table->columns as $column)
                        <td @class(['right' => in_array($column['type'], $rightAligned, true)])>{{ ReportExporter::display($table->totals[$column['key']] ?? null, $column['type']) }}</td>
                    @endforeach
                </tr>
            @endif
        </tbody>
    </table>

    @if ($table->summary !== [])
        <table class="summary">
            @foreach ($table->summary as $item)
                <tr><td>{{ $item['label'] }}</td><td class="right">{{ $item['value'] }}</td></tr>
            @endforeach
        </table>
    @endif

    <div class="footer">
        Dicetak oleh {{ auth()->user()?->name ?? 'sistem' }} melalui SIKEU Lentera pada {{ now()->translatedFormat('j F Y H:i') }}
    </div>
</body>
</html>
