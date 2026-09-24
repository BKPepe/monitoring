<?php
/**
 * Where the Cloudflare Worker ran: its colo map and the label it posts.
 *
 * The Worker (agents/cloudflare-agent.js) labels its checks with the city AND
 * the country of the colo that ran it. Before agents e13a2d4 it took the
 * country from the trace's `loc`, which inside a Worker is its own egress IP
 * (US wherever it runs), so the history holds "🇺🇸 Mumbai, US (AS13335
 * Cloudflare)". db.php rewrites those with the map below.
 *
 * This is the server's only copy of the map. It mirrors COLO in the Worker;
 * tests/run_tests.php reads the Worker source and fails when the two differ,
 * so a colo added there has to be added here as well.
 */

// Included only (db.php); requested directly it has nothing to show.
if (count(get_included_files()) === 1) {
    http_response_code(404);
    exit;
}

/** IATA colo code => [city, ISO country], exactly as in the Worker's COLO. */
function bk_cf_colos(): array {
    return [
        'AMS' => ['Amsterdam', 'NL'],
        'ARN' => ['Stockholm', 'SE'],
        'ATL' => ['Atlanta', 'US'],
        'BCN' => ['Barcelona', 'ES'],
        'BEG' => ['Belgrade', 'RS'],
        'BER' => ['Berlin', 'DE'],
        'BKK' => ['Bangkok', 'TH'],
        'BOM' => ['Mumbai', 'IN'],
        'BRU' => ['Brussels', 'BE'],
        'BUH' => ['Bucharest', 'RO'],
        'CDG' => ['Paris', 'FR'],
        'CWB' => ['Curitiba', 'BR'],
        'DEL' => ['New Delhi', 'IN'],
        'DFW' => ['Dallas', 'US'],
        'DUB' => ['Dublin', 'IE'],
        'DUS' => ['Düsseldorf', 'DE'],
        'EWR' => ['Newark', 'US'],
        'EZE' => ['Buenos Aires', 'AR'],
        'FCO' => ['Rome', 'IT'],
        'FRA' => ['Frankfurt', 'DE'],
        'GIG' => ['Rio de Janeiro', 'BR'],
        'GRU' => ['São Paulo', 'BR'],
        'HAM' => ['Hamburg', 'DE'],
        'HKG' => ['Hong Kong', 'HK'],
        'IAD' => ['Washington DC', 'US'],
        'IAH' => ['Houston', 'US'],
        'ICN' => ['Seoul', 'KR'],
        'IST' => ['Istanbul', 'TR'],
        'JNB' => ['Johannesburg', 'ZA'],
        'KHI' => ['Karachi', 'PK'],
        'KIX' => ['Osaka', 'JP'],
        'LAX' => ['Los Angeles', 'US'],
        'LHR' => ['London', 'GB'],
        'LIM' => ['Lima', 'PE'],
        'LIS' => ['Lisbon', 'PT'],
        'MAA' => ['Chennai', 'IN'],
        'MAD' => ['Madrid', 'ES'],
        'MAN' => ['Manchester', 'GB'],
        'MEL' => ['Melbourne', 'AU'],
        'MEX' => ['Mexico City', 'MX'],
        'MIA' => ['Miami', 'US'],
        'MNL' => ['Manila', 'PH'],
        'MRS' => ['Marseille', 'FR'],
        'MUC' => ['Munich', 'DE'],
        'NRT' => ['Tokyo', 'JP'],
        'ORD' => ['Chicago', 'US'],
        'OSL' => ['Oslo', 'NO'],
        'OTP' => ['Bucharest', 'RO'],
        'PHX' => ['Phoenix', 'US'],
        'PNQ' => ['Pune', 'IN'],
        'PRG' => ['Prague', 'CZ'],
        'QRO' => ['Queretaro', 'MX'],
        'RUH' => ['Riyadh', 'SA'],
        'SCL' => ['Santiago', 'CL'],
        'SEA' => ['Seattle', 'US'],
        'SFO' => ['San Francisco', 'US'],
        'SIN' => ['Singapore', 'SG'],
        'SJC' => ['San Jose', 'US'],
        'SLC' => ['Salt Lake City', 'US'],
        'SOF' => ['Sofia', 'BG'],
        'SYD' => ['Sydney', 'AU'],
        'TLV' => ['Tel Aviv', 'IL'],
        'TPE' => ['Taipei', 'TW'],
        'TXL' => ['Berlin', 'DE'],
        'VIE' => ['Vienna', 'AT'],
        'WAW' => ['Warsaw', 'PL'],
        'YUL' => ['Montreal', 'CA'],
        'YVR' => ['Vancouver', 'CA'],
        'YYZ' => ['Toronto', 'CA'],
        'ZRH' => ['Zürich', 'CH'],
    ];
}

/**
 * The Worker's countryFlag(): two regional indicator letters, the neutral
 * globe for anything that is not a two-letter code. (The Worker only checks
 * the length; every code it can pass is two letters, the test holds that.)
 */
function bk_cf_country_flag(string $cc): string {
    if (!preg_match('/^[A-Za-z]{2}$/', $cc)) {
        return '🌐';
    }
    $cc = strtoupper($cc);
    return mb_chr(0x1F1E6 + ord($cc[0]) - 65, 'UTF-8') . mb_chr(0x1F1E6 + ord($cc[1]) - 65, 'UTF-8');
}

/** The label the Worker posts for a colo: "🇩🇪 Frankfurt, DE (AS13335 Cloudflare)". */
function bk_cf_location_label(string $city, string $cc): string {
    $geo = implode(', ', array_filter([$city, $cc], fn(string $part): bool => $part !== ''));
    return bk_cf_country_flag($cc) . ' ' . $geo . ' (AS13335 Cloudflare)';
}

/** The Worker's EDGE_UNKNOWN: the trace named no colo, so no place is claimed. */
const BK_CF_EDGE_UNKNOWN = '🌐 Cloudflare Edge (AS13335 Cloudflare)';

/**
 * What a stored Worker label says today, or null when it stays as it is.
 *
 * "<flag> <city>[, <CC>] (AS13335 Cloudflare)" is rebuilt the way the Worker
 * builds it now: a city in the map (or a colo code the map now knows - SLC
 * and TXL were posted as codes) gets the map's country, a code the map does
 * not know keeps the code under a globe, and a label with no colo at all
 * ("🇺🇸 US") becomes BK_CF_EDGE_UNKNOWN. Other providers, cities the map does
 * not know and labels that are already right give null.
 */
function bk_cf_corrected_label(string $label): ?string {
    if (!preg_match('/^(?:🌐|[\x{1F1E6}-\x{1F1FF}]{2}) (.*) \(AS13335 Cloudflare\)$/u', $label, $m)) {
        return null;
    }
    if ($m[1] === '' || preg_match('/^[A-Z]{2}$/', $m[1])) {
        return BK_CF_EDGE_UNKNOWN;
    }
    $city = preg_match('/^(.+), [A-Z]{2}$/u', $m[1], $g) ? $g[1] : $m[1];
    $colos = bk_cf_colos();
    if (isset($colos[$city])) {
        [$city, $cc] = $colos[$city];
    } elseif (preg_match('/^[A-Z]{3}$/', $city)) {
        $cc = '';
    } else {
        $cc = null;
        foreach ($colos as [$colo_city, $colo_cc]) {
            if ($colo_city === $city) {
                $cc = $colo_cc;
                break;
            }
        }
        if ($cc === null) {
            return null;
        }
    }
    $fixed = bk_cf_location_label($city, $cc);
    return $fixed === $label ? null : $fixed;
}
