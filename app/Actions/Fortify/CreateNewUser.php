<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\UserRole;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create an account with an explicitly assigned role.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input, UserRole $role = UserRole::Staff): User
    {
        $input['email'] = is_string($input['email'] ?? null) ? mb_strtolower(trim($input['email'])) : ($input['email'] ?? null);
        $input['name'] = is_string($input['name'] ?? null) ? trim($input['name']) : ($input['name'] ?? null);
        $validated = Validator::make($input, $this->rules())->validate();

        $user = new User([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
            'password_confirmation' => ['required', 'string'],
        ];
    }
}
