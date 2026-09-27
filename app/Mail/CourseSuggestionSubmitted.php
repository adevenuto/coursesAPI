<?php

namespace App\Mail;

use App\Models\CourseSuggestion;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notifies the support inbox that someone suggested a course.
 *
 * Deliberately not `ShouldQueue`: nothing drains the queue on this host, so a
 * queued mail would sit in `jobs` forever and silently. The controller sends it
 * inline — see the note there.
 */
class CourseSuggestionSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public CourseSuggestion $suggestion) {}

    public function envelope(): Envelope
    {
        $where = $this->suggestion->locationLabel();

        return new Envelope(
            subject: trim(sprintf(
                '[%s] %s%s',
                $this->suggestion->typeLabel(),
                $this->suggestion->course_name,
                $where === '' ? '' : ' — '.$where,
            )),
            // Hitting Reply in the support inbox answers the submitter, not us.
            replyTo: [new Address($this->suggestion->submitter_email)],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.course-suggestion',
            with: ['suggestion' => $this->suggestion],
        );
    }
}
