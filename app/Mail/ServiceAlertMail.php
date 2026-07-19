<?php

namespace App\Mail;

use App\Models\Alert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class ServiceAlertMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, Alert>  $alerts
     */
    public function __construct(public Collection $alerts) {}

    public function build(): self
    {
        return $this
            ->subject(__('alerts.mail_subject', ['count' => $this->alerts->count()]))
            ->view('mail.service-alert')
            ->with(['alerts' => $this->alerts]);
    }
}
