<?php

namespace justinholtweb\erpyafas\connectors;

use Craft;
use DateTime;
use DateTimeInterface;
use justinholtweb\erpy\auth\BaseAuth;
use justinholtweb\erpy\base\AuthInterface;
use justinholtweb\erpy\base\Capabilities;
use justinholtweb\erpy\base\Connector;
use justinholtweb\erpy\base\Direction;
use justinholtweb\erpy\base\Entity;
use justinholtweb\erpy\base\FetchCriteria;
use justinholtweb\erpy\base\Field;
use justinholtweb\erpy\base\HealthResult;
use justinholtweb\erpy\base\Page;
use justinholtweb\erpy\base\PushResult;
use justinholtweb\erpy\base\Transport;
use justinholtweb\erpy\models\canonical\ErpCredit;
use justinholtweb\erpy\models\canonical\ErpCustomer;
use justinholtweb\erpy\models\canonical\ErpOrder;
use justinholtweb\erpy\models\canonical\ErpOrderStatus;
use justinholtweb\erpy\models\canonical\ErpPrice;
use justinholtweb\erpy\models\canonical\ErpProduct;
use justinholtweb\erpy\models\canonical\ErpStock;

/**
 * AFAS Profit, through GetConnectors and UpdateConnectors.
 *
 * AFAS is unlike every other ERP here in one decisive way: **it has no fixed schema.** A
 * GetConnector is something the customer's own consultant builds, with whatever fields and
 * whatever names they chose. There is no `Items` endpoint to read; there is whatever your
 * implementation partner called it.
 *
 * So this connector does not pretend to know your field names. It ships the names AFAS's own
 * standard connectors use, exposes every one of them as a setting, and — for anything a setting
 * does not cover — relies on Erpy's mapping screen, where a rule targeting a canonical field
 * corrects the connector's reading without touching code.
 *
 * Authentication is an App Connector token, which AFAS gives you as XML and expects back
 * base64-encoded inside an `AfasToken` header. Everyone gets that wrong once.
 */
class AfasConnector extends Connector
{
    public static function handle(): string
    {
        return 'afas';
    }

    public static function displayName(): string
    {
        return 'AFAS Profit';
    }

    public static function vendor(): string
    {
        return 'AFAS';
    }

    public static function description(): string
    {
        return 'AFAS Profit through GetConnectors and UpdateConnectors, with every connector and field name configurable — because in AFAS they always are.';
    }

    public static function setupUrl(): ?string
    {
        return 'https://help.afas.nl/help/NL/SE/App_Cnr_Rest.htm';
    }

    public static function capabilities(): Capabilities
    {
        return Capabilities::make()
            // AFAS filters server-side on any field in a GetConnector, so a modified-since query
            // works — provided the connector actually exposes a modified field, which is why the
            // field name is a setting.
            ->supports(Entity::CUSTOMER, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::PRODUCT, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::PRICE, Direction::PULL, delta: false, pageSize: 100)
            ->supports(Entity::INVENTORY, Direction::PULL, delta: false, pageSize: 200)
            ->supports(Entity::ORDER, Direction::PUSH)
            ->supports(Entity::ORDER_STATUS, Direction::PULL, delta: true, pageSize: 100)
            ->supports(Entity::CREDIT, Direction::PULL, pageSize: 100);
    }

