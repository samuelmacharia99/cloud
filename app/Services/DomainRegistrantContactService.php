<?php

namespace App\Services;

use App\Models\User;
use App\Rules\ValidCountryCode;
use App\Support\Countries;
use Illuminate\Support\Arr;

class DomainRegistrantContactService
{
    /**
     * @return array<string, mixed>
     */
    public function rules(string $prefix = 'registrant'): array
    {
        $field = fn (string $key) => $prefix === '' ? $key : $prefix.'.'.$key;

        return [
            $field('first_name') => ['required', 'string', 'min:1', 'max:64'],
            $field('last_name') => ['required', 'string', 'min:1', 'max:64'],
            $field('email') => ['required', 'email', 'max:255'],
            $field('phone') => ['required', 'string', 'min:7', 'max:32'],
            $field('company') => ['nullable', 'string', 'max:128'],
            $field('address1') => ['required', 'string', 'min:3', 'max:128'],
            $field('address2') => ['nullable', 'string', 'max:128'],
            $field('city') => ['required', 'string', 'min:2', 'max:64'],
            $field('state') => ['nullable', 'string', 'max:64'],
            $field('postal_code') => ['nullable', 'string', 'max:16'],
            $field('country') => ['required', 'string', 'size:2', new ValidCountryCode],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     first_name: string,
     *     last_name: string,
     *     email: string,
     *     phone: string,
     *     company: ?string,
     *     address1: string,
     *     address2: ?string,
     *     city: string,
     *     state: ?string,
     *     postal_code: ?string,
     *     country: string
     * }
     */
    public function normalize(array $input): array
    {
        $rawCountry = trim((string) ($input['country'] ?? ''));
        $country = Countries::normalize($rawCountry) ?? strtoupper($rawCountry);

        return [
            'first_name' => trim((string) ($input['first_name'] ?? '')),
            'last_name' => trim((string) ($input['last_name'] ?? '')),
            'email' => strtolower(trim((string) ($input['email'] ?? ''))),
            'phone' => trim((string) ($input['phone'] ?? '')),
            'company' => $this->nullableString($input['company'] ?? null),
            'address1' => trim((string) ($input['address1'] ?? '')),
            'address2' => $this->nullableString($input['address2'] ?? null),
            'city' => trim((string) ($input['city'] ?? '')),
            'state' => $this->nullableString($input['state'] ?? null),
            'postal_code' => $this->nullableString($input['postal_code'] ?? null),
            'country' => $country,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fromUser(User $user): array
    {
        [$first, $last] = $this->splitName((string) $user->name);

        return $this->normalize([
            'first_name' => $first,
            'last_name' => $last,
            'email' => $user->email,
            'phone' => $user->phone,
            'company' => $user->company,
            'address1' => $user->address,
            'city' => $user->city,
            'postal_code' => $user->postal_code,
            'country' => Countries::normalize($user->country) ?? $user->country,
        ]);
    }

    /**
     * @param  array<string, mixed>  $contact
     */
    public function isComplete(array $contact): bool
    {
        $normalized = $this->normalize($contact);

        return $normalized['first_name'] !== ''
            && $normalized['last_name'] !== ''
            && filter_var($normalized['email'], FILTER_VALIDATE_EMAIL) !== false
            && strlen($normalized['phone']) >= 7
            && strlen($normalized['address1']) >= 3
            && strlen($normalized['city']) >= 2
            && Countries::isValidCode($normalized['country']);
    }

    /**
     * Cosmotown /v1/reseller/contactinfo payload for all four roles.
     *
     * @param  array<string, mixed>  $contact
     * @return array{registrant: array<string, string>, administrative: array<string, string>, technical: array<string, string>, billing: array<string, string>}
     */
    public function toCosmotownPayload(array $contact): array
    {
        $normalized = $this->normalize($contact);
        $row = [
            'FirstName' => $normalized['first_name'],
            'LastName' => $normalized['last_name'],
            'Company' => (string) ($normalized['company'] ?? ''),
            'Phone' => $normalized['phone'],
            'Extension' => '',
            'Fax' => '',
            'City' => $normalized['city'],
            'State' => (string) ($normalized['state'] ?? ''),
            'Zip' => (string) ($normalized['postal_code'] ?? ''),
            'Country' => $normalized['country'],
            'Email' => $normalized['email'],
            'Address1' => $normalized['address1'],
            'Address2' => (string) ($normalized['address2'] ?? ''),
        ];

        return [
            'registrant' => $row,
            'administrative' => $row,
            'technical' => $row,
            'billing' => $row,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function fromCosmotownPayload(array $payload): array
    {
        $bags = [$payload];
        foreach (['registrant', 'contact', 'contacts', 'data'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $bags[] = $payload[$key];
            }
        }

        if (isset($payload['registrant']['contacts']['registrant']) && is_array($payload['registrant']['contacts']['registrant'])) {
            $bags[] = $payload['registrant']['contacts']['registrant'];
        }

        $row = [];
        foreach ($bags as $bag) {
            $candidate = $bag['registrant'] ?? $bag;
            if (! is_array($candidate)) {
                continue;
            }
            $row = $candidate;
            if ($this->valueFromBag($candidate, ['FirstName', 'first_name', 'firstName']) !== '') {
                break;
            }
        }

        return $this->normalize([
            'first_name' => $this->valueFromBag($row, ['FirstName', 'first_name', 'firstName']),
            'last_name' => $this->valueFromBag($row, ['LastName', 'last_name', 'lastName']),
            'email' => $this->valueFromBag($row, ['Email', 'email']),
            'phone' => $this->valueFromBag($row, ['Phone', 'phone']),
            'company' => $this->valueFromBag($row, ['Company', 'company']),
            'address1' => $this->valueFromBag($row, ['Address1', 'address1', 'address']),
            'address2' => $this->valueFromBag($row, ['Address2', 'address2']),
            'city' => $this->valueFromBag($row, ['City', 'city']),
            'state' => $this->valueFromBag($row, ['State', 'state']),
            'postal_code' => $this->valueFromBag($row, ['Zip', 'zip', 'postal_code']),
            'country' => $this->valueFromBag($row, ['Country', 'country']),
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function splitName(string $name): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        if ($name === '') {
            return ['', ''];
        }

        $parts = explode(' ', $name, 2);

        return [
            $parts[0],
            isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : $parts[0],
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $bag
     * @param  list<string>  $keys
     */
    private function valueFromBag(array $bag, array $keys): string
    {
        foreach ($keys as $key) {
            $value = Arr::get($bag, $key);
            if (is_string($value) || is_numeric($value)) {
                $trimmed = trim((string) $value);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }

        return '';
    }
}
