<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class SetWhatsAppAdministrator extends Command
{
    protected $signature = 'whatsapp:admin {email : User email} {--create : Create a new account with an interactive password prompt} {--revoke : Remove administrator access}';

    protected $description = 'Provision, grant or revoke WhatsApp administrator access';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->error('Invalid email address.');

            return self::FAILURE;
        }
        $user = User::query()->where('email', $email)->first();
        if (! $user && $this->option('create') && ! $this->option('revoke') && $this->input->isInteractive()) {
            $name = $this->ask('Name');
            $password = $this->secret('Password');
            $confirmation = $this->secret('Confirm password');
            $validator = Validator::make(['name' => $name, 'password' => $password, 'password_confirmation' => $confirmation], [
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
            ]);
            if ($validator->fails()) {
                $this->error($validator->errors()->first());

                return self::FAILURE;
            }
            $user = User::query()->create(['email' => $email, 'name' => $name, 'password' => $password]);
        }
        if (! $user) {
            $this->error('User not found. Use --create interactively to provision the account.');

            return self::FAILURE;
        }
        $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();
        $this->info('Administrator access updated.');

        return self::SUCCESS;
    }
}
