<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Mail;

use App\Enums\VerificationStatus;
use App\Filament\Pages\AiTestBuilderPage;
use App\Models\AiConversation;
use App\Models\AppSetting;
use App\Models\TestSuite;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SuiteRepairProposedMailable extends Mailable
{
    use SerializesModels;

    public function __construct(
        public readonly AiConversation $conversation,
        public readonly TestSuite $suite,
    ) {}

    public function envelope(): Envelope
    {
        $fromAddress = AppSetting::get('mail_from_address') ?: config('mail.from.address');
        $fromName    = AppSetting::get('mail_from_name') ?: config('mail.from.name');

        $statusLabel = $this->conversation->verification_status === VerificationStatus::Passed
            ? 'Verified fix ready for review'
            : 'Proposed fix needs review';

        return new Envelope(
            from: new Address($fromAddress, $fromName),
            subject: sprintf(
                '🔧 %s — %s / %s',
                $statusLabel,
                $this->suite->project->name ?? 'Unknown',
                $this->suite->name
            ),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.suite-repair-proposed', with: [
            'reviewUrl' => $this->buildReviewUrl(),
        ]);
    }

    private function buildReviewUrl(): string
    {
        return AiTestBuilderPage::getUrl() . '?' . http_build_query([
            'project_id' => $this->suite->project_id,
            'conversation' => $this->conversation->ulid,
        ]);
    }
}
