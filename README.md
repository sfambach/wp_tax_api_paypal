# Taxmod PayPal

PayPal connection for the [Taxonomy Modeller](https://github.com/sfambach/wp-taxonomy-modeler) (WordPress plugin).

**Status 0.0.1 (needs modeller 0.1.81 and [Taxmod Mail](https://github.com/sfambach/wp_tax_mail) 0.6.0 or later):
reads PayPal payment receipts from the mailbox of Taxmod Mail and hands them to the modeller's inbox.**

Every connection is a module of its own, whether it reads mails or an API; Taxmod Mail only fetches and files the
mails (D-1006, [concept](https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/import/module-schnitt.md)).

## What it reads

| Mail | Becomes |
|---|---|
| «Beleg für Ihre Zahlung an …», «Sie haben eine Zahlung autorisiert», «<merchant>: <amount> € EUR» | order of the platform «PayPal», number = transaction code; merchant, date, items (title, quantity, unit price, product id), shipping, total |
| the same, paid to a shop that has a module of its own (eBay, AliExpress, …) | discarded — that module brings the order |
| «Rückzahlung» / «Erstattung» | refunded (no real sample yet) |
| money received, notices, advertising | discarded |

The authorisation and the receipt of one payment carry the same transaction code, so they are one order in the inbox.
A PayPal API connection is not built yet.

## Setup

1. Install and set up Taxmod Mail (mailbox access).
2. Activate this module. *Taxonomy Modeller → Configuration → PayPal* shows under «Mail» which mails it reads.

## Development

`composer install`, then `vendor/bin/phpunit` (core suite, no WordPress). The sample mails in `tests/Core/fixtures`
are synthetic.

## License

Copyright (C) 2026 Stefan Fambach (SteFam), https://www.fambach.net — [PolyForm Noncommercial 1.0.0](LICENSE.md).
Commercial use needs the owner's prior consent.

## Authors

- **Stefan Fambach** (SteFam) – idea and concept, review and testing – https://www.fambach.net
- **Claude** (Anthropic AI) – implementation, via Claude Code

## AI disclosure

This project is built with AI assistance. Stefan Fambach had the idea, wrote the concept and reviews and tests every change; the implementation (code and documentation) is written largely by Claude (Anthropic) via Claude Code.
