<?php

namespace App\Mail;

use App\Models\UserNextStep;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NextStepModuleAssigned extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public UserNextStep $step) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your next module is ready: '.$this->step->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.next-step-module-assigned',
        );
    }
}
