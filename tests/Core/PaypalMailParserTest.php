<?php declare(strict_types=1);

namespace TaxApiPaypal\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxApiPaypal\Core\PaypalMailParser;
use Taxmod\Core\Import\Mail\MimeParser;

/**
 * The sample mails are synthetic, laid out like four real PayPal mails of October 2026 (receipt, authorisation, eBay
 * authorisation); refund and notice mails have no real sample yet.
 */
final class PaypalMailParserTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function read(string $fixture): array
    {
        $parser = new PaypalMailParser('0.0.1', static fn (): array => ['eBay', 'AliExpress', 'JLCPCB']);
        $mail = (new MimeParser())->parse(1, (string) file_get_contents(__DIR__ . '/fixtures/' . $fixture . '.eml'));
        self::assertTrue($parser->recognises($mail), 'not recognised: ' . $fixture);

        return $parser->parse($mail)->toArray();
    }

    public function testAReceiptBecomesAnOrderNumberedByItsTransactionCode(): void
    {
        $order = self::read('p1');

        self::assertSame(['PayPal', '4AB00000CD0000001', 'ordered', '2026-10-18'], [$order['platform'], $order['order_number'], $order['kind'], $order['order_date']]);
        self::assertSame(['Bastelladen GmbH', '30.44', '3.99', 'EUR'], [$order['counterparty'], $order['total'], $order['shipping_cost'], $order['currency']]);
        self::assertSame(['Lötzinn 0,5 mm 100 g'], array_column($order['lines'], 'title'));
        self::assertSame([5.0], array_column($order['lines'], 'quantity'));
        self::assertSame(['5.29'], array_column($order['lines'], 'unit_price'));
        self::assertSame('mail', $order['source']);
    }

    public function testAPaymentToAShopWithItsOwnModuleIsLeftToThatModule(): void
    {
        self::assertSame('no_receipt', self::read('p2')['kind']);
        self::assertSame('no_receipt', self::read('p4')['kind']);
    }

    public function testTheSameReceiptIsAnOrderWhenNoModuleReadsTheShop(): void
    {
        $parser = new PaypalMailParser('0.0.1', static fn (): array => []);
        $mail = (new MimeParser())->parse(1, (string) file_get_contents(__DIR__ . '/fixtures/p2.eml'));
        $order = $parser->parse($mail)->toArray();

        self::assertSame(['ordered', 'AliExpress', '49.22'], [$order['kind'], $order['counterparty'], $order['total']]);
        self::assertSame(['3000000000000001', '3000000000000002'], array_column($order['lines'], 'article_number'));
        self::assertSame(['40.36', '8.86'], array_column($order['lines'], 'unit_price'));
    }

    public function testAnAuthorisationReadsItsLongForm(): void
    {
        $order = self::read('p3');

        self::assertSame(['3P000000RW0000003', 'ordered', '2026-10-19', 'Retro Teile Versand'], [$order['order_number'], $order['kind'], $order['order_date'], $order['counterparty']]);
        self::assertSame(['29.84', '5'], [$order['total'], $order['shipping_cost']]);
        self::assertSame(['NE555 Timer DIP-8'], array_column($order['lines'], 'title'));
        self::assertSame(['24.84'], array_column($order['lines'], 'unit_price'));
    }

    public function testAMailWithoutPaymentIsNoReceipt(): void
    {
        self::assertSame('no_receipt', self::read('p5')['kind']);
    }

    public function testARefundIsRefundedWithoutItems(): void
    {
        $order = self::read('p6');

        self::assertSame(['refunded', '9ZZ00000YY0000006', []], [$order['kind'], $order['order_number'], $order['lines']]);
    }

    public function testItNamesWhatItReads(): void
    {
        $parser = new PaypalMailParser('1.2.3', static fn (): array => []);

        self::assertNotSame([], $parser->kinds());
        self::assertSame(['paypal', 'PayPal', '1.2.3'], [$parser->key(), $parser->shopName(), $parser->version()]);
    }
}
