<?php

declare(strict_types=1);

namespace App\Http\Requests\Brain;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/brain/files/{path} — a human edit. `base_version` is the head the
 * editor loaded; a stale one is answered with 409 {current_version}.
 */
class UpdateBrainFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'content' => ['present', 'string', 'max:1000000'],
            'frontmatter' => ['sometimes', 'nullable', 'array'],
            'base_version' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'change_note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, mixed> */
    public function frontmatterInput(): array
    {
        $fm = $this->input('frontmatter');

        return is_array($fm) ? $fm : [];
    }

    public function baseVersion(): ?int
    {
        $base = $this->input('base_version');

        return $base === null || $base === '' ? null : (int) $base;
    }
}
