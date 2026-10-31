<?php declare(strict_types=1);

/**
 * Plugin Name:       Taxmod PayPal
 * Description:       Reads PayPal payment receipts from the mailbox of Taxmod Mail and hands them to the inbox of the Taxonomy Modeller.
 * Version:           0.0.1
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Stefan Fambach (SteFam), Claude (Anthropic AI)
 * Author URI:        https://www.fambach.net
 * License:           PolyForm Noncommercial 1.0.0
 * License URI:       https://polyformproject.org/licenses/noncommercial/1.0.0/
 * Text Domain:       taxapipaypal
 * GitHub Plugin URI: sfambach/wp_tax_api_paypal
 * Primary Branch:    main
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/import/module-schnitt.md
 */

if (! defined('ABSPATH')) {
    exit;
}

$taxapipaypalAutoloader = __DIR__ . '/vendor/autoload.php';

if (! is_readable($taxapipaypalAutoloader)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>Taxmod PayPal:</strong> '
            . esc_html__('run "composer install" in the plugin folder — the autoloader is missing.', 'taxapipaypal')
            . '</p></div>';
    });

    return;
}

require_once $taxapipaypalAutoloader;

TaxApiPaypal\WordPress\Plugin::boot();
