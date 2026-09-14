<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A list of container-relative paths (batch delete, download as zip).
 */
class BatchContainerPathsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public static function pathRules(): array
    {
        return [
            'required',
            'string',
            'max:500',
            'regex:/^\//',
            'not_regex:/\.\./',
            'not_regex:/[\x00-\x1F\x7F]/',
        ];
    }

    public function rules(): array
    {
        return [
            'paths' => ['required', 'array', 'min:1', 'max:'.self::maxItems()],
            'paths.*' => self::pathRules(),
        ];
    }

    public function messages(): array
    {
        return [
            'paths.required' => 'Select at least one item.',
            'paths.max' => 'At most '.self::maxItems().' items can be handled at once.',
            'paths.*.regex' => 'Every path must start with "/"',
            'paths.*.not_regex' => 'A path contains invalid characters or traversal segments',
        ];
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_values(array_unique(array_map('strval', (array) $this->validated('paths'))));
    }

    public static function maxItems(): int
    {
        return max(1, (int) config('containers.file_manager.max_batch_items', 500));
    }

    public static function normalizePath(mixed $path): mixed
    {
        if (! is_string($path) || $path === '') {
            return $path;
        }

        $normalized = '/'.ltrim($path, '/');

        return preg_replace('#/+#', '/', $normalized) ?? $normalized;
    }

    protected function prepareForValidation(): void
    {
        $paths = $this->input('paths');
        if (is_array($paths)) {
            $this->merge(['paths' => array_map([self::class, 'normalizePath'], array_values($paths))]);
        }
    }
}
