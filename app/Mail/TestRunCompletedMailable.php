<?php

namespace App\Mail;

use App\Models\AppSetting;
use App\Models\TestRun;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TestRunCompletedMailable extends Mailable
{
    use SerializesModels;

    public function __construct(public TestRun $run) {}

    public function envelope(): Envelope
    {
        $status  = $this->run->status === 'passing' ? '✅ Passed' : '❌ Failed';
        $project = $this->run->project->name;
        $client  = $this->run->project->client->name;

        $fromAddress = AppSetting::get('mail_from_address') ?: config('mail.from.address');
        $fromName    = AppSetting::get('mail_from_name') ?: config('mail.from.name');

        return new Envelope(
            from: new \Illuminate\Mail\Mailables\Address($fromAddress, $fromName),
            subject: "[{$status}] {$client} — {$project} · Run #{$this->run->id}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.test-run-completed',
        );
    }
}
