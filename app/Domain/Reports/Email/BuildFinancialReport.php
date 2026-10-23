<?php

namespace App\Domain\Reports\Email;

use App\Domain\Investments\Portfolio;
use App\Domain\Investments\PortfolioRow;
use App\Domain\Investments\Quantity;
use App\Domain\NetWorth\NetWorthHistory;
use App\Domain\Reports\MonthlySummary;
use App\Enums\ReportSection;
use App\Enums\ReportView;
use App\Models\Household;
use App\Models\Scopes\VisibleAccountScope;
use App\Models\User;
use App\Support\MoneyFormatter;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * Monta o e-mail de resumo com os mesmos cálculos das telas (Resumo do mês, Patrimônio e Carteira).
 *
 * Roda com quem envia logado, para valerem os escopos de lar e visibilidade. Na visão "só o compartilhado",
 * as contas, bens e ativos pessoais de quem envia também ficam de fora, e o patrimônio usa a visão do lar.
 */
class BuildFinancialReport
{
    public function __construct(
        private readonly MonthlySummary $summary,
        private readonly NetWorthHistory $netWorth,
        private readonly Portfolio $portfolio,
    ) {}

    /**
     * @param  list<ReportSection>  $sections
     */
    public function execute(User $sender, array $sections, ReportView $view, ?Carbon $month = null, ?string $message = null): FinancialReport
    {
        if (Auth::id() !== $sender->id) {
            throw new LogicException('O resumo é montado com quem envia logado.');
        }

        $shared = $view === ReportView::Shared;
        $build = fn (): FinancialReport => new FinancialReport(
            household: (string) Household::find($sender->current_household_id)?->name,
            sender: $sender->name,
            viewLabel: $view->label(),
            sharedOnly: $shared,
            generatedAt: now()->format('d/m/Y H:i'),
            sectionLabels: array_map(fn (ReportSection $section): string => $section->label(), $sections),
            message: filled($message) ? trim((string) $message) : null,
            monthly: in_array(ReportSection::MonthlySummary, $sections, true) ? $this->monthly($month ?? today()) : null,
            netWorth: in_array(ReportSection::NetWorth, $sections, true) ? $this->netWorth($sender, $shared) : null,
            portfolio: in_array(ReportSection::Portfolio, $sections, true) ? $this->portfolio() : null,
        );

        return $shared ? VisibleAccountScope::onlyShared($build) : $build();
    }

