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

class AuditStatusEmail extends Mailable
{
    use Queueable, SerializesModels;

    public User $user;
    public AuditRequest $auditRequest;
    public string $subjectLine;
    public string $headingText;
    public string $bodyText;
    public string $status;
    public string $auditTypeLabel;

    /** Title-case line for the summary box (e.g. Office, Home, Commercial / Industrial). */
    public string $auditTypeTitle;

    /** Solution the customer chose before requesting audit (full-kit / inverter-battery, etc.). */
    public ?string $solutionLabel;

    public function __construct(User $user, AuditRequest $auditRequest, string $status)
    {
        $this->user = $user;
        $this->auditRequest = $auditRequest;
        $this->status = $status;
        $this->auditTypeLabel = self::auditTypeDisplayLabel($auditRequest);
        $this->auditTypeTitle = self::auditTypeTitleCase($auditRequest);
        $this->solutionLabel = self::productCategoryLabel($auditRequest->product_category ?? null);

        if ($status === 'approved') {
            $this->subjectLine = 'Your audit request has been approved - Troosolar';
            $this->headingText = MailBrand::heading('Your audit request has been approved');
            $this->bodyText = 'Your '.$this->auditTypeLabel.' audit request has been approved. Professional audits are paid services — please complete payment using the instructions below, then reply to this email with your payment receipt for confirmation.';
        } elseif ($status === 'rejected') {
            $this->subjectLine = 'Update on your audit request - Troosolar';
            $this->headingText = MailBrand::heading('Your audit request was not approved');
            $this->bodyText = 'We are unable to approve your '.$this->auditTypeLabel.' audit request at this time. If you have questions, please reply to this email or contact support.';
        } else {
            $this->subjectLine = 'Update on your audit request - Troosolar';
            $this->headingText = MailBrand::heading('Update on your audit request');
            $this->bodyText = 'Your audit request status has been updated.';
        }
    }

    public static function auditTypeDisplayLabel(AuditRequest $r): string
    {
        if ($r->audit_type === 'commercial') {
            return 'commercial / industrial';
        }
        if ($r->audit_type === 'home-office') {
            if ($r->audit_subtype === 'office') {
                return 'office';
            }
            if ($r->audit_subtype === 'home') {
                return 'home';
            }

            return 'home';
        }

        return (string) ($r->audit_type ?: 'audit');
    }

    public static function auditTypeTitleCase(AuditRequest $r): string
    {
        if ($r->audit_type === 'commercial') {
            return 'Commercial / Industrial';
        }
        if ($r->audit_type === 'home-office') {
            if ($r->audit_subtype === 'office') {
                return 'Office';
            }

            return 'Home';
        }

        return $r->audit_type ? ucfirst((string) $r->audit_type) : 'Audit';
    }

    public static function productCategoryLabel(?string $value): ?string
    {
        $v = strtolower(trim((string) $value));
        if ($v === '') {
            return null;
        }

        return match ($v) {
            'full-kit' => 'Solar panels, inverter, and battery solution',
            'inverter-battery' => 'Inverter and battery solution',
            'battery-only' => 'Battery only',
            'inverter-only' => 'Inverter only',
            'panels-only' => 'Solar panels only',
            'audit' => 'Professional energy audit',
            default => str_replace('-', ' ', (string) $value),
        };
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
            view: 'emails.audit_status',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
