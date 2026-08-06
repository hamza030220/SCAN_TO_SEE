<?php

namespace App\Service;

final class StripeUpgradePaymentVerifier
{
    public function upgradeWasPaid(\Stripe\Subscription $subscription, string $expectedPriceId): bool
    {
        $pendingUpdate = $subscription->pending_update ?? null;
        $priceId = $subscription->items->data[0]->price->id ?? null;

        return $pendingUpdate === null
            && is_string($priceId)
            && hash_equals($expectedPriceId, $priceId);
    }

    public function trustedHostedInvoiceUrl(mixed $invoice): ?string
    {
        $url = is_object($invoice) ? ($invoice->hosted_invoice_url ?? null) : null;
        if (!is_string($url) || $url === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($scheme !== 'https' || ($host !== 'stripe.com' && !str_ends_with($host, '.stripe.com'))) {
            return null;
        }

        return $url;
    }
}
