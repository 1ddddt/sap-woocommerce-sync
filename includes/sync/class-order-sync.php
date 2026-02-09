<?php
/**
 * Order Sync - WooCommerce to SAP order synchronization
 *
 * Handles COD and prepaid order flows with idempotency checking.
 * Before creating a Sales Order, checks SAP for existing NumAtCard
 * to prevent duplicate orders on retry.
 *
 * @package SAP_WooCommerce_Sync
 * @since 2.0.0
 */

namespace SAPWCSync\Sync;

use SAPWCSync\API\SAP_Client;
use SAPWCSync\Constants\Config;
use SAPWCSync\Constants\Sync_Status;
use SAPWCSync\Helpers\Logger;
use SAPWCSync\Helpers\Transaction_Manager;
use SAPWCSync\Helpers\SAP_Filter_Builder;
use SAPWCSync\Repositories\Order_Map_Repository;

defined('ABSPATH') || exit;

class Order_Sync
{
    private $sap_client;
    private $logger;
    private $order_repo;
    private $transaction;

    public function __construct(SAP_Client $sap_client)
    {
        $this->sap_client = $sap_client;
        $this->logger = new Logger();
        $this->order_repo = new Order_Map_Repository();
        $this->transaction = new Transaction_Manager();
    }

    /**
     * Process new WC order -> SAP.
     */
    public function process_new_order(int $order_id): void
    {
        if (Config::get(Config::OPT_ENABLE_ORDER_SYNC) !== 'yes') {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            $this->logger->warning("Order #{$order_id} not found");
            return;
        }

        if ($this->order_repo->is_synced($order_id)) {
            $this->logger->info("Order #{$order_id} already synced, skipping");
            return;
        }

        $is_cod = in_array($order->get_payment_method(), ['cod', 'cashondelivery'], true);

        try {
            // IDEMPOTENCY CHECK: Verify order doesn't already exist in SAP
            $web_so_number = $this->get_web_so_number($order_id);
            $existing_so = $this->find_existing_sales_order($web_so_number);

            if ($existing_so) {
                // Order already exists in SAP - link mapping without creating duplicate
                $this->order_repo->upsert($order_id, [
                    'sap_doc_entry' => $existing_so['DocEntry'],
                    'sap_doc_num' => $existing_so['DocNum'],
                    'sap_doc_type' => 'SalesOrder',
                    'payment_type' => $is_cod ? 'cod' : 'prepaid',
                    'sync_status' => Sync_Status::SO_CREATED,
                ]);
                $this->logger->info("Order #{$order_id} already exists in SAP (DocEntry={$existing_so['DocEntry']}), linked mapping");
                return;
            }

            if ($is_cod) {
                $this->process_cod_order($order);
            } else {
                $this->process_prepaid_order($order);
            }
        } catch (\Exception $e) {
            $this->logger->error("Order sync failed for #{$order_id}: " . $e->getMessage(), [
                'entity_type' => Sync_Status::ENTITY_ORDER,
                'entity_id' => $order_id,
            ]);

            $order->add_order_note(sprintf('SAP Sync Error: %s - Will retry automatically.', $e->getMessage()));

            // Schedule retry
            $current_attempt = 0;
            $existing = $this->order_repo->find_by_wc_order($order_id);
            if ($existing) {
                $current_attempt = (int) $existing->retry_count;
            }
            $this->order_repo->upsert($order_id, [
                'payment_type' => $is_cod ? 'cod' : 'prepaid',
                'sync_status' => Sync_Status::ERROR,
            ]);
            $this->order_repo->schedule_retry($order_id, $e->getMessage(), $current_attempt);

            throw $e;
        }
    }

    /**
     * Process COD order - Sales Order only.
     */
    private function process_cod_order(\WC_Order $order): void
    {
        $order_id = $order->get_id();

        $this->transaction->execute(function () use ($order, $order_id) {
            $card_code = $this->ensure_customer($order);
            $so_data = $this->build_sales_order_payload($order, $card_code);
            $so_response = $this->sap_client->post('Orders', $so_data);

            $this->order_repo->upsert($order_id, [
                'sap_doc_entry' => $so_response['DocEntry'],
                'sap_doc_num' => $so_response['DocNum'],
                'sap_doc_type' => 'SalesOrder',
                'payment_type' => 'cod',
                'sync_status' => Sync_Status::SO_CREATED,
            ]);

            $order->add_order_note(sprintf(
                'SAP Sales Order created: DocEntry=%d, DocNum=%d',
                $so_response['DocEntry'], $so_response['DocNum']
            ));
        });
    }

