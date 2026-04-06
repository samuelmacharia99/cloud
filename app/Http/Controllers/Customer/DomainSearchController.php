<?php

namespace App\Http\Controllers\Customer;

use App\Models\DomainExtension;
use App\Models\DomainPricing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Http\Controllers\Controller;

class DomainSearchController extends Controller
{
    // Using WHOIS-based availability check
    protected const WHOIS_TIMEOUT = 5;

    public function search(Request $request)
    {
        $query = $request->get('q', '');
        $results = [];
        $searchQuery = $query;

        if (empty($query)) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please enter a domain name to search',
                    'results' => [],
                ]);
            }
            return view('public.domain-search', ['results' => [], 'query' => '']);
        }

        // Clean up the query - remove www and extract domain name
        $query = str_replace(['www.', 'https://', 'http://'], '', strtolower($query));
        $query = trim($query);

        // If user included TLD (full domain), search only that domain
        if (strpos($query, '.') !== false) {
            [$domainName, $tld] = explode('.', $query, 2);
            $extension = DomainExtension::where('extension', '.' . $tld)->first();

            if ($extension) {
                $available = $this->checkAvailability($query);
                $pricing = $extension->getRetailPricing(1);
                $price = $pricing ? (float) $pricing->price : 0;

                $results[] = [
                    'domain' => $domainName,
                    'extension' => '.' . $tld,
                    'full_domain' => $query,
                    'available' => $available,
                    'price' => $price,
                    'currency' => 'KES',
                    'years' => [1, 2, 3, 5],
                ];
            }
        } else {
            // Search across all enabled extensions
            $extensions = DomainExtension::where('enabled', true)->get();

            foreach ($extensions as $ext) {
                if (!$ext) continue;

                $fullDomain = $query . $ext->extension;
                $available = $this->checkAvailability($fullDomain);

                $pricing = $ext->getRetailPricing(1);
                $price = $pricing ? (float) $pricing->price : 0;

                $results[] = [
                    'domain' => $query,
                    'extension' => $ext->extension,
                    'full_domain' => $fullDomain,
                    'available' => $available,
                    'price' => $price,
                    'currency' => 'KES',
                    'years' => [1, 2, 3, 5],
                ];
            }
        }

        // Sort available domains first
        usort($results, fn($a, $b) => $b['available'] <=> $a['available']);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'results' => $results,
            ]);
        }

        return view('public.domain-search', ['results' => $results, 'query' => $query, 'searchQuery' => $searchQuery]);
    }

    /**
     * Check domain availability via WHOIS lookup
     * Returns true if domain appears to be available, false if taken or error
     */
    private function checkAvailability(string $domain): bool
    {
        try {
            // Use whois.verisign.com for generic TLDs, nsec.online for others
            $tld = substr($domain, strrpos($domain, '.') + 1);

            $whoisServers = [
                'com' => 'whois.verisign.com',
                'net' => 'whois.verisign.com',
                'org' => 'whois.pir.org',
                'co' => 'whois.nic.co',
                'io' => 'whois.nic.io',
                'ke' => 'whois.kenic.or.ke',
            ];

            $whoisServer = $whoisServers[$tld] ?? 'whois.nic.' . $tld;

            // Try socket connection for WHOIS
            $conn = @fsockopen($whoisServer, 43, $errno, $errstr, self::WHOIS_TIMEOUT);

            if (!$conn) {
                // If we can't reach WHOIS, use DNS check as fallback
                return $this->checkDNS($domain);
            }

            // Send WHOIS query
            fwrite($conn, "$domain\r\n");
            $response = '';
            while (!feof($conn)) {
                $response .= fgets($conn, 128);
            }
            fclose($conn);

            // Check for "No Found" or "No Object" strings that indicate availability
            // Different registries use different messages
            $notFoundPatterns = [
                'no found',
                'no data found',
                'no match',
                'not found',
                'object does not exist',
                'domain status: no object',
                'no entries found',
            ];

            $response_lower = strtolower($response);
            foreach ($notFoundPatterns as $pattern) {
                if (strpos($response_lower, $pattern) !== false) {
                    return true; // Available
                }
            }

            return false; // Taken (found in WHOIS)
        } catch (\Exception $e) {
            // Fallback: try DNS check
            return $this->checkDNS($domain);
        }
    }

    /**
     * Fallback: Check if domain resolves via DNS (if it resolves, it's taken)
     */
    private function checkDNS(string $domain): bool
    {
        try {
            // If DNS resolves, domain is taken. If not, it's available.
            $ip = gethostbyname($domain);
            // If gethostbyname returns the domain itself, DNS failed (available)
            return $ip === $domain;
        } catch (\Exception $e) {
            // On error, assume taken to be safe
            return false;
        }
    }
}