    public static function settingsFields(): array
    {
        return [
            Field::text('environmentId', Craft::t('erpy', 'Environment ID'), [
                'required' => true,
                'placeholder' => '12345',
                'instructions' => Craft::t('erpy', 'The number in your AFAS URL. Erpy builds the REST host from it.'),
            ]),
            Field::select('environmentType', Craft::t('erpy', 'Environment'), [
                'live' => Craft::t('erpy', 'Production'),
                'test' => Craft::t('erpy', 'Test'),
                'accept' => Craft::t('erpy', 'Acceptance'),
            ], ['default' => 'live']),
            Field::secret('token', Craft::t('erpy', 'App Connector token'), [
                'required' => true,
                'instructions' => Craft::t('erpy', 'Paste the token value only — the part between <data> and </data> in the XML AFAS gave you. Erpy wraps and encodes it.'),
            ]),

            Field::heading(
                Craft::t('erpy', 'GetConnectors'),
                Craft::t('erpy', 'AFAS has no fixed schema: a GetConnector is built by your implementation partner. These are the names AFAS’s own standard connectors use — change any that differ, and correct individual field names on the mapping screen.'),
            ),
            Field::text('itemsConnector', Craft::t('erpy', 'Items'), ['default' => 'Profit_Artikelen']),
            Field::text('stockConnector', Craft::t('erpy', 'Stock'), ['default' => 'Profit_Voorraad']),
            Field::text('customersConnector', Craft::t('erpy', 'Customers'), ['default' => 'Profit_Debiteuren']),
            Field::text('pricesConnector', Craft::t('erpy', 'Prices'), ['default' => 'Profit_Verkoopprijzen']),
            Field::text('ordersConnector', Craft::t('erpy', 'Sales orders'), ['default' => 'Profit_Verkooporders']),

            Field::heading(Craft::t('erpy', 'Field names')),
            Field::text('modifiedField', Craft::t('erpy', 'Modified field'), [
                'default' => 'Bijgewerkt',
                'instructions' => Craft::t('erpy', 'Used for delta syncs. Leave blank if your connectors do not expose one — every sync then reads everything, which Erpy’s change detection makes cheap.'),
            ]),
            Field::text('skuField', Craft::t('erpy', 'Item code field'), ['default' => 'Itemcode']),
            Field::text('customerCodeField', Craft::t('erpy', 'Customer number field'), ['default' => 'Debiteurnummer']),

            Field::heading(Craft::t('erpy', 'Sales orders')),
            Field::text('salesOrderConnector', Craft::t('erpy', 'UpdateConnector'), [
                'default' => 'FbSales',
                'instructions' => Craft::t('erpy', 'The UpdateConnector new sales orders are posted to.'),
            ]),
            Field::text('warehouse', Craft::t('erpy', 'Warehouse'), []),
        ];
    }

    protected function buildAuth(): ?AuthInterface
    {
        return new class extends BaseAuth {
            public function headers(): array
            {
                $token = (string)$this->setting('token');

                if ($token === '') {
                    return [];
                }

                // AFAS hands the token over as XML and expects it back base64-encoded. Sending
                // the bare token, or the XML un-encoded, both produce the same unhelpful 401.
                $xml = '<token><version>1</version><data>' . $token . '</data></token>';

                return ['Authorization' => 'AfasToken ' . base64_encode($xml)];
            }

            public function isConfigured(): bool
            {
                return (string)$this->setting('token') !== '';
            }
        };
    }

