<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class TopicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules of a topic. The web forms and the API use
     * them. A name is unique in its list. The web routes name the route
     * parameters `list` and `topic`, and the API routes name them `listId`
     * and `topicId`. A create request has no topic. Scramble calls this
     * method with no user when it builds the API documentation.
     *
     * @return array<string, array<int, Unique|string>>
     */
    public function rules(): array
    {
        $listId = (int) ($this->route('listId') ?? $this->route('list'));
        $topicId = $this->route('topicId') ?? $this->route('topic');
        $clientId = $this->user()->client_id ?? 0;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('topics', 'name')
                    ->where('list_id', $listId)
                    ->where('client_id', $clientId)
                    ->ignore($topicId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
