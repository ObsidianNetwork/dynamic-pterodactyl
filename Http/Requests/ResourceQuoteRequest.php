<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ResourceQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'config_options' => ['present', 'array', 'max:50'],
            'cart_item_id' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.PHP_INT_MAX,
            ],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The resource quote request is invalid.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
