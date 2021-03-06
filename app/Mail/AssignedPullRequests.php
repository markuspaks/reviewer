<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AssignedPullRequests extends Mailable
{
    use Queueable, SerializesModels;

    protected array $user;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($user)
    {
        $this->user = $user;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): static
    {
        return $this
            ->subject(count($this->user['pull_requests']) . ' pull requests waiting for you')
            ->markdown('mails.assigned-pull-requests', [
            'user' => $this->user
        ]);
    }
}