    /**
     * Process prepaid order - Sales Order only.
     *
     * NOTE: Down Payment Invoice and Incoming Payment creation are DISABLED
     * to prevent automatic creation of financial documents in SAP.
     * Only Sales Order is created with customer details in U_ fields.
     */
    private function process_prepaid_order(\WC_Order $order): void
    {
        $order_id = $order->get_id();
        $card_code = $this->ensure_customer($order);

        // Step 1: Sales Order (critical)
        $so_data = $this->build_sales_order_payload($order, $card_code);
        $so_response = $this->sap_client->post('Orders', $so_data);

        $this->order_repo->upsert($order_id, [
            'sap_doc_entry' => $so_response['DocEntry'],
            'sap_doc_num' => $so_response['DocNum'],
            'sap_doc_type' => 'SalesOrder',
            'payment_type' => 'prepaid',
            'sync_status' => Sync_Status::SO_CREATED,
        ]);

        $order->add_order_note(sprintf('SAP SO: DocEntry=%d, DocNum=%d', $so_response['DocEntry'], $so_response['DocNum']));

        // DISABLED: Down Payment Invoice creation
        // To prevent automatic creation of financial documents in SAP,
        // Down Payment Invoice and Incoming Payment creation are commented out.
        // If needed in future, uncomment the code below:

        /*
        // Step 2: Down Payment Invoice (non-blocking)
        $dp_response = null;
        try {
            $dp_data = $this->build_sales_order_payload($order, $card_code);
            $dp_data['DownPaymentType'] = 'dptInvoice';
            // DP Invoices use their own numbering series in SAP — remove SO series
            unset($dp_data['Series']);
            $dp_response = $this->sap_client->post('DownPayments', $dp_data);

            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . Config::TABLE_ORDER_MAP,
                ['sap_dp_invoice_entry' => $dp_response['DocEntry'], 'sync_status' => Sync_Status::DP_CREATED],
                ['wc_order_id' => $order_id]
            );
        } catch (\Exception $e) {
            $this->logger->warning("DP Invoice skipped for #{$order_id}: " . $e->getMessage());
            $order->add_order_note('SAP DP Invoice skipped: ' . $e->getMessage());
        }

        // Step 3: Incoming Payment (only if DP created)
        if ($dp_response) {
            try {
                $payment_data = $this->build_incoming_payment_payload(
                    $order,
                    $card_code,
                    $dp_response['DocEntry'],
                    'it_DownPayment'
                );
                $this->sap_client->post('IncomingPayments', $payment_data);

                $order->add_order_note('SAP Incoming Payment recorded');
            } catch (\Exception $e) {
                $this->logger->warning("Payment skipped for #{$order_id}: " . $e->getMessage());
                $order->add_order_note('SAP Payment skipped: ' . $e->getMessage());
            }
        }
        */
    }

    /**
     * Build Incoming Payment payload based on WC payment method.
     *
     * Bank transfer (bacs) -> TransferAccount + TransferSum
     * Card payment (resampathpaycorp, stripe, etc.) -> PaymentCreditCards
     * Fallback -> TransferAccount (for any other method)
     */
    private function build_incoming_payment_payload(
        \WC_Order $order,
        string $card_code,
        int $doc_entry,
        string $invoice_type
    ): array {
        $total = (float) $order->get_total();
        $doc_date = $order->get_date_created()->format('Y-m-d');

        $payment_data = [
            'CardCode' => $card_code,
            'DocType' => 'rCustomer',
            'DocDate' => $doc_date,
            'PaymentInvoices' => [[
                'DocEntry' => $doc_entry,
                'SumApplied' => $total,
                'InvoiceType' => $invoice_type,
                'InstallmentId' => 1,
            ]],
        ];

        $wc_method = $order->get_payment_method();
        $credit_card_code = Config::get(Config::OPT_CREDIT_CARD_CODE);
        $credit_card_account = Config::get(Config::OPT_CREDIT_CARD_ACCOUNT);

        // Card payment methods -> PaymentCreditCards (if configured)
        $card_methods = apply_filters('sap_wc_card_payment_methods', [
            'resampathpaycorp', 'stripe', 'paypal',
        ]);

        if (in_array($wc_method, $card_methods, true) && !empty($credit_card_code) && !empty($credit_card_account)) {
            $payment_data['PaymentCreditCards'] = [[
                'CreditCard' => (int) $credit_card_code,
                'CreditAcct' => $credit_card_account,
                'CreditSum' => $total,
                'CardValidUntil' => date('Y-m-d', strtotime('+5 years')),
                'VoucherNum' => $order->get_id(),
            ]];
        } else {
            // Bank transfer or fallback -> TransferAccount
            $transfer_account = Config::get(Config::OPT_TRANSFER_ACCOUNT);
            if (empty($transfer_account)) {
                throw new \Exception('Transfer account not configured');
            }

            $payment_data['TransferAccount'] = $transfer_account;
            $payment_data['TransferSum'] = $total;
            $payment_data['TransferDate'] = $doc_date;
            $payment_data['TransferReference'] = $order->get_transaction_id() ?: "WC-{$order->get_id()}";
        }

        return $payment_data;
    }

