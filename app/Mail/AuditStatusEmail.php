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

    public function __construct(User $user, AuditRequest $auditRequest, string $status)
    {
        $this->user = $user;
        $this->auditRequest = $auditRequest;
        $this->status = $status;
        $this->auditTypeLabel = self::auditTypeDisplayLabel($auditRequest);
        $this->auditTypeTitle = self::auditTypeTitleCase($auditRequest);

        if ($status === 'approved') {
            $this->subjectLine = 'Your audit request has been approved - Troosolar';
            $this->headingText = MailBrand::heading('Your audit request has been approved');
            $this->bodyText = 'Your '.$this->auditTypeLabel.' audit request has been approved. Professional audits are paid services — please complete payment using the instructions below so we can confirm your booking and schedule your visit.';
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
