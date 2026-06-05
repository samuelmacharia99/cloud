<?php

namespace App\Services;

class DomainInputParser
{
    /**
     * Split a domain label or FQDN plus optional extension into registrable parts.
     *
     * @param  array<int, string>  $allowedExtensions
     * @return array{name: string, extension: string}|null
     */
    public function parse(string $nameInput, ?string $extensionInput, array $allowedExtensions): ?array
    {
        $raw = $this->normalizeRaw($nameInput);

        if ($raw === '') {
            return null;
        }

        $extensions = $this->normalizeExtensions($allowedExtensions);

        if (str_contains($raw, '.')) {
            foreach ($extensions as $extension) {
                if (! str_ends_with($raw, $extension)) {
                    continue;
                }

                $name = rtrim(substr($raw, 0, -strlen($extension)), '.');

                if ($this->isValidLabel($name)) {
                    return [
                        'name' => $name,
                        'extension' => $extension,
                    ];
                }
            }
        }

        $extension = $this->normalizeExtension($extensionInput);

        if ($extension && in_array($extension, $extensions, true) && $this->isValidLabel($raw)) {
            return [
                'name' => $raw,
                'extension' => $extension,
            ];
        }

        return null;
    }

    private function normalizeRaw(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/^https?:\/\//', '', $value) ?? $value;
        $value = explode('/', $value)[0] ?? $value;

        return rtrim($value, '.');
    }

    /**
     * @param  array<int, string>  $allowedExtensions
     * @return array<int, string>
     */
    private function normalizeExtensions(array $allowedExtensions): array
    {
        return collect($allowedExtensions)
            ->map(fn (string $extension) => $this->normalizeExtension($extension) ?? '')
            ->filter()
            ->unique()
            ->sortByDesc(fn (string $extension) => strlen($extension))
            ->values()
            ->all();
    }

    private function normalizeExtension(?string $extension): ?string
    {
        if ($extension === null || trim($extension) === '') {
            return null;
        }

        $extension = strtolower(trim($extension));

        return str_starts_with($extension, '.') ? $extension : '.'.$extension;
    }

    private function isValidLabel(string $label): bool
    {
        if ($label === '' || str_starts_with($label, '-') || str_ends_with($label, '-')) {
            return false;
        }

        return (bool) preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label);
    }
}
