<?php

namespace App\Domain\Reports\Email;

use App\Enums\ReportSection;
use App\Enums\ReportView;
use App\Mail\FinancialReportMail;
use App\Models\ReportEmail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Envia o resumo escolhido (resumo do mês, patrimônio e/ou carteira) para um ou mais e-mails e registra o envio.
 * O conteúdo é calculado agora, como quem envia; a fila só entrega (um e-mail por destinatário).
 */
class SendFinancialReport
{
    public const MAX_RECIPIENTS = 10;

    public function __construct(
        private readonly BuildFinancialReport $builder,
    ) {}

    /**
     * @param  array{recipients?: mixed, sections?: mixed, view?: mixed, month?: mixed, message?: mixed}  $data
     */
    public function execute(User $sender, array $data): ReportEmail
    {
        $data['recipients'] = collect(is_array($data['recipients'] ?? null) ? $data['recipients'] : [])
            ->map(fn (mixed $email): string => mb_strtolower(trim((string) $email)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $valid = Validator::make($data, [
            'recipients' => ['required', 'array', 'max:'.self::MAX_RECIPIENTS],
            'recipients.*' => ['email:rfc'],
            'sections' => ['required', 'array'],
            'sections.*' => [Rule::enum(ReportSection::class)],
            'view' => ['required', Rule::enum(ReportView::class)],
            'month' => [Rule::requiredIf(fn (): bool => in_array(ReportSection::MonthlySummary->value, (array) ($data['sections'] ?? []), true)), 'nullable', 'date_format:Y-m'],
            'message' => ['nullable', 'string', 'max:2000'],
        ], [
            'recipients.required' => 'Escolha ao menos um destinatário.',
            'recipients.max' => 'No máximo '.self::MAX_RECIPIENTS.' destinatários por envio.',
            'recipients.*.email' => 'E-mail inválido: :input.',
            'sections.required' => 'Escolha ao menos uma parte para enviar.',
        ], [
            'sections' => 'o que enviar',
            'view' => 'visão',
            'month' => 'mês',
            'message' => 'mensagem',
        ])->validate();

        /** @var list<ReportSection> $sections */
        $sections = array_values(array_filter(
            ReportSection::cases(),
            fn (ReportSection $section): bool => in_array($section->value, $valid['sections'], true),
        ));
        $view = ReportView::from($valid['view']);
        $month = in_array(ReportSection::MonthlySummary, $sections, true)
            ? Carbon::createFromFormat('Y-m-d', $valid['month'].'-01')?->startOfDay()
            : null;

        $report = $this->builder->execute($sender, $sections, $view, $month, $valid['message'] ?? null);

        foreach ($valid['recipients'] as $recipient) {
            Mail::to($recipient)->queue(new FinancialReportMail($report));
        }

        return ReportEmail::create([
            'sender_id' => $sender->id,
            'recipients' => $valid['recipients'],
            'sections' => array_map(fn (ReportSection $section): string => $section->value, $sections),
            'view' => $view,
            'month' => $month,
        ]);
    }
}
