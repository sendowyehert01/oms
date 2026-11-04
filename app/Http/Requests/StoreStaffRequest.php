<?php

namespace App\Http\Requests;

use App\Actions\Fortify\CreateNewUser;
use Illuminate\Foundation\Http\FormRequest;

class StoreStaffRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-staff') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return app(CreateNewUser::class)->rules();
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        $name = $this->input('name');
        $this->merge([
            'email' => is_string($email) ? mb_strtolower(trim($email)) : $email,
            'name' => is_string($name) ? trim($name) : $name,
        ]);
    }
}
