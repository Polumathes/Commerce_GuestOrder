<?php
header('Cache-Control: no cache');

// Get form values
$order = $modx->getOption("order", $scriptProperties, $_REQUEST["order"]);
$values = $modx->getOption("values", $scriptProperties, $_REQUEST["values"]);
$secret = $modx->getOption("secret", $scriptProperties, $_REQUEST["secret"]);

// Enable direct access with order ID and secret from comOrder
$useSecret = (bool)$modx->getOption('useSecret', $scriptProperties, true);
// Comma seperated list of comOrder fields to validate against
$fields = explode(",", $modx->getOption("fields", $scriptProperties, "zip"));
// Address types to use. shipping, billing, or both (default)
$addressType = $modx->getOption('addressType', $scriptProperties, "both");

// Template settings
$tpl = $modx->getOption('tpl', $scriptProperties, 'frontend/account/order-detail.twig');
$formTpl = $modx->getOption('formTpl', $scriptProperties, 'GetGuestOrderForm');
$errorTpl = $modx->getOption('errorTpl', $scriptProperties, 'GetGuestOrderError');
$loadItems = (bool)$modx->getOption('loadItems', $scriptProperties, true);
$loadStatus = (bool)$modx->getOption('loadStatus', $scriptProperties, true);
$loadTransactions = (bool)$modx->getOption('loadTransactions', $scriptProperties, true);
$loadBillingAddress = (bool)$modx->getOption('loadBillingAddress', $scriptProperties, true);
$loadShippingAddress = (bool)$modx->getOption('loadShippingAddress', $scriptProperties, true);

if (isset($order) && is_numeric($order)) {
    // Initialize Commerce
    $path = $modx->getOption('commerce.core_path', null, MODX_CORE_PATH . 'components/commerce/') . 'model/commerce/';
    $params = ['mode' => $modx->getOption('commerce.mode')];
    $commerce = $modx->getService('commerce', 'Commerce', $path, $params);
    
    if (!($commerce instanceof Commerce)) {
        $modx->log(modX::LOG_LEVEL_ERROR, 'Could not load Commerce service in GetGuestOrder snippet.');
        return 'Could not load Commerce. Please try again later.';
    }
    
    if ($commerce->isDisabled()) {
        return $commerce->adapter->lexicon('commerce.mode.disabled.message');
    }
    $modx->lexicon->load('commerce:frontend');

    // Allowed order classes
    $allowedClasses = ['comProcessingOrder', 'comCompletedOrder'];
    foreach ($allowedClasses as $ac) {
        $allowedClasses = array_merge($allowedClasses, $modx->getDescendants($ac));
    }
    $allowedClasses = array_unique($allowedClasses);

    // Build the order query, looking for only guest orders for inputted ID
    $orderQuery = $modx->newQuery('comOrder');
    $orderQuery->where([
        'id' => $order,
        'user' => 0,
        'test' => $commerce->isTestMode(),
        'class_key:IN' => $allowedClasses
    ]);
    $order = $modx->getObject('comOrder', $orderQuery);
    
    // Check if the order actually exists.
    if (!$order) {
        return $modx->getChunk($errorTpl, ['order' => $order]);
    }
    
    if ($useSecret && isset($secret)) {
        if ($order->get('secret') !== $secret) {
            return $modx->getChunk($errorTpl, ['order' => $order]);
        }
    } else {
        $orderAddressQuery = $modx->newQuery('comOrderAddress');
        $orderAddressQuery->where([
            'order' => $order->get('id'),
        ]);
        if ($addressType !== "both") {
            $orderAddressQuery->where([
                'type' => $addressType    
            ]);
        }
        $orderAddresses = $modx->getCollection("comOrderAddress", $orderAddressQuery);

        if (!$orderAddresses) {
            return $modx->getChunk($errorTpl, ['order' => $order]);
        }

        // Loop over each order address, getting the individual address. Checks each required field against comAddress object.
        foreach ($orderAddresses as $orderAddress) {
            $addressQuery = $modx->newQuery('comAddress');
            $addressQuery->where([
                'id' => $orderAddress->get('address')
            ]);
            $address = $modx->getObject('comAddress', $addressQuery);

            if (!$address) {
                return $modx->getChunk($errorTpl, ['order' => $order]);
            }

            foreach ($fields as $field) {
                if ($address->get($field) !== $values[$field]) {
                    return $modx->getChunk($errorTpl, ['order' => $order]);
                }
            }
        }
    }
    
    // Grab the data and output into twig
    $data = [];
    $data['order'] = $order->toArray();
    $data['state'] = $order->getState();
    
    if ($loadItems) {
        $items = [];
        foreach ($order->getItems() as $item) {
            $ta = $item->toArray();
            if ($product = $item->getProduct()) {
                $ta['product'] = $product->toArray();
            }
            $items[] = $ta;
        }
        $data['items'] = $items;
    }
    if ($loadStatus) {
        $status = $order->getStatus();
        $data['status'] = $status->toArray();
    }
    
    if ($loadTransactions) {
        $trans = [];
        $transactions = $order->getTransactions();
        foreach ($transactions as $transaction) {
            if ($transaction->isCompleted()) {
                $traa = $transaction->toArray();
                if ($method = $transaction->getMethod()) {
                    $traa['method'] = $method->toArray();
                }
                $trans[] = $traa;
            }
        }
        $data['transactions'] = $trans;
    }
    
    if ($loadBillingAddress) {
        $ba = $order->getBillingAddress();
        $data['billing_address'] = $ba->toArray();
    }
    if ($loadShippingAddress) {
        $sa = $order->getShippingAddress();
        $data['shipping_address'] = $sa->toArray();
    }
    
    if ($tpl !== '') {
        try {
            $output = $commerce->twig->render($tpl, $data);
        }
        catch(Exception $e) {
            $modx->log(modX::LOG_LEVEL_ERROR, 'Error processing GetGuestOrder snippet on resource #' . $modx->resource->get('id') . ' - twig threw exception ' . get_class($e) . ': ' . $e->getMessage());
            return 'Sorry, could not show your order.';
        }
    } else {
        $output = '<pre>' . print_r($data, true) . '</pre>';
    }
    
    return $output;
    
} else {
    // The form chunk to display when an order is not set
    return $modx->getChunk($formTpl);
}