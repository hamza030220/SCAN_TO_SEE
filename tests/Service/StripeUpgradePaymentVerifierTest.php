<?php

namespace App\Tests\Service;

use App\Service\StripeUpgradePaymentVerifier;
use PHPUnit\Framework\TestCase;
use Stripe\Invoice;
use Stripe\Subscription;

final class StripeUpgradePaymentVerifierTest extends TestCase
{
    private StripeUpgradePaymentVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new StripeUpgradePaymentVerifier();
    }

    public function testPaidAppliedPriceIsAccepted(): void
    {
        $subscription = Subscription::constructFrom([
            'items' => ['data' => [['price' => ['id' => 'price_pro']]]],
            'pending_update' => null,
        ]);

        self::assertTrue($this->verifier->upgradeWasPaid($subscription, 'price_pro'));
    }

    public function testPendingPaymentNeverUnlocksUpgrade(): void
    {
        $subscription = Subscription::constructFrom([
            'items' => ['data' => [['price' => ['id' => 'price_pro']]]],
            'pending_update' => ['expires_at' => time() + 3600],
        ]);

        self::assertFalse($this->verifier->upgradeWasPaid($subscription, 'price_pro'));
    }

    public function testDifferentAppliedPriceIsRejected(): void
    {
        $subscription = Subscription::constructFrom([
            'items' => ['data' => [['price' => ['id' => 'price_basic']]]],
            'pending_update' => null,
        ]);

        self::assertFalse($this->verifier->upgradeWasPaid($subscription, 'price_pro'));
    }

    public function testOnlyStripeHostedInvoiceUrlsAreAccepted(): void
    {
        $stripeInvoice = Invoice::constructFrom([
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_test/inv_test',
        ]);
        $untrustedInvoice = Invoice::constructFrom([
            'hosted_invoice_url' => 'https://stripe.com.attacker.example/pay',
        ]);

        self::assertSame(
            'https://invoice.stripe.com/i/acct_test/inv_test',
            $this->verifier->trustedHostedInvoiceUrl($stripeInvoice),
        );
        self::assertNull($this->verifier->trustedHostedInvoiceUrl($untrustedInvoice));
    }
}
