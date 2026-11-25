<?php

namespace App\Http\Requests\Settings;

use App\Actions\DeleteUser;
use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }

    /**
     * Refuse the delete before the controller logs the user out: the instance
     * owner and the only admin of a team keep the account.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(DeleteUser $deleteUser): array
    {
        return [
            function (Validator $validator) use ($deleteUser): void {
                if ($validator->errors()->has('password')) {
                    return;
                }

                try {
                    $deleteUser->successorFor($this->user());
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $key => $messages) {
                        $validator->errors()->add($key, $messages[0]);
                    }
                }
            },
        ];
    }
}
