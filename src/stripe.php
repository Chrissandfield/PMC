<?php
declare(strict_types=1);

/*
 * Stripe Connect helpers (plain PHP, no libraries).
 * Model: each club has its own Express connected account. Customers pay the club directly
 * (a "direct charge"). Stripe takes its card fee from the club, and PlayMoreCricket's commission
 * is added as an application fee. The secret key and webhook secret live in environment
 * variables on the host (PMC_STRIPE_SECRET, PMC_STRIPE_WEBHOOK_SECRET, or config.local.php on the server), never in the code.
 */

function stripe_secret(): string
{
    $k = $GLOBALS['cfg']['stripe_secret'] ?? '';
    if ($k === '') { throw new RuntimeException('Stripe secret key is not set.'); }
    return $k;
}

/** Commission in pence for a price, honouring a "free until" date. Rounded to the nearest penny. */
function commission_pence(int $pricePence, float $pct, ?string $freeUntil, ?DateTimeImmutable $today = null): int
{
    $today ??= new DateTimeImmutable('today', new DateTimeZone('Europe/London'));
    if ($freeUntil !== null && $freeUntil !== '' && $today <= new DateTimeImmutable($freeUntil, new DateTimeZone('Europe/London'))) {
        return 0;
    }
    $pct = max(0.0, min(100.0, $pct));
    return (int) round($pricePence * $pct / 100);
}

/** Flatten nested arrays into Stripe's form encoding: a[b][0]=c */
function stripe_encode(array $data, string $prefix = ''): array
{
    $out = [];
    foreach ($data as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix . '[' . $k . ']';
        if (is_array($v)) { $out += stripe_encode($v, $key); }
        elseif (is_bool($v)) { $out[$key] = $v ? 'true' : 'false'; }
        elseif ($v !== null) { $out[$key] = (string) $v; }
    }
    return $out;
}

/** Call the Stripe API. $account = connected account id for direct charges. */
function stripe_call(string $method, string $path, array $data = [], ?string $account = null, ?string $idempotencyKey = null): array
{
    $headers = ['Authorization: Bearer ' . stripe_secret()];
    if ($account !== null) { $headers[] = 'Stripe-Account: ' . $account; }
    if ($idempotencyKey !== null) { $headers[] = 'Idempotency-Key: ' . $idempotencyKey; }
    $ch = curl_init('https://api.stripe.com' . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($data) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(stripe_encode($data))); }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false) { $e = curl_error($ch); curl_close($ch); throw new RuntimeException('Stripe unreachable: ' . $e); }
    curl_close($ch);
    $json = json_decode($body, true);
    if (!is_array($json) || $status >= 400) {
        throw new RuntimeException('Stripe error ' . $status . ': ' . ($json['error']['message'] ?? substr((string) $body, 0, 200)));
    }
    return $json;
}

/** Create the club's Express account (Stripe collects its own fees; Stripe covers negative balances). */
function stripe_create_club_account(array $club): array
{
    return stripe_call('POST', '/v1/accounts', [
        'country' => 'GB',
        'email'   => $club['email'],
        'controller' => [
            'fees'              => ['payer' => 'account'],
            'losses'            => ['payments' => 'stripe'],
            'stripe_dashboard'  => ['type' => 'express'],
            'requirement_collection' => 'stripe',
        ],
        'capabilities' => ['card_payments' => ['requested' => true], 'transfers' => ['requested' => true]],
        'business_profile' => ['name' => $club['name'], 'url' => $GLOBALS['cfg']['app_url'] . '/club/' . $club['slug']],
        'metadata' => ['club_id' => $club['id'], 'club_slug' => $club['slug']],
    ], null, 'club-account-' . $club['id']);
}

/** A one-off link that sends the club's contact to Stripe's sign-up form. */
function stripe_account_link(string $accountId, string $returnUrl, string $refreshUrl): string
{
    $r = stripe_call('POST', '/v1/account_links', [
        'account' => $accountId, 'type' => 'account_onboarding',
        'return_url' => $returnUrl, 'refresh_url' => $refreshUrl,
    ]);
    return (string) $r['url'];
}

/** Hosted payment page for one booking (direct charge to the club, commission as application fee). */
function stripe_checkout_session(array $booking, string $accountId, string $description, string $successUrl, string $cancelUrl): array
{
    $params = [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url'  => $cancelUrl,
        'customer_email' => $booking['customer_email'],
        'client_reference_id' => $booking['reference'],
        'expires_at' => time() + 30 * 60,                    // Stripe's minimum is 30 minutes
        'line_items' => [[
            'quantity' => 1,
            'price_data' => ['currency' => 'gbp', 'unit_amount' => (int) $booking['price_pence'],
                             'product_data' => ['name' => $description]],
        ]],
        'metadata' => ['booking_reference' => $booking['reference']],
        'payment_intent_data' => ['metadata' => ['booking_reference' => $booking['reference']]],
    ];
    if ((int) $booking['commission_pence'] > 0) {
        $params['payment_intent_data']['application_fee_amount'] = (int) $booking['commission_pence'];
    }
    return stripe_call('POST', '/v1/checkout/sessions', $params, $accountId, 'checkout-' . $booking['reference']);
}

/** Refund a payment on the club's account, giving back our commission pro rata. */
function stripe_refund(string $paymentIntentId, string $accountId, string $reference): array
{
    return stripe_call('POST', '/v1/refunds', [
        'payment_intent' => $paymentIntentId, 'refund_application_fee' => true,
        'metadata' => ['booking_reference' => $reference],
    ], $accountId, 'refund-' . $reference);
}

/**
 * Check a webhook really came from Stripe. Returns the decoded event or null.
 * $tolerance is how many seconds old a signature may be.
 */
function stripe_verify_webhook(string $payload, string $header, string $secret, int $tolerance = 300, ?int $now = null): ?array
{
    $t = null; $sigs = [];
    foreach (explode(',', $header) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) { continue; }
        if ($kv[0] === 't') { $t = (int) $kv[1]; }
        if ($kv[0] === 'v1') { $sigs[] = $kv[1]; }
    }
    if ($t === null || !$sigs || abs(($now ?? time()) - $t) > $tolerance) { return null; }
    $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
    foreach ($sigs as $s) {
        if (hash_equals($expected, $s)) {
            $e = json_decode($payload, true);
            return is_array($e) ? $e : null;
        }
    }
    return null;
}

/** Cancellation rule: under 24 hours before the session starts, the full fee is kept. */
function refund_allowed(DateTimeImmutable $startsAtUtc, ?DateTimeImmutable $nowUtc = null): bool
{
    $nowUtc ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return ($startsAtUtc->getTimestamp() - $nowUtc->getTimestamp()) >= 24 * 3600;
}
