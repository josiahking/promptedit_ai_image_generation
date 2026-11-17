<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:1', 'max:4000'],
            'size' => ['required', Rule::in(['1024x1024', '1536x1024', '1024x1536'])],
            'quality' => ['required', Rule::in(['low', 'medium', 'high'])],
            'reference_images' => ['present', 'array', 'max:4'],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * @return array{prompt:string,size:string,quality:string,reference_images:array<int,string>}
     */
    public function generationInput(): array
    {
        $data = $this->validated();

        /** @var array<int, string> $references */
        $references = $data['reference_images'];

        return [
            'prompt' => $data['prompt'],
            'size' => $data['size'],
            'quality' => $data['quality'],
            'reference_images' => $references,
        ];
    }
}
