<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateLocalUser extends Command
{
    protected $signature = 'erp:create-local-user {email} {--name=Local Developer}';

    protected $description = 'Create a verified local development account without business grants';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This command is only available in local development.');

            return self::FAILURE;
        }

        $validator = Validator::make([
            'name' => $this->option('name'),
            'email' => $this->argument('email'),
            'password' => $this->secret('Password (minimum 12 characters)'),
        ], [
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        User::create($validator->validated())->markEmailAsVerified();
        $this->info('Local account created. No business permissions were granted.');

        return self::SUCCESS;
    }
}