    /**
     * Build SAP Sales Order payload.
     */
    private function build_sales_order_payload(\WC_Order $order, string $card_code): array
    {
        $warehouse = Config::warehouse();
        $series = (int) Config::get('sap_wc_sales_order_series', Config::DEFAULT_SALES_ORDER_SERIES);
        $account_code = Config::get('sap_wc_account_code', Config::DEFAULT_ACCOUNT_CODE);
        $tax_code = Config::get('sap_wc_tax_code', Config::DEFAULT_TAX_CODE);

        $payment_method = $order->get_payment_method();
        $is_cod = in_array($payment_method, ['cod', 'cashondelivery'], true);
        $web_so_number = $this->get_web_so_number($order->get_id());

        $document_lines = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $sku = $product->get_sku();
            if (empty($sku)) {
                continue;
            }

            $line_total = (float) $item->get_total();
            $line_tax = (float) $item->get_total_tax();
            $quantity = (float) $item->get_quantity();

            $price_after_vat = $quantity > 0 ? ($line_total + $line_tax) / $quantity : 0;

            $subtotal = (float) $item->get_subtotal();
            $discount_percent = ($subtotal > 0 && $line_total < $subtotal)
                ? (($subtotal - $line_total) / $subtotal) * 100
                : 0;

            $document_lines[] = [
                'ItemCode' => $sku,
                'Quantity' => $quantity,
                'PriceAfterVAT' => $price_after_vat,
                'TaxCode' => $tax_code,
                'DiscountPercent' => round($discount_percent, 2),
                'WarehouseCode' => $warehouse,
            ];
        }

