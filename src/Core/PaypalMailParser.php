<?php declare(strict_types=1);

namespace TaxApiPaypal\Core;

use Closure;
use Taxmod\Core\Import\Mail\LineCollector;
use Taxmod\Core\Import\Mail\MailMessage;
use Taxmod\Core\Import\Mail\OrderMailParser;
use Taxmod\Core\Import\Mail\Text;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;

/**
 * PayPal: the buyer's receipts — «Beleg für Ihre Zahlung an …», «Sie haben eine Zahlung autorisiert», «<merchant>:
 * <amount> € EUR» — become an order of the platform «PayPal», numbered by the transaction code, with the merchant,
 * items, shipping and total; a refund becomes «refunded». A payment to a shop that has a module of its own (eBay,
 * AliExpress …) is that module's order and is discarded here, as are mails without a payment of his.
 *
 * ```mermaid
 * flowchart LR
 *   M["PayPal mail"] --> C{"transaction code?"}
 *   C -->|no| D["no receipt → discarded"]
 *   C -->|yes| S{"merchant has its own module?"}
 *   S -->|yes| D
 *   S -->|no| O["order «PayPal» · refunded"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/import/module-schnitt.md
 */
final class PaypalMailParser implements OrderMailParser
{
    public const PLATFORM = 'PayPal';

    private const CODE = '/Transaktionscode:?\s+([A-Z0-9]{17})\b/u';
    private const MONTHS = ['januar' => 1, 'februar' => 2, 'märz' => 3, 'april' => 4, 'mai' => 5, 'juni' => 6, 'juli' => 7,
        'august' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'dezember' => 12];

    /**
     * @param Closure(): list<string> $otherShops names of the shops other modules read — asked at parse time, because
     *        modules register in any order
     */
    public function __construct(private readonly string $version, private readonly Closure $otherShops)
    {
    }

    public function key(): string
    {
        return 'paypal';
    }

    public function shopName(): string
    {
        return self::PLATFORM;
    }

    public function recognises(MailMessage $mail): bool
    {
        return str_contains($mail->senderDomain(), 'paypal');
    }

    public function parse(MailMessage $mail): OrderProposal
    {
        $lines = $mail->lines();
        $text = $mail->subject . "\n" . implode("\n", $lines);
        $kind = self::kind($text);

        if ($kind === null || preg_match(self::CODE, $text, $code) !== 1) {
            return new OrderProposal(self::PLATFORM, '', ProposalKind::NoReceipt);
        }

        $merchant = self::merchant($lines, $mail->subject);

        if ($merchant !== null && $this->hasOwnModule($merchant)) {
            return new OrderProposal(self::PLATFORM, $code[1], ProposalKind::NoReceipt, counterparty: $merchant);
        }

        [$total, $currency] = Text::money(Text::after($lines, 'Gesamtbetrag') ?? Text::after($lines, 'Gesamtbetrag des Einkaufs'));
        [$shipping] = Text::money(Text::after($lines, 'Versand') ?? Text::after($lines, 'Versand und Verpackung'));

        return new OrderProposal(
            self::PLATFORM,
            $code[1],
            $kind,
            self::date(Text::after($lines, 'Transaktionsdatum')) ?? $mail->date?->format('Y-m-d'),
            $currency ?? 'EUR',
            $kind === ProposalKind::Ordered ? self::items($lines)->lines() : [],
            $shipping === null || $shipping == 0.0 ? null : Text::decimal($shipping),
            Text::decimal($total),
            $merchant,
            source: 'mail',
            externalId: $mail->messageId !== '' ? $mail->messageId : null,
        );
    }

    public function kinds(): array
    {
        return [ProposalKind::Ordered, ProposalKind::Refunded];
    }

    public function version(): string
    {
        return $this->version;
    }

    /** Null: not a payment of his — money he received, security notices, advertising. */
    private static function kind(string $text): ?ProposalKind
    {
        $lower = mb_strtolower($text);

        return match (true) {
            str_contains($lower, 'rückzahlung') || str_contains($lower, 'erstattung') => ProposalKind::Refunded,
            str_contains($lower, 'zahlung erhalten') || str_contains($lower, 'geld erhalten') => null,
            str_contains($lower, 'zahlung an') || str_contains($lower, 'zahlung gesendet') || str_contains($lower, 'autorisiert')
                || preg_match('/sie haben .{1,40} an .+ gezahlt/u', $lower) === 1 => ProposalKind::Ordered,
            default => null,
        };
    }

    /** @param list<string> $lines */
    private static function merchant(array $lines, string $subject): ?string
    {
        $name = Text::after($lines, 'Händler') ?? Text::after($lines, 'Zahlung an');

        if ($name === null && preg_match('/^Beleg für Ihre Zahlung an (.+)$/u', $subject, $match) === 1) {
            $name = $match[1];
        }

        return $name === null || trim($name) === '' ? null : trim($name);
    }

    private function hasOwnModule(string $merchant): bool
    {
        $squeezed = self::squeeze($merchant);

        foreach (($this->otherShops)() as $shop) {
            $key = self::squeeze($shop);

            if ($key !== '' && $key !== self::squeeze(self::PLATFORM) && str_contains($squeezed, $key)) {
                return true;
            }
        }

        return false;
    }

    private static function squeeze(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($name)) ?? '';
    }

    /** «20.10.2026» (receipt) or «19. Oktober 2026» (authorisation). */
    private static function date(?string $text): ?string
    {
        $text = trim((string) $text);

        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $text, $m) === 1) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        if (preg_match('/^(\d{1,2})\.\s*(\p{L}+)\s+(\d{4})$/u', $text, $m) === 1 && isset(self::MONTHS[mb_strtolower($m[2])])) {
            return sprintf('%04d-%02d-%02d', $m[3], self::MONTHS[mb_strtolower($m[2])], $m[1]);
        }

        return null;
    }

    /**
     * Items stand before «Zwischensumme»: title, «Menge: n», maybe «Produkt-ID: …», unit price (receipt); or title,
     * «Artikelnummer: … | price» (authorisation).
     *
     * @param list<string> $lines
     */
    private static function items(array $lines): LineCollector
    {
        $items = new LineCollector();
        $end = array_search('Zwischensumme', $lines, true);
        $end = $end === false ? count($lines) : $end;

        for ($k = 1; $k < $end; $k++) {
            if (preg_match('/^Menge:\s*(\d+)$/u', $lines[$k], $quantity) === 1) {
                $article = null;
                $price = null;

                for ($j = $k + 1; $j < $end && $j <= $k + 2; $j++) {
                    if (preg_match('/^Produkt-ID:\s*(\S+)$/u', $lines[$j], $id) === 1) {
                        $article = $id[1];
                    } elseif ($price === null) {
                        [$price] = Text::money($lines[$j]);
                    }
                }

                $items->add($lines[$k - 1], (float) $quantity[1], $price, $article);
            } elseif (preg_match('/^Artikelnummer:\s*(.*?)\s*\|\s*(.+)$/u', $lines[$k], $row) === 1) {
                [$price] = Text::money($row[2]);
                $title = $k - 1;

                // The long form repeats the price beneath the title.
                while ($title > 0 && preg_match('/^[\d.,]+\s*(€|EUR)(\s+[A-Z]{3})?$/u', $lines[$title]) === 1) {
                    $title--;
                }

                $items->add($lines[$title], null, $price, $row[1] !== '' ? $row[1] : null);
            }
        }

        return $items;
    }
}
