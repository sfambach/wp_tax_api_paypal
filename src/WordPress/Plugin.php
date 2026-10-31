<?php declare(strict_types=1);

namespace TaxApiPaypal\WordPress;

use TaxApiPaypal\Core\PaypalMailParser;
use Taxmod\Core\Import\Mail\OrderMailParser;

/**
 * The boundary: registers the PayPal mail reader with Taxmod Mail (filter `taxmail_parsers`) and a tab on the
 * modeller's Configuration page whose part «Mail» names what it reads (D-1006, D-1007).
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/import/module-schnitt.md
 */
final class Plugin
{
    public const VERSION = '0.0.1';
    public const TAB     = 'paypal';

    public static function boot(): void
    {
        add_action('plugins_loaded', static function (): void {
            load_plugin_textdomain('taxapipaypal', false, dirname(plugin_basename(dirname(__DIR__, 2) . '/wp-tax-api-paypal.php')) . '/languages');

            // The contract lives in the modeller (0.1.81 or later); without it there is nothing to register with.
            if (! interface_exists(OrderMailParser::class)) {
                add_action('admin_notices', static fn () => print '<div class="notice notice-warning"><p><strong>Taxmod PayPal:</strong> '
                    . esc_html__('needs the Taxonomy Modeller 0.1.81 or later and Taxmod Mail 0.6.0 or later.', 'taxapipaypal') . '</p></div>');

                return;
            }

            $reader = new PaypalMailParser(self::VERSION, self::otherShops(...));
            add_filter('taxmail_parsers', static fn (array $parsers): array => [...$parsers, $reader]);
            add_filter('taxmod_config_tabs', static fn (array $tabs): array => $tabs + [self::TAB => [
                'title'   => 'PayPal',
                'version' => self::VERSION,
                'reader'  => $reader,
                'guide'   => self::guide(...),
                'render'  => static fn (): string => '',
                'save'    => static function (array $post): void {
                },
            ]], 30);
        });
    }

    /**
     * Shops other modules read; a payment to one of them is that module's order, not a second one from PayPal.
     *
     * @return list<string>
     */
    public static function otherShops(): array
    {
        $names = [];

        foreach ((array) apply_filters('taxmail_parsers', []) as $parser) {
            if ($parser instanceof OrderMailParser && $parser->key() !== self::TAB) {
                $names[] = $parser->shopName();
            }
        }

        return $names;
    }

    /** The box «How to connect» beside the tab (D-963). */
    public static function guide(): string
    {
        return '<ol><li>' . esc_html__('Let PayPal send its payment receipts to the mailbox of Taxmod Mail (or forward them there).', 'taxapipaypal') . '</li><li>'
            . esc_html__('Keep this module active: Taxmod Mail hands every PayPal mail to it, and the proposals appear in the Inbox.', 'taxapipaypal') . '</li></ol><p>'
            . esc_html__('A payment to a shop with a module of its own (eBay, AliExpress …) is left to that module and discarded here. Every other payment becomes an order of the platform «PayPal», numbered by its transaction code.', 'taxapipaypal') . '</p>';
    }
}
