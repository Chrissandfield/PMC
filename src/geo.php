<?php
declare(strict_types=1);

/*
 * Postcode search ("Find near me"). Uses the free postcodes.io service to turn UK postcodes into map points.
 * The postcode a visitor types is sent there to be looked up and is NOT stored by us. Clubs' postcodes are
 * looked up once and saved against the club (clubs.lat, clubs.lng).
 */

function normalise_postcode(string $pc): ?string
{
    $pc = strtoupper(preg_replace('/\s+/', '', trim($pc)));
    if (!preg_match('/^[A-Z]{1,2}[0-9][A-Z0-9]?[0-9][A-Z]{2}$/', $pc)) { return null; }
    return substr($pc, 0, -3) . ' ' . substr($pc, -3);
}

/** Look up many postcodes in one call. Returns ['TN11 8DG' => [lat, lng], ...] for those found. */
function geocode_postcodes(array $postcodes): array
{
    $postcodes = array_values(array_unique($postcodes));
    if (!$postcodes) { return []; }
    if (getenv('PMC_GEO_FAKE') === '1') {
        $fake = ['TN11 8DG' => [51.1966, 0.2077], 'TN1 1AA' => [51.1320, 0.2630], 'CT1 2AA' => [51.2800, 1.0800]];
        return array_intersect_key($fake, array_flip($postcodes));
    }
    $ch = curl_init('https://api.postcodes.io/postcodes');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['postcodes' => array_slice($postcodes, 0, 100)])]);
    $body = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    if ($status !== 200 || !is_array($json['result'] ?? null)) { return []; }
    $out = [];
    foreach ($json['result'] as $r) {
        $q = normalise_postcode((string) ($r['query'] ?? ''));
        if ($q !== null && isset($r['result']['latitude'], $r['result']['longitude'])) { $out[$q] = [(float) $r['result']['latitude'], (float) $r['result']['longitude']]; }
    }
    return $out;
}

function distance_miles(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 3958.8; $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
    $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

/**
 * Add 'miles' to each club and sort nearest first. Looks up (and saves) any club missing a map point.
 * Returns [clubs, message|null]. On any failure the clubs come back in their normal order with a message.
 */
function clubs_near(array $clubs, string $typed, ?float $maxMiles): array
{
    $pc = normalise_postcode($typed);
    if ($pc === null) { return [$clubs, 'That does not look like a UK postcode. Try something like TN11 8XX.']; }
    $need = [$pc => true];
    foreach ($clubs as $c) {
        if ($c['lat'] === null && !empty($c['postcode']) && ($n = normalise_postcode((string) $c['postcode'])) !== null) { $need[$n] = true; }
    }
    $found = geocode_postcodes(array_keys($need));
    if (!isset($found[$pc])) { return [$clubs, 'We could not look up that postcode. Check it, or try again in a moment.']; }
    foreach ($clubs as &$c) {
        if ($c['lat'] === null && !empty($c['postcode'])) {
            $n = normalise_postcode((string) $c['postcode']);
            if ($n !== null && isset($found[$n])) {
                [$c['lat'], $c['lng']] = $found[$n];
                q('UPDATE clubs SET lat = ?, lng = ? WHERE id = ?', [$c['lat'], $c['lng'], $c['id']]);
            }
        }
        $c['miles'] = ($c['lat'] !== null) ? distance_miles($found[$pc][0], $found[$pc][1], (float) $c['lat'], (float) $c['lng']) : null;
    }
    unset($c);
    if ($maxMiles !== null) { $clubs = array_values(array_filter($clubs, fn($c) => $c['miles'] !== null && $c['miles'] <= $maxMiles)); }
    usort($clubs, fn($a, $b) => ($a['miles'] ?? 1e9) <=> ($b['miles'] ?? 1e9));
    return [$clubs, null];
}
