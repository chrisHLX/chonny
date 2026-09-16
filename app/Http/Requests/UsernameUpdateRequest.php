<?php

namespace App\Http\Requests;

use App\Models\PreviousUsername;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UsernameUpdateRequest extends FormRequest
{
    /** Handles nobody can take: they read as the site speaking, not a player. */
    public const RESERVED = [
        'admin', 'administrator', 'mindcollector', 'mod', 'moderator', 'staff', 'support',
        'system', 'official', 'help', 'root', 'null', 'undefined', 'player', 'someone', 'you',
    ];

    /** Lowercase and drop a leading "@" before validating — both are what people type. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'username' => mb_strtolower(ltrim(trim((string) $this->input('username')), '@')),
        ]);
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'username' => [
                'required',
                'string',
                'min:3',
                'max:30',
                // Letters, numbers, - and _, starting and ending with a letter or number. It sits in
                // a URL, so nothing that needs escaping.
                'regex:/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/',
                function (string $attribute, string $value, Closure $fail) use ($userId) {
                    if (in_array($value, self::RESERVED, true)) {
                        $fail('That handle is reserved.');

                        return;
                    }

                    $taken = User::whereRaw('LOWER(username) = ?', [$value])->whereKeyNot($userId)->exists()
                        || PreviousUsername::where('username', $value)->where('user_id', '!=', $userId)->exists();

                    if ($taken) {
                        $fail('That handle is taken.');
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'username.regex' => 'Use letters, numbers, - or _, starting and ending with a letter or number.',
        ];
    }
}