    /**
     * @return array<string, mixed>
     */
    private function monthly(Carbon $month): array
    {
        $totals = $this->summary->totals($month);
        $row = fn (string $label, array $values): array => [
            'label' => $label,
            'paid' => self::money($values['paid']),
            'scheduled' => self::money($values['scheduled']),
            'total' => self::money($values['total']),
        ];

        $categories = array_map(fn (array $item): array => [
            'name' => $item['category']->name,
            'total' => self::money($item['total']),
            'percent' => self::percent($item['percent'], 1),
            'children' => array_map(fn (array $child): array => [
                'name' => $child['category']->name,
                'total' => self::money($child['total']),
            ], $item['children']),
        ], $this->summary->expensesByCategory($month));

        $projection = array_map(fn (array $item): array => [
            'account' => $item['account']->name,
            'today' => MoneyFormatter::formatMinor($item['today'], $item['account']->currency),
            'scheduled' => MoneyFormatter::formatMinor($item['scheduled'], $item['account']->currency),
            'projected' => MoneyFormatter::formatMinor($item['projected'], $item['account']->currency),
            'negative' => $item['projected'] < 0,
        ], $this->summary->projection($month));

        return [
            'title' => ucfirst($month->copy()->locale('pt_BR')->translatedFormat('F/Y')),
            'totals' => [$row('Entradas', $totals['income']), $row('Saídas', $totals['expense'])],
            'result' => self::money($totals['result']),
            'result_negative' => $totals['result'] < 0,
            'categories' => $categories,
            'projection' => $projection,
            'other_currencies' => $this->summary->otherCurrencies($month),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function netWorth(User $sender, bool $household): array
    {
        $now = $this->netWorth->current($sender, $household);
        $groups = [];

        foreach (['Contas' => $now->accountItems, 'Investimentos' => $now->investmentItems, 'Bens' => $now->goodItems, 'Dívidas' => $now->debtItems] as $label => $items) {
            if ($items === []) {
                continue;
            }

            arsort($items);
            $debt = $label === 'Dívidas';
            $groups[] = [
                'label' => $label,
                'total' => self::money($debt ? -array_sum($items) : array_sum($items)),
                'debt' => $debt,
                'items' => array_map(
                    fn (string $name, int $value): array => ['name' => $name, 'value' => self::money($debt ? -$value : $value)],
                    array_keys($items),
                    array_values($items),
                ),
            ];
        }

        $series = array_slice($this->netWorth->series($sender, $household, 7), -7);
        $history = [];
        $previous = null;

        foreach ($series as $point) {
            $history[] = [
                'month' => $point['month']->format('m/Y').($point['live'] ? ' (hoje)' : ''),
                'value' => self::money($point['net_worth']),
                'change' => $previous === null ? null : self::money($point['net_worth'] - $previous),
                'change_negative' => $previous !== null && $point['net_worth'] < $previous,
            ];
            $previous = $point['net_worth'];
        }

        return [
            'date' => today()->format('d/m/Y'),
            'total' => self::money($now->netWorth()),
            'negative' => $now->netWorth() < 0,
            'groups' => $groups,
            'history' => array_reverse($history),
            'missing' => $now->missing,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function portfolio(): array
    {
        $rows = $this->portfolio->rows();
        $totals = $this->portfolio->totals($rows);
        $market = $totals['market'];

        return [
            'rows' => array_map(fn (PortfolioRow $row): array => $this->position($row, $market), $rows),
            'totals' => [
                'cost' => MoneyFormatter::format($totals['cost']),
                'market' => MoneyFormatter::format($totals['market']),
                'result' => MoneyFormatter::format($totals['result']),
                'percent' => $totals['percent'] !== null ? self::percent($totals['percent'], 2) : null,
                'negative' => $totals['result']->isNegative(),
            ],
            'distribution' => array_map(fn (array $item): array => [
                'type' => $item['type']->label(),
                'value' => MoneyFormatter::format($item['value']),
                'percent' => self::percent($item['percent'], 1),
            ], $this->portfolio->distribution($rows)),
            'missing' => $totals['missing'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function position(PortfolioRow $row, Money $totalMarket): array
    {
        $market = $row->marketValueBrl();
        $result = $row->resultBrl();
        $cost = $row->costBrl();
        $percent = $result !== null && $cost !== null && $cost->isPositive()
            ? round((float) (string) $result->getAmount() / (float) (string) $cost->getAmount() * 100, 2)
            : null;

        return [
            'asset' => $row->asset->label(),
            'detail' => $row->asset->type->label().' · '.$row->asset->account->name,
            'quantity' => $row->isValuedByBalance() ? '—' : Quantity::format((string) $row->position->quantity),
            'market' => $market !== null ? MoneyFormatter::format($market) : 'sem câmbio',
            'result' => $result !== null ? MoneyFormatter::format($result) : '—',
            'percent' => $percent !== null ? self::percent($percent, 2) : null,
            'negative' => $result?->isNegative() ?? false,
            'share' => $market !== null && $totalMarket->isPositive()
                ? self::percent((float) (string) $market->getAmount() / (float) (string) $totalMarket->getAmount() * 100, 1)
                : '—',
        ];
    }

    private static function money(int $minor): string
    {
        return MoneyFormatter::formatMinor($minor);
    }

    private static function percent(float $value, int $decimals): string
    {
        return number_format($value, $decimals, ',', '.').'%';
    }
}
