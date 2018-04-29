<?php
header('Cache-Control: no cache');

// Inputted order id.
$order = $modx->getOption("order", $scriptProperties, $_POST["order"]);
// Array of inputted field values
$values = $modx->getOption("values", $scriptProperties, $_POST["values"]);

// Allowed fields in modx_commerce_address to use.  TODO add to system settings instead
$fields = explode(",", $modx->getOption("fields", $scriptProperties, "zip"));
$allowedFields = ["properties", "user", "fullname", "firstname", "lastname", "company", "address1", "address2", "address3", "zip", "city", "state", "country", "phone", "mobile", "email"];

// Defaults to commerce template twig order detail page.
$tpl = (string)$modx->getOption('tpl', $scriptProperties, 'frontend/account/order-detail.twig');
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
        $modx->log(modX::LOG_LEVEL_ERROR, 'Could not load Commerce service in GuestOrder snippet.');
        return 'Could not load Commerce. Please try again later.';
    }
    
    if ($commerce->isDisabled()) {
        return $commerce->adapter->lexicon('commerce.mode.disabled.message');
    }
    $modx->lexicon->load('commerce:frontend');


    // Check if class is processing or completed, don't want drafts. TODO add to system settings instead?
    $allowedClasses = ['comProcessingOrder', 'comCompletedOrder'];
    foreach ($allowedClasses as $ac) {
        $allowedClasses = array_merge($allowedClasses, $modx->getDescendants($ac));
    }
    $allowedClasses = array_unique($allowedClasses);

    // Build the order query, looking for only guest orders for inputted ID
    $orderQuery = $commerce->adapter->newQuery('comOrder');
    $orderQuery->select('comOrderAddress.address');
    $orderQuery->select($modx->getSelectColumns('comOrder', 'comOrder'));
    $orderQuery->innerJoin('comOrderAddress','comOrderAddress', ["comOrder.id = comOrderAddress.order"]);
    $orderQuery->where([
        'comOrderAddress.order:=' => $order,
        'comOrder.id:=' => $order,
        'comOrder.user:=' => 0,
        'comOrder.test:=' => $commerce->isTestMode(),
        'comOrder.class_key:IN' => $allowedClasses
    ]);
    $orderQuery->prepare();
    print $orderQuery->toSQL();
    $order = $commerce->adapter->getObject('comOrder', $orderQuery);
    
    //print_r($order->toArray());
    // Check if the order actually exists. TODO custom not found tpl
    if (!$order) {
        return $modx->sendErrorPage();
    }

    
    // Loop over each order address, getting the individual address (gets both billing & shipping). Checks each required field against comAddress object.
    $addressQuery = $commerce->adapter->newQuery('comAddress');
    $addressQuery->where([
        'id' => $order->get('address')
    ]);
    $address = $commerce->adapter->getObject('comAddress', $addressQuery);
    if($address) {
        $addresses[] = $address->toArray();
        
        // Check each required field
        foreach ($fields as $field) {
            if ($address->get($field) !== $values[$field]) {
                return $modx->sendErrorPage();
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
}