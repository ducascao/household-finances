@php
    /** @var \App\Domain\Reports\Email\FinancialReport $report */
    $font = "font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;";
    $h2 = "margin: 28px 0 8px; font-size: 18px; color: #111827; {$font}";
    $h3 = "margin: 18px 0 6px; font-size: 14px; color: #374151; {$font}";
    $table = 'width: 100%; border-collapse: collapse; font-size: 14px;';
    $th = 'text-align: left; padding: 6px 8px; border-bottom: 2px solid #e5e7eb; color: #6b7280; font-weight: 600; font-size: 12px;';
    $thRight = $th.' text-align: right;';
    $td = 'padding: 6px 8px; border-bottom: 1px solid #f3f4f6; color: #111827; vertical-align: top;';
    $tdRight = $td.' text-align: right; white-space: nowrap;';
    $muted = 'color: #6b7280; font-size: 12px;';
    $red = 'color: #b91c1c;';
    $green = 'color: #047857;';
@endphp
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $report->subject() }}</title>
</head>
<body style="margin: 0; padding: 0; background: #f3f4f6; {{ $font }}">
<table role="presentation" style="width: 100%; background: #f3f4f6;" cellpadding="0" cellspacing="0">
    <tr>
        <td style="padding: 16px 8px;">
            <table role="presentation" style="width: 100%; max-width: 640px; margin: 0 auto; background: #ffffff; border-radius: 8px;" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="padding: 20px 20px 24px; {{ $font }} color: #111827;">
                        <p style="margin: 0; {{ $muted }}">Finanças de Casa · {{ $report->household }}</p>
                        <h1 style="margin: 4px 0 4px; font-size: 22px;">Resumo financeiro</h1>
                        <p style="margin: 0; {{ $muted }}">
                            Enviado por {{ $report->sender }} em {{ $report->generatedAt }} · {{ $report->viewLabel }}
                        </p>

                        @if ($report->message)
                            <p style="margin: 16px 0 0; padding: 12px; background: #f9fafb; border-left: 3px solid #2a78d6; white-space: pre-line;">{{ $report->message }}</p>
                        @endif

                        @if ($report->monthly)
                            @php($m = $report->monthly)
                            <h2 style="{{ $h2 }}">Resumo do mês · {{ $m['title'] }}</h2>
                            <table style="{{ $table }}">
                                <tr>
                                    <th style="{{ $th }}"></th>
                                    <th style="{{ $thRight }}">Pago</th>
                                    <th style="{{ $thRight }}">Previsto</th>
                                    <th style="{{ $thRight }}">Total</th>
                                </tr>
                                @foreach ($m['totals'] as $row)
                                    <tr>
                                        <td style="{{ $td }}">{{ $row['label'] }}</td>
                                        <td style="{{ $tdRight }}">{{ $row['paid'] }}</td>
                                        <td style="{{ $tdRight }}">{{ $row['scheduled'] }}</td>
                                        <td style="{{ $tdRight }} font-weight: 600;">{{ $row['total'] }}</td>
                                    </tr>
                                @endforeach
                                <tr>
                                    <td style="{{ $td }} font-weight: 600;" colspan="3">Resultado</td>
                                    <td style="{{ $tdRight }} font-weight: 700; {{ $m['result_negative'] ? $red : $green }}">{{ $m['result'] }}</td>
                                </tr>
                            </table>

                            <h3 style="{{ $h3 }}">Gastos por categoria</h3>
                            @if ($m['categories'] === [])
                                <p style="margin: 0; {{ $muted }}">Nenhum gasto no mês.</p>
                            @else
                                <table style="{{ $table }}">
                                    @foreach ($m['categories'] as $category)
                                        <tr>
                                            <td style="{{ $td }} font-weight: 600;">{{ $category['name'] }}</td>
                                            <td style="{{ $tdRight }} font-weight: 600;">{{ $category['total'] }}</td>
                                            <td style="{{ $tdRight }} {{ $muted }}">{{ $category['percent'] }}</td>
                                        </tr>
                                        @foreach ($category['children'] as $child)
                                            <tr>
                                                <td style="{{ $td }} padding-left: 20px; color: #4b5563;">↳ {{ $child['name'] }}</td>
                                                <td style="{{ $tdRight }} color: #4b5563;">{{ $child['total'] }}</td>
                                                <td style="{{ $td }}"></td>
                                            </tr>
                                        @endforeach
                                    @endforeach
                                </table>
                            @endif

                            <h3 style="{{ $h3 }}">Saldo projetado no fim do mês</h3>
                            @if ($m['projection'] === [])
                                <p style="margin: 0; {{ $muted }}">Nenhuma conta.</p>
                            @else
                                <table style="{{ $table }}">
                                    <tr>
                                        <th style="{{ $th }}">Conta</th>
                                        <th style="{{ $thRight }}">Hoje</th>
                                        <th style="{{ $thRight }}">Previstos</th>
                                        <th style="{{ $thRight }}">Fim do mês</th>
                                    </tr>
                                    @foreach ($m['projection'] as $row)
                                        <tr>
                                            <td style="{{ $td }}">{{ $row['account'] }}</td>
                                            <td style="{{ $tdRight }}">{{ $row['today'] }}</td>
                                            <td style="{{ $tdRight }}">{{ $row['scheduled'] }}</td>
                                            <td style="{{ $tdRight }} font-weight: 600; {{ $row['negative'] ? $red : '' }}">{{ $row['projected'] }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            @if ($m['other_currencies'] !== [])
                                <p style="margin: 8px 0 0; {{ $muted }}">Lançamentos em {{ implode(', ', $m['other_currencies']) }} ficam fora dos totais.</p>
                            @endif
                        @endif

                        @if ($report->netWorth)
                            @php($n = $report->netWorth)
                            <h2 style="{{ $h2 }}">Patrimônio líquido · {{ $n['date'] }}</h2>
                            <p style="margin: 0 0 8px; font-size: 24px; font-weight: 700; {{ $n['negative'] ? $red : '' }}">{{ $n['total'] }}</p>

                            @if ($n['groups'] !== [])
                                <table style="{{ $table }}">
                                    @foreach ($n['groups'] as $group)
                                        <tr>
                                            <td style="{{ $td }} font-weight: 600; background: #f9fafb;">{{ $group['label'] }}</td>
                                            <td style="{{ $tdRight }} font-weight: 600; background: #f9fafb; {{ $group['debt'] ? $red : '' }}">{{ $group['total'] }}</td>
                                        </tr>
                                        @foreach ($group['items'] as $item)
                                            <tr>
                                                <td style="{{ $td }} padding-left: 20px; color: #4b5563;">{{ $item['name'] }}</td>
                                                <td style="{{ $tdRight }} color: #4b5563;">{{ $item['value'] }}</td>
                                            </tr>
                                        @endforeach
                                    @endforeach
                                </table>
                            @endif

                            @if (count($n['history']) > 1)
                                <h3 style="{{ $h3 }}">Evolução</h3>
                                <table style="{{ $table }}">
                                    <tr>
                                        <th style="{{ $th }}">Mês</th>
                                        <th style="{{ $thRight }}">Patrimônio</th>
                                        <th style="{{ $thRight }}">Variação</th>
                                    </tr>
                                    @foreach ($n['history'] as $point)
                                        <tr>
                                            <td style="{{ $td }}">{{ $point['month'] }}</td>
                                            <td style="{{ $tdRight }}">{{ $point['value'] }}</td>
                                            <td style="{{ $tdRight }} {{ $point['change'] === null ? '' : ($point['change_negative'] ? $red : $green) }}">{{ $point['change'] ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            @if ($n['missing'] !== [])
                                <p style="margin: 8px 0 0; {{ $muted }}">Fora do total por falta de câmbio: {{ implode(', ', $n['missing']) }}.</p>
                            @endif
                        @endif

                        @if ($report->portfolio)
                            @php($p = $report->portfolio)
                            <h2 style="{{ $h2 }}">Ativos (Carteira)</h2>
                            @if ($p['rows'] === [])
                                <p style="margin: 0; {{ $muted }}">Nenhuma posição aberta.</p>
                            @else
                                <table style="{{ $table }}">
                                    <tr>
                                        <td style="{{ $td }}">Investido</td>
                                        <td style="{{ $tdRight }}">{{ $p['totals']['cost'] }}</td>
                                    </tr>
                                    <tr>
                                        <td style="{{ $td }}">Valor atual</td>
                                        <td style="{{ $tdRight }} font-weight: 700;">{{ $p['totals']['market'] }}</td>
                                    </tr>
                                    <tr>
                                        <td style="{{ $td }}">Resultado</td>
                                        <td style="{{ $tdRight }} font-weight: 600; {{ $p['totals']['negative'] ? $red : $green }}">
                                            {{ $p['totals']['result'] }}@if ($p['totals']['percent']) ({{ $p['totals']['percent'] }})@endif
                                        </td>
                                    </tr>
                                </table>

                                <h3 style="{{ $h3 }}">Distribuição por tipo</h3>
                                <table style="{{ $table }}">
                                    @foreach ($p['distribution'] as $item)
                                        <tr>
                                            <td style="{{ $td }}">{{ $item['type'] }}</td>
                                            <td style="{{ $tdRight }}">{{ $item['value'] }}</td>
                                            <td style="{{ $tdRight }} {{ $muted }}">{{ $item['percent'] }}</td>
                                        </tr>
                                    @endforeach
                                </table>

                                <h3 style="{{ $h3 }}">Posições</h3>
                                <table style="{{ $table }}">
                                    <tr>
                                        <th style="{{ $th }}">Ativo</th>
                                        <th style="{{ $thRight }}">Valor</th>
                                        <th style="{{ $thRight }}">Resultado</th>
                                    </tr>
                                    @foreach ($p['rows'] as $row)
                                        <tr>
                                            <td style="{{ $td }}">
                                                <strong>{{ $row['asset'] }}</strong><br>
                                                <span style="{{ $muted }}">{{ $row['detail'] }}@if ($row['quantity'] !== '—') · {{ $row['quantity'] }} cotas @endif</span>
                                            </td>
                                            <td style="{{ $tdRight }}">
                                                {{ $row['market'] }}<br>
                                                <span style="{{ $muted }}">{{ $row['share'] }} da carteira</span>
                                            </td>
                                            <td style="{{ $tdRight }} {{ $row['negative'] ? $red : $green }}">
                                                {{ $row['result'] }}@if ($row['percent'])<br><span style="font-size: 12px;">{{ $row['percent'] }}</span>@endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>

                                @if ($p['missing'] !== [])
                                    <p style="margin: 8px 0 0; {{ $muted }}">Fora dos totais por falta de câmbio: {{ implode(', ', $p['missing']) }}.</p>
                                @endif
                            @endif
                        @endif

                        <p style="margin: 28px 0 0; {{ $muted }}">
                            @if ($report->sharedOnly)
                                Só contas, bens e investimentos compartilhados do lar.
                            @else
                                Inclui as contas pessoais de {{ $report->sender }}.
                            @endif
                            Valores calculados no momento do envio.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
