<?php

namespace App\Mail;

use App\Domain\Reports\Email\FinancialReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * E-mail com o resumo financeiro já montado (só textos; nada é consultado na hora da entrega).
 */
class FinancialReportMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly FinancialReport $report,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->report->subject());
    }

    public function content(): Content
    {
        return new Content(view: 'mail.financial-report', with: ['report' => $this->report]);
    }
}
