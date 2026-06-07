<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'in:Bug,Confusing,Feature Idea,General Feedback'],
            'message'  => ['required', 'string', 'min:10', 'max:2000'],
            'email'    => ['nullable', 'email', 'max:255'],
            'discord'  => ['nullable', 'string', 'max:100'],
            'screenshot_url' => ['nullable', 'url', 'max:500'],
        ];
    }
}