    protected function buildTransport(): Transport
    {
        $environment = (string)$this->setting('environmentType', 'live');
        $prefix = match ($environment) {
            'test' => 'test',
            'accept' => 'accept',
            default => '',
        };

        return (new Transport())
            ->setBaseUri(sprintf(
                'https://%s%s.rest.afas.online/profitrestservices',
                (string)$this->setting('environmentId'),
                $prefix !== '' ? '.' . $prefix : '',
            ))
            ->setDefaultHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json;charset=utf-8',
            ])
            ->setRateLimit(4)
            ->setTimeout(120);
    }

    protected function probe(): HealthResult
    {
        $response = $this->transport()->get('metainfo');

        if (!$response->ok()) {
            return HealthResult::fail($response->errorMessage(), match ($response->status) {
                401 => [
                    Craft::t('erpy', 'Paste only the value between <data> and </data> — Erpy adds the XML wrapper and the base64 encoding itself.'),
                    Craft::t('erpy', 'Check the App Connector is enabled for this environment and has not expired.'),
                ],
                403 => [Craft::t('erpy', 'The token is valid but the App Connector has not been given access to the connectors you are trying to read.')],
                default => [],
            });
        }

        $connectors = (array)$response->at('getConnectors', []);
        $names = array_column($connectors, 'id');
        $wanted = array_filter([
            (string)$this->setting('itemsConnector'),
            (string)$this->setting('customersConnector'),
        ]);
        $missing = array_values(array_diff($wanted, $names));

        if ($missing !== []) {
            return HealthResult::fail(
                Craft::t('erpy', 'Connected, but this App Connector cannot see: {list}', ['list' => implode(', ', $missing)]),
                [Craft::t('erpy', 'It can see: {list}', ['list' => implode(', ', array_slice($names, 0, 25))])],
            );
        }

        return HealthResult::pass(Craft::t('erpy', 'Connected to AFAS.'), [
            Craft::t('erpy', 'Environment') => (string)$this->setting('environmentId'),
            Craft::t('erpy', 'GetConnectors available') => (string)count($names),
        ]);
    }

    // ---------------------------------------------------------------------------------------
    // Pull
    // ---------------------------------------------------------------------------------------

    protected function fetchProducts(FetchCriteria $criteria): Page
    {
        $skuField = (string)$this->setting('skuField', 'Itemcode');

        return $this->page((string)$this->setting('itemsConnector', 'Profit_Artikelen'), $criteria, Entity::PRODUCT, function(array $row) use ($skuField): ErpProduct {
            return new ErpProduct([
                'sku' => (string)($row[$skuField] ?? $row['Itemcode'] ?? ''),
                'name' => (string)($row['Omschrijving'] ?? $row['Description'] ?? ''),
                'enabled' => !$this->flag($row, ['Geblokkeerd', 'Blocked']),
                'blocked' => $this->flag($row, ['Geblokkeerd', 'Blocked']),
                'category' => $row['Artikelgroep'] ?? $row['ItemGroup'] ?? null,
                'unitOfMeasure' => $row['Eenheid'] ?? $row['Unit'] ?? null,
                'price' => $this->number($row, ['Verkoopprijs', 'SalesPrice']),
                'barcode' => $row['EAN'] ?? $row['Barcode'] ?? null,
                'weight' => $this->number($row, ['Gewicht', 'Weight']),
                'remoteId' => (string)($row[$skuField] ?? ''),
                'remoteKey' => (string)($row[$skuField] ?? ''),
                'modifiedAt' => $this->date($row[(string)$this->setting('modifiedField', 'Bijgewerkt')] ?? null),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchInventory(FetchCriteria $criteria): Page
    {
        $skuField = (string)$this->setting('skuField', 'Itemcode');

        return $this->page((string)$this->setting('stockConnector', 'Profit_Voorraad'), $criteria, Entity::INVENTORY, function(array $row) use ($skuField): ErpStock {
            return new ErpStock([
                'sku' => (string)($row[$skuField] ?? $row['Itemcode'] ?? ''),
                'warehouse' => $row['Magazijn'] ?? $row['Warehouse'] ?? null,
                'onHand' => (float)($this->number($row, ['Voorraad', 'Stock', 'Aantal']) ?? 0),
                'available' => $this->number($row, ['Vrije voorraad', 'FreeStock', 'Beschikbaar']),
                'allocated' => $this->number($row, ['Gereserveerd', 'Reserved']),
                'remoteId' => (string)($row[$skuField] ?? ''),
                'raw' => $row,
            ]);
        }, delta: false);
    }

    protected function fetchPrices(FetchCriteria $criteria): Page
    {
        $skuField = (string)$this->setting('skuField', 'Itemcode');
        $customerField = (string)$this->setting('customerCodeField', 'Debiteurnummer');

        return $this->page((string)$this->setting('pricesConnector', 'Profit_Verkoopprijzen'), $criteria, Entity::PRICE, function(array $row) use ($skuField, $customerField): ErpPrice {
            return new ErpPrice([
                'sku' => (string)($row[$skuField] ?? ''),
                'customerCode' => isset($row[$customerField]) ? (string)$row[$customerField] : null,
                'priceListCode' => $row['Prijslijst'] ?? $row['PriceList'] ?? null,
                'currency' => $row['Valuta'] ?? $row['Currency'] ?? null,
                'unitPrice' => (float)($this->number($row, ['Prijs', 'Price', 'Verkoopprijs']) ?? 0),
                'minQuantity' => (float)($this->number($row, ['Vanaf aantal', 'MinQuantity']) ?? 1) ?: 1.0,
                'startsAt' => $this->date($row['Ingangsdatum'] ?? $row['StartDate'] ?? null),
                'endsAt' => $this->date($row['Einddatum'] ?? $row['EndDate'] ?? null),
                'raw' => $row,
            ]);
        }, delta: false);
    }

    protected function fetchCustomers(FetchCriteria $criteria): Page
    {
        $customerField = (string)$this->setting('customerCodeField', 'Debiteurnummer');

        return $this->page((string)$this->setting('customersConnector', 'Profit_Debiteuren'), $criteria, Entity::CUSTOMER, function(array $row) use ($customerField): ErpCustomer {
            return new ErpCustomer([
                'code' => (string)($row[$customerField] ?? ''),
                'name' => (string)($row['Naam'] ?? $row['Name'] ?? ''),
                'email' => $row['E-mail'] ?? $row['Email'] ?? null,
                'phone' => $row['Telefoonnr'] ?? $row['Phone'] ?? null,
                'enabled' => !$this->flag($row, ['Geblokkeerd', 'Blocked']),
                'onHold' => $this->flag($row, ['Geblokkeerd', 'Blocked']),
                'currency' => $row['Valuta'] ?? $row['Currency'] ?? null,
                'taxId' => $row['BTW-nummer'] ?? $row['VATNumber'] ?? null,
                'paymentTermsCode' => $row['Betalingsconditie'] ?? $row['PaymentCondition'] ?? null,
                'creditLimit' => $this->number($row, ['Kredietlimiet', 'CreditLimit']),
                'balance' => $this->number($row, ['Openstaand saldo', 'Balance']),
                'remoteId' => (string)($row[$customerField] ?? ''),
                'remoteKey' => (string)($row[$customerField] ?? ''),
                'modifiedAt' => $this->date($row[(string)$this->setting('modifiedField', 'Bijgewerkt')] ?? null),
                'raw' => $row,
            ]);
        });
    }

    protected function fetchCredit(FetchCriteria $criteria): Page
    {
        $customerField = (string)$this->setting('customerCodeField', 'Debiteurnummer');

        return $this->page((string)$this->setting('customersConnector', 'Profit_Debiteuren'), $criteria, Entity::CREDIT, function(array $row) use ($customerField): ErpCredit {
            return new ErpCredit([
                'customerCode' => (string)($row[$customerField] ?? ''),
                'currency' => (string)($row['Valuta'] ?? 'EUR'),
                'creditLimit' => $this->number($row, ['Kredietlimiet', 'CreditLimit']),
                'balance' => (float)($this->number($row, ['Openstaand saldo', 'Balance']) ?? 0),
                'onHold' => $this->flag($row, ['Geblokkeerd', 'Blocked']),
                'raw' => $row,
            ]);
        }, delta: false);
    }

    protected function fetchOrderStatuses(FetchCriteria $criteria): Page
    {
        return $this->page((string)$this->setting('ordersConnector', 'Profit_Verkooporders'), $criteria, Entity::ORDER_STATUS, function(array $row): ErpOrderStatus {
            $status = (string)($row['Status'] ?? '');

            return new ErpOrderStatus([
                'orderNumber' => (string)($row['Referentie'] ?? $row['Reference'] ?? ''),
                'status' => $status,
                'statusCode' => $status,
                'isCancelled' => stripos($status, 'vervall') !== false || stripos($status, 'cancel') !== false,
                'isShipped' => stripos($status, 'geleverd') !== false || stripos($status, 'deliver') !== false,
                'isInvoiced' => stripos($status, 'gefactureerd') !== false || stripos($status, 'invoice') !== false,
                'remoteId' => (string)($row['Ordernummer'] ?? $row['OrderNumber'] ?? ''),
                'remoteKey' => (string)($row['Ordernummer'] ?? ''),
                'modifiedAt' => $this->date($row[(string)$this->setting('modifiedField', 'Bijgewerkt')] ?? null),
                'raw' => $row,
            ]);
        });
    }

    // ---------------------------------------------------------------------------------------
    // Push
    // ---------------------------------------------------------------------------------------

    protected function pushOrder(ErpOrder $document, ?string $remoteId = null): PushResult
    {
        if ($document->customerCode === null || $document->customerCode === '') {
            return PushResult::rejected(Craft::t('erpy', 'AFAS needs a debtor number. Set a guest customer code on the order mapping, or link this customer to an AFAS debtor.'));
        }

        $connector = (string)$this->setting('salesOrderConnector', 'FbSales');
        $lines = [];

        foreach ($document->lines as $line) {
            $lines[] = [
                'Fields' => array_filter([
                    'ItCd' => $line->sku,
                    'QuUn' => $line->quantity,
                    'Upri' => $line->unitPrice,
                    'PrDc' => $line->discountPercent,
                    'Rm' => $line->notes ? mb_substr(implode('; ', $line->notes), 0, 250) : null,
                    'War' => $line->warehouse ?: ($this->setting('warehouse') ?: null),
                ], static fn($value) => $value !== null && $value !== ''),
            ];
        }

        // AFAS's UpdateConnector envelope: one Element with Fields, and nested Objects for the
        // lines. The nesting is fixed and unforgiving — a misplaced key is a 400 with a message
        // about an unexpected element rather than about the key.
        $payload = [
            $connector => [
                'Element' => [
                    'Fields' => array_filter([
                        'OrDa' => ($document->orderedAt ?? new DateTime())->format('Y-m-d'),
                        'DbId' => $document->customerCode,
                        'RfCs' => mb_substr($document->orderNumber, 0, 50),
                        'CuId' => $document->currency,
                        'Rm' => $document->customerNote ? mb_substr($document->customerNote, 0, 250) : null,
                        'War' => $this->setting('warehouse') ?: null,
                    ], static fn($value) => $value !== null && $value !== ''),
                    'Objects' => [
                        ['FbSalesLines' => ['Element' => $lines]],
                    ],
                ],
            ],
        ];

        foreach ($document->customFields as $fieldName => $value) {
            $payload[$connector]['Element']['Fields'][$fieldName] = $value;
        }

        $response = $this->transport()->post("connectors/$connector", $payload);

        if (!$response->ok()) {
            return $response->status >= 400 && $response->status < 500
                ? PushResult::rejected($response->errorMessage(), $response->json_())
                : PushResult::failed($response->errorMessage(), $response->json_());
        }

        // AFAS answers with the new order number in a `results` object whose exact shape depends
        // on the connector, so the Commerce order number is the fallback identity.
        $number = (string)($response->at('results.FbSales.OrNu') ?? $response->at('OrNu') ?? '');

        return PushResult::ok($number !== '' ? $number : $document->orderNumber, $number ?: null, $response->json_());
    }

    // ---------------------------------------------------------------------------------------
    // Plumbing
    // ---------------------------------------------------------------------------------------

    private function page(string $connector, FetchCriteria $criteria, string $entity, callable $make, bool $delta = true): Page
    {
        if ($connector === '') {
            $this->note(Craft::t('erpy', 'No GetConnector is configured for this entity, so nothing was read.'));

            return Page::empty();
        }

        $take = $this->pageSize($entity, $criteria);
        $skip = (int)($criteria->cursor ?? 0);
        $query = ['skip' => $skip, 'take' => $take];

        $modifiedField = (string)$this->setting('modifiedField', '');

        if ($delta && $modifiedField !== '' && $criteria->since instanceof DateTimeInterface) {
            // AFAS filters with three parallel lists: fields, values and operator codes. 4 is
            // "greater than or equal to". Getting the three out of step filters on the wrong
            // field and silently returns the wrong rows.
            $query['filterfieldids'] = $modifiedField;
            $query['filtervalues'] = $criteria->since->format('Y-m-d\TH:i:s');
            $query['operatortypes'] = '4';
        }

        if ($criteria->filters !== []) {
            $fields = array_keys($criteria->filters);
            $values = array_values($criteria->filters);

            $query['filterfieldids'] = implode(',', array_filter([$query['filterfieldids'] ?? null, ...$fields]));
            $query['filtervalues'] = implode(',', array_filter([$query['filtervalues'] ?? null, ...array_map('strval', $values)]));
            $query['operatortypes'] = implode(',', array_filter([$query['operatortypes'] ?? null, ...array_fill(0, count($fields), '1')]));
        }

        $response = $this->transport()->get("connectors/$connector", $query);

        if (!$response->ok()) {
            throw new \RuntimeException(sprintf(
                'AFAS refused to read %s: %s',
                $connector,
                $response->errorMessage(),
            ));
        }

        $rows = (array)$response->at('rows', []);
        $items = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $items[] = $make($row);
            }
        }

        return new Page($items, count($rows) >= $take ? (string)($skip + $take) : null);
    }

    /**
     * Read the first of several possible field names — AFAS connectors are built per customer and
     * the same value is called something different in every one.
     */
    private function number(array $row, array $names): ?float
    {
        foreach ($names as $name) {
            if (isset($row[$name]) && is_numeric($row[$name])) {
                return (float)$row[$name];
            }
        }

        return null;
    }

    private function flag(array $row, array $names): bool
    {
        foreach ($names as $name) {
            if (!array_key_exists($name, $row)) {
                continue;
            }

            $value = $row[$name];

            if (is_bool($value)) {
                return $value;
            }

            return in_array(strtolower((string)$value), ['1', 'true', 'ja', 'yes', 'j', 'y'], true);
        }

        return false;
    }

    private function date(mixed $value): ?DateTime
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTime($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
