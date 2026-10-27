<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MakeCentralToken extends Command
{
    protected $signature = 'central:make-token';

    protected $description = 'Print a new central dashboard token and its .env hash line (nothing is stored)';

    public function handle(): int
    {
        $token = bin2hex(random_bytes(32));

        $this->line('Token (give it to the dashboard, shown only once):');
        $this->line($token);
        $this->newLine();
        $this->line('Paste into .env (use NEXT instead of CURRENT while rotating):');
        $this->line('CENTRAL_API_TOKEN_HASH_CURRENT='.hash('sha256', $token));

        return self::SUCCESS;
    }
}