        $delivery_address = trim($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2());
        if (empty($delivery_address)) {
            $delivery_address = trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2());
        }

        $customer_name = trim($order->get_formatted_billing_full_name())
            ?: trim($order->get_formatted_shipping_full_name());

        if (empty($document_lines)) {
            $skipped = [];
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                $sku = $product ? $product->get_sku() : '';
                $skipped[] = $item->get_name() . ($sku ? " (SKU: {$sku})" : ' (no SKU - unmapped)');
            }
            throw new \Exception(sprintf(
                'No mapped SAP items in order. Products: %s. Map these products to SAP ItemCodes first.',
                implode('; ', $skipped)
            ));
        }

        // Build shipping line item (if any)
        $shipping_line = $this->build_shipping_line($order, $tax_code);
        if ($shipping_line) {
            $document_lines[] = $shipping_line;
        }

        $payload = [
            'CardCode' => $card_code,
            'Series' => $series,
            'NumAtCard' => $web_so_number,
            'DocDate' => $order->get_date_created()->format('Y-m-d\TH:i:s'),
            'TaxDate' => $order->get_date_created()->format('Y-m-d\TH:i:s'),
            'DocDueDate' => (clone $order->get_date_created())->modify('+' . Config::DEFAULT_DOC_DUE_DAYS . ' days')->format('Y-m-d\TH:i:s'),
            'AccountCode' => $account_code,
            'U_Web_Customer_Name' => substr($customer_name, 0, 100),
            'U_Delivery_Address' => substr($delivery_address, 0, 254),
            'U_Delivery_City' => substr($order->get_shipping_city() ?: $order->get_billing_city(), 0, 100),
            'U_Web_Customer_Mobile_No' => $order->get_billing_phone(),
            'U_Web_Customer_Email' => $order->get_billing_email(),
            'U_Payment_Term' => $is_cod ? 'COD' : 'Prepaid',
            'U_Payment_Method' => Config::map_payment_method($payment_method, $order->get_payment_method_title()),
            'U_Web_Sales_Order_Number' => $web_so_number,
            'DocumentLines' => $document_lines,
        ];

        return $payload;
    }

    /**
     * Build shipping line item for SAP DocumentLines.
     *
     * Shipping is added as a service item line (e.g., NON-00002) with zero-rated VAT.
     * ItemDescription is omitted so SAP uses the item's master data description.
     *
     * @param \WC_Order $order The WooCommerce order.
     * @param string $default_tax_code Default tax code (not used, shipping always uses VAT@00).
     * @return array|null Line item array, or null if no shipping.
     */
    private function build_shipping_line(\WC_Order $order, string $default_tax_code): ?array
    {
        $shipping_total = (float) $order->get_shipping_total();

        if ($shipping_total <= 0) {
            return null;
        }

        $shipping_tax = (float) $order->get_shipping_tax();
        $shipping_item_code = Config::get(Config::OPT_SHIPPING_ITEM_CODE, Config::DEFAULT_SHIPPING_ITEM_CODE);

        // Always use zero-rated VAT for shipping
        $tax_code = 'VAT@00';

        // Calculate price after VAT (total including tax)
        $price_after_vat = $shipping_total + $shipping_tax;

        $this->logger->info(sprintf(
            'Order #%d: Adding shipping line - ItemCode: %s, Amount: %s (inc tax: %s), TaxCode: %s',
            $order->get_id(),
            $shipping_item_code,
            $shipping_total,
            $price_after_vat,
            $tax_code
        ));

        return [
            'ItemCode' => $shipping_item_code,
            'Quantity' => 1,
            'PriceAfterVAT' => round($price_after_vat, 2),
            'TaxCode' => $tax_code,
            'DiscountPercent' => 0,
        ];
    }

    /**
     * Check if Sales Order already exists in SAP (idempotency).
     */
    private function find_existing_sales_order(string $web_so_number): ?array
    {
        try {
            $response = $this->sap_client->get('Orders', [
                '$filter' => SAP_Filter_Builder::equals('NumAtCard', $web_so_number),
                '$select' => 'DocEntry,DocNum',
                '$top' => 1,
            ]);

            if (!empty($response['value'])) {
                return $response['value'][0];
            }
        } catch (\Exception $e) {
            // If lookup fails, proceed with creation
        }

        return null;
    }

    /**
     * Get SAP customer code for order.
     *
     * ALWAYS returns the default SAP customer (e.g., TR-W001) configured in settings.
     * Does NOT create customers in SAP to prevent unwanted master data creation.
     * Customer details are passed to U_ fields in the Sales Order instead.
     */
    private function ensure_customer(\WC_Order $order): string
    {
        // Always use the default SAP customer - no customer creation in SAP
        $default_customer = Config::get(Config::OPT_DEFAULT_CUSTOMER);

        if (empty($default_customer)) {
            throw new \Exception(
                'Default SAP Customer (OPT_DEFAULT_CUSTOMER) is not configured. ' .
                'Please set it in SAP WooCommerce Sync settings (e.g., TR-W001).'
            );
        }

        $this->logger->info(sprintf(
            'Order #%d: Using default SAP customer: %s (Customer: %s)',
            $order->get_id(),
            $default_customer,
            $order->get_formatted_billing_full_name()
        ));

        return $default_customer;

        /* DISABLED: Customer creation in SAP
         *
         * To prevent automatic creation of Business Partners in SAP (which affects
         * critical master data), customer creation is disabled. All orders use the
         * default SAP customer, with actual customer details stored in U_ fields.
         *
         * If customer creation needs to be re-enabled in future, uncomment below:

        global $wpdb;

        $email = $order->get_billing_email();
        $customer_id = $order->get_customer_id();

        // Check local mapping
        if ($customer_id) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT sap_card_code FROM {$wpdb->prefix}" . Config::TABLE_CUSTOMER_MAP . " WHERE wc_customer_id = %d",
                $customer_id
            ));
            if ($existing) {
                return $existing;
            }
        }

        // Check SAP by email
        try {
            $filter = SAP_Filter_Builder::and_filter([
                SAP_Filter_Builder::equals('CardType', 'cCustomer'),
                SAP_Filter_Builder::equals('EmailAddress', $email),
            ]);

            $result = $this->sap_client->get('BusinessPartners', [
                '$filter' => $filter,
                '$select' => 'CardCode',
                '$top' => 1,
            ]);

            if (!empty($result['value'])) {
                $card_code = $result['value'][0]['CardCode'];
                if ($customer_id) {
                    $this->save_customer_mapping($customer_id, $card_code);
                }
                return $card_code;
            }
        } catch (\Exception $e) {
            // Fall through to creation
        }

        // Create new customer with collision-resistant CardCode
        $card_code = Config::CARDCODE_PREFIX . strtoupper(substr(md5($email . uniqid('', true)), 0, Config::CARDCODE_HASH_LENGTH));

        // Verify uniqueness in SAP before creating
        try {
            $existing_bp = $this->sap_client->get("BusinessPartners('{$card_code}')", ['$select' => 'CardCode']);
            if (!empty($existing_bp['CardCode'])) {
                // Collision - regenerate
                $card_code = Config::CARDCODE_PREFIX . strtoupper(substr(md5($email . microtime(true) . random_int(1000, 9999)), 0, Config::CARDCODE_HASH_LENGTH));
            }
        } catch (\Exception $e) {
            // 404 means no collision - proceed
        }

        $countries_with_states = apply_filters('sap_wc_countries_with_states', Config::COUNTRIES_WITH_STATES);

        $billing_street = trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2());
        $shipping_street = trim($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2());

        $billing_address = [
            'AddressName' => 'Billing',
            'AddressType' => 'bo_BillTo',
            'Street' => substr($billing_street, 0, 100),
            'City' => $order->get_billing_city(),
            'Country' => $order->get_billing_country(),
            'ZipCode' => $order->get_billing_postcode(),
        ];

        $shipping_address = [
            'AddressName' => 'Shipping',
            'AddressType' => 'bo_ShipTo',
            'Street' => substr($shipping_street ?: $billing_street, 0, 100),
            'City' => $order->get_shipping_city() ?: $order->get_billing_city(),
            'Country' => $order->get_shipping_country() ?: $order->get_billing_country(),
            'ZipCode' => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
        ];

        if (in_array($order->get_billing_country(), $countries_with_states) && $order->get_billing_state()) {
            $billing_address['State'] = $order->get_billing_state();
        }
        $ship_country = $order->get_shipping_country() ?: $order->get_billing_country();
        if (in_array($ship_country, $countries_with_states)) {
            $state = $order->get_shipping_state() ?: $order->get_billing_state();
            if ($state) {
                $shipping_address['State'] = $state;
            }
        }

        $customer_data = [
            'CardCode' => $card_code,
            'CardName' => $order->get_formatted_billing_full_name(),
            'CardType' => 'cCustomer',
            'EmailAddress' => $email,
            'Phone1' => $order->get_billing_phone(),
            'PriceListNum' => (int) apply_filters('sap_wc_default_price_list', Config::DEFAULT_PRICE_LIST),
            'BPAddresses' => [$billing_address, $shipping_address],
        ];

        try {
            $this->sap_client->post('BusinessPartners', $customer_data);
            if ($customer_id) {
                $this->save_customer_mapping($customer_id, $card_code);
            }
            return $card_code;
        } catch (\Exception $bp_error) {
            $default = Config::get(Config::OPT_DEFAULT_CUSTOMER);
            if (!empty($default)) {
                $this->logger->warning("BP creation failed, using default: {$default}");
                return $default;
            }
            throw $bp_error;
        }
        */
    }

    private function save_customer_mapping(int $customer_id, string $card_code): void
    {
        global $wpdb;
        $wpdb->replace(
            $wpdb->prefix . Config::TABLE_CUSTOMER_MAP,
            [
                'wc_customer_id' => $customer_id,
                'sap_card_code' => $card_code,
                'sync_status' => Sync_Status::SYNCED,
                'last_sync_at' => current_time('mysql'),
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
    }

    private function get_web_so_number(int $order_id): string
    {
        return sprintf('WEB-SO-%06d', $order_id);
    }
}
