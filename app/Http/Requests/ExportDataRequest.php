<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExportDataRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('export-data') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'date_from.required' => 'Choose a Date from.',
            'date_to.required' => 'Choose a Date to.',
            'date_from.date_format' => 'Date from must be a valid date.',
            'date_to.date_format' => 'Date to must be a valid date.',
            'date_to.after_or_equal' => 'Date to must be on or after Date from.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->errorBag = $this->route('type');
    }

    protected function getRedirectUrl(): string
    {
        return route('exports.index');
    }
}
