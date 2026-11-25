<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateReplyToRequest extends FormRequest
{
    /**
     * Matches CR, LF, and NUL. These characters let a value inject extra mail headers.
     */
    private const string HEADER_BREAK_PATTERN = '/[\r\n\0]/';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reply_to_email' => ['nullable', 'required_with:reply_to_name', 'string', 'max:255', 'not_regex:'.self::HEADER_BREAK_PATTERN, 'email'],
            'reply_to_name' => ['nullable', 'string', 'max:255', 'not_regex:'.self::HEADER_BREAK_PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reply_to_email.required_with' => 'Add an email address to use a Reply-To name.',
            'reply_to_email.not_regex' => 'The email cannot contain line breaks.',
            'reply_to_name.not_regex' => 'The name cannot contain line breaks.',
        ];
    }

    /**
     * The validated Reply-To values. An empty email and name clear both fields.
     *
     * @return array{reply_to_email: ?string, reply_to_name: ?string}
     */
    public function replyTo(): array
    {
        $email = $this->validated('reply_to_email');

        if (blank($email)) {
            return ['reply_to_email' => null, 'reply_to_name' => null];
        }

        $name = $this->validated('reply_to_name');

        return [
            'reply_to_email' => trim($email),
            'reply_to_name' => blank($name) ? null : trim($name),
        ];
    }
}
