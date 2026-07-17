<?php

namespace App\Mail;

use App\Models\AuditRequest;
use App\Models\User;
use App\Support\MailBrand;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuditPaymentConfirmedEmail extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;
    public AuditRequest $auditRequest;
    public string $subjectLine;
    public string $headingText;
    public string $auditTypeTitle;
    public ?string $solutionLabel;

    public function __construct(User $user, AuditRequest $auditRequest)
    {
        $this->user = $user;
        $this->auditRequest = $auditRequest;
        $this->subjectLine = 'Your audit payment has been confirmed - Troosolar';
        $this->headingText = MailBrand::heading('Your audit date and time have been confirmed');
        $this->auditTypeTitle = AuditStatusEmail::auditTypeTitleCase($auditRequest);
        $this->solutionLabel = AuditStatusEmail::productCategoryLabel($auditRequest->product_category ?? null);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            replyTo: [config('mail.from.address')],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.audit_payment_confirmed',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
