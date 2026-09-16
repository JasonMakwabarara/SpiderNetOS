<?php

declare(strict_types=1);

namespace App\Http\Requests\Brain;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/brain/files/{path}/revert {version} */
class RevertBrainFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
