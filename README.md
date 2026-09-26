# Erpy for AFAS

An **[Erpy](https://justinholt.com/plugins/craft-erpy)** connector for AFAS Profit.

Free. Erpy itself is the paid part — it owns the sync engine, the identity map, the field
mapping, the queue, the dead letters and the log. This package's whole job is to translate one
vendor's API into Erpy's canonical documents.

## Installing

```sh
composer require justinholtweb/craft-erpy-afas
php craft plugin/install erpy-afas
```

Then add a connection under **Erpy → Connections** and pick it from the ERP list.

## What you need to know

### Authentication

An App Connector token. AFAS gives it to you as XML and expects it back base64-encoded in the `Authorization` header, as `Authorization: AfasToken <base64>` — paste only the part between `<data>` and `</data>` and Erpy does the rest.

### There is no fixed schema

A GetConnector is built by your implementation partner, with whatever fields and names they chose. Every connector name is a setting here, the defaults are AFAS’s own standard ones, and any individual field can be corrected on Erpy’s mapping screen with a rule targeting the canonical field.

### Delta syncing

Works if your GetConnectors expose a modified field — name it in the settings. If they do not, every sync reads everything, which Erpy’s change detection makes cheap.

## What it syncs

The connection screen shows exactly which entities and directions this connector supports —
it is generated from the connector's own declaration, so it can never advertise a flow it has
not implemented.

## A field is wrong

Correct it on the mapping screen: a rule whose target is a canonical field (`sku`, `unitPrice`,
`customerCode`) overrides what the connector read, before anything reaches Commerce. No fork,
no wait for a release.

## Documentation

The full documentation for this add-on is at
https://justinholt.com/plugins/craft-erpy/docs/afas, and Erpy's own is at
https://justinholt.com/plugins/craft-erpy/docs.

## Requirements

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+, and Erpy 5.0+.

## Support

justin@justinholt.com

## License

The Craft License. See `LICENSE.md`. Erpy for AFAS is free: no editions and no licence key of its own.
It needs a licensed copy of [Erpy](https://justinholt.com/plugins/craft-erpy), which is the paid part.
