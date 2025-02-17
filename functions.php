<?php

const WRONG_PARAM_TYPE_OF_ORDER = 1;
//function checkOrdersToSell($ch, $text, $issueId, $productId, $qty, $cost, $trySell, $trySellUnit){
function trySell($ch, $p, $trySell){

	global $config;
	$debug = $config['debug'];

	$issueId	= $p['vwdId'];
	$productId	= $p['id'];
	$name		= $p['name'];
	$symbol		= $p['symbol'];
	$qty		= $p['qtyAvail'];
	$cost		= $p['breakEvenPrice'];
	$secondaryId	= $p['vwdIdSecondary'];

	$info		= getTradingInfo($ch, $secondaryId);


	$last		= (float) $info['last'];
	$prev		= (float) $info['prev'];
	$totalCost	= (float) ($cost * $qty);
	$lastPrice	= (float) $info['lastPrice'];
	$totalLast	= (float) $lastPrice * $qty;
	$diff		= (float) ($lastPrice * $qty) - ($totalCost);
	$trNr		= $info['trNr'];

	$trySellUn	= normalizeFloat($trySell/$qty);

	if($debug){
		$xCost = normalizeFloat($cost);
		echo date('Y-m-d H:i:s') . "|$symbol \ttrying to sell at $trySell ($trySellUn, costed $xCost), ($trNr variations)";
	}

	#echo "forecast $text: $last, f1: $forecast1, f2: $forecast2|" . round(($forecast1 - $last),4) . "\n";

	if($last == 0 && $prev == 0){
		echo "|no data\n";
		return;
	}

	if($trySell >= $totalLast){ // at least 1€ for comissions
		if($debug)
			echo "|lastPrice $lastPrice ($totalLast) - placing order!!\n";

		$sellingPrice = max($lastPrice, $trySellUn);

		echo date('Y-m-d H:i:s') . "|Placing order for $text to sell at $trySell ($qty * $sellingPrice)\n";
		placeOrder($ch, $productId, $qty, $sellingPrice, "SELL");
	}else{
		if($debug)
			echo "|not high enough to sell. lastPrice $lastPrice ($totalLast) diff: $diff\n";
	}

}


function placeOrder($ch, $productId, $qty, $price, $typeOfOrder){
	if($typeOfOrder != "BUY" && $typeOfOrder != "SELL") {
		throw new \Exception('Param typeOfOrder should be "BUY" o "SELL"', WRONG_PARAM_TYPE_OF_ORDER);
	}

	//$fee = getFee($productId, $qty, $price, $typeOfOrder);
	$confimation = getConfirmation($productId, $qty, $price, $typeOfOrder);
	$confirmationId = $confimation['confirmationId'];
	confirmOrder($ch, $confirmationId, $postParams, $productId, $qty);
}

function getConfirmation($productId, int $qty, $price, string $typeOfOrder) {
	global $config;
	$ch = curl_init();

	$url = $config['tradingUrl'] . 'v5/checkOrder' . ';jsessionid=' . $config['sessionId'] . '?intAccount=' . $config['intAccount'] . '&sessionId=' . $config['sessionId'];
	$postParams = '{"buySell":"' . $typeOfOrder . '","orderType":0,"productId":"' . $productId . '","timeType":1,"size":' . $qty . ',"price":' . $price . '}';

	$headers[] = 'Content-Type: application/json;charset=UTF-8';
	$headers[] = 'Accept: application/json, text/plain, */*';

	curl_setopt_array($ch, [
		CURLOPT_URL				=> $url,
		CURLOPT_HTTPHEADER		=> $headers,
		CURLOPT_POST			=> true,
		CURLOPT_POSTFIELDS		=> $postParams,
		CURLOPT_RETURNTRANSFER	=> true
	]);

	$result = curl_exec($ch);
	if($result != json_decode(json_encode($result), true)){
		die("invalid json\n");
	}
	$result = json_decode($result, true);
	$result = $result['data'];
	return $result;
}

function getFee($productId, int $qty, $price, string $typeOfOrder) {
	$result = getConfirmation($productId, $qty, $price, $typeOfOrder);
	$transactionFees = $result['transactionFees'];

	$totalFee = 0;
	foreach ($transactionFees as $fee) {
		$totalFee += $fee['amount'];
	}

	return $totalFee;
}

function confirmOrder($ch, $confirmationId, $postParams, $productId, $qty){
	global $config;
	$url = 'https://trader.degiro.nl/trading/secure/v5/order/' . $confirmationId . ';jsessionid=' . $config['sessionId'] . '?intAccount=' . $config['intAccount'] . '&sessionId=' . $config['sessionId'];
	curl_setopt_array($ch, [
		CURLOPT_URL				=> $url,
		CURLOPT_POST			=> true,
		CURLOPT_POSTFIELDS		=> $postParams,
	]);

	$result = curl_exec($ch);
	if($result != json_decode(json_encode($result), true)){
		die("invalid json\n");
	}
	$result = json_decode($result, true);
	if(isset($result['errors'])){
		echo "Error confirming order, check result\n";
	}

	if(!empty($result['data'])) {
		return $result['data']['orderId'];
	} else if(count($result['errors']) > 0) {
		foreach ($result['errors'] as $error) {
			echo $error['text'];
		}
	} else {
		die('Unknow error');
	}


	/*if($result['status'] == 0){

		$wrote = 0;
		foreach($selling as $k => $v){
			if($v['productId'] == $productId){
				$wrote = 1;
				$selling[$k]['qty'] = ($selling[$k]['qty'] - $qty);
			}
		}
		if($wrote){
			file_put_contents($runFile, json_encode($selling));
		}else{
			file_put_contents($runFile, "ERROR: could not find $productId" . json_encode($selling));
		}

	}*/
}


function updatePortfolio(){
	global $config;
	$ch = curl_init();
	$header = array(
		 'authority: trader.degiro.nl'
		,'cache-control: max-age=0'
		,'accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8'
		,'accept-encoding: gzip, deflate, br'
		,'accept-language: en-GB,en;q=0.9,pt-PT;q=0.8,pt;q=0.7'
		,'cache-control: max-age=0'
		,'upgrade-insecure-requests: 1'
		,'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/72.0.3626.121 Safari/537.36'
	);

	$url = $config['tradingUrl'] . "v5/update/" . $config['intAccount'] . ";jsessionid=" . $config['sessionId'] . "?portfolio=0";


	curl_setopt_array($ch, [
		CURLOPT_URL				=> $url,
		CURLOPT_RETURNTRANSFER	=> true
	]);


	$result = curl_exec($ch);
	$info = curl_getinfo($ch);
	if($info['http_code'] != 200 && $info['http_code'] != 201){
		echo "error getting portfolio.. lets use cache (or remove cookie file to reload)\n";
		die();
		$result = file_get_contents(__DIR__ . '/portfolio.json');
	}

	$result = json_decode($result,true);
	$portfolio = array();
	$cash = false;
	$searchProductIds = array();
	$openOrders = getOpenOrders($ch);


	foreach($result['portfolio']['value'] as $k => $p){
		$productId = $p['id'];

		if(is_numeric($productId)){
			if(isset($p['value'])){
				foreach ($p['value'] as $info){
					if(isset($info['value'])){
						$name = $info['name'];
							$portfolio["$productId"][$name] = $info['value'];
					}
				}
			}
		}
	}

	foreach($portfolio as $k => $p){
		if($p['size'] == 0){
			unset($portfolio[$k]);
			continue;
		}
		if($p['positionType'] == 'CASH'){
			unset($portfolio[$k]);
			$cash = $p;
			continue;
		}
		$s = array_search($k, array_column($openOrders, 'productId')); // search if this product id is in a openOrder
		if($s !== false){
			$portfolio["$k"]['qtyAvail'] = $p['size'] - $openOrders["$s"]['size'];
		}else{
			$portfolio["$k"]['qtyAvail'] = $p['size'];
		}
		$searchProductIds[] = $k;
	}
	$productInfo = getArrayProducts($searchProductIds);

	foreach($portfolio as $k => $p){
		$portfolio["$k"]['name'] = $productInfo["$k"]['name'];
		$portfolio["$k"]['vwdId'] = $productInfo["$k"]['vwdId'];
		$portfolio["$k"]['symbol'] = $productInfo["$k"]['symbol'];
		$portfolio["$k"]['vwdIdSecondary'] = $productInfo["$k"]['vwdIdSecondary'];
	}

	#if($force){
		$cache = json_encode($portfolio, JSON_PRETTY_PRINT);
		file_put_contents(__DIR__ . '/portfolio.json', json_encode($cache, JSON_PRETTY_PRINT));
	#}

	return $portfolio;
}

/**
 * Retrieve the active buy/sell orders
 * https://trader.degiro.nl/trader/#/orders/open
 */
function getOpenOrders(){
	global $config;
	$ch = curl_init();

	$cookieFile = $config['cookieFile'];

	$url = $url = $config['tradingUrl'] . "v5/update/" . $config['intAccount'] . ";jsessionid=" . $config['sessionId'] . "?orders=0";
	curl_setopt_array($ch, [
		CURLOPT_URL				=> $url,
		CURLOPT_RETURNTRANSFER	=> true
	]);
	$result = curl_exec($ch);
	$result = json_decode($result,true);

	//return $result['orders']['value'];
	$orders = array();

	foreach($result['orders']['value'] as $k => $order){
		foreach($order['value'] as $o){
			$name = $o['name'];
			$value = $o['value'];
			$orders["$k"]["$name"] = $value;
		}
	}
	return $orders;
}

function checkProspects($ch, $zone){
	// NASDAQ
	// https://trader.degiro.nl/product_search/secure/v5/stocks?exchangeId=663&stockCountryId=846&offset=0&limit=500&requireTotal=true&sortColumns=name&sortTypes=asc
	global $config;
	$userToken = $config['clientId'];
	$intAccount = $config['intAccount'];
	$sessionId = $config['sessionId'];

	$url = "https://trader.degiro.nl/product_search/secure/v5/stocks?exchangeId=663&stockCountryId=846&offset=0&limit=500&requireTotal=true&sortColumns=name&sortTypes=asc&intAccount=$intAccount&sessionId=$sessionId";
	if($zone == 'pt'){
		$url = "https://trader.degiro.nl/product_search/secure/v5/stocks?stockCountryId=954&&offset=0&limit=500&requireTotal=true&sortColumns=name&sortTypes=asc&intAccount=$intAccount&sessionId=$sessionId";
	}
	curl_setopt_array($ch, [
		CURLOPT_URL		=> $url,
		CURLOPT_POST	=> false,
	]);
	$result = curl_exec($ch);
	$result = json_decode($result,true);
	#var_dump($url);
	#var_dump($result);

	#var_dump($result);
	foreach($result['products'] as $p){
		#$name = ""
		#$res[] = $p['closePrice'];
		if(isset($p['closePrice']) && (float)$p['closePrice'] < 1){
			#echo $p['closePrice'] . '|' . $p['name'] . "\n";

			$name = $p['name'];
			$closed = $p['closePrice'];
			$issueId = $p['vwdId'];
			$productId = $p['id'];

			$res["$name"] = array('name' => $name, 'closed' => $closed, 'issueId' => $issueId, 'productId' => $productId);
		}
	}
	#var_dump($res);

	foreach($res as $k => $v){
		$info = getTradingInfo($ch, $v['issueId']);
		if(!isset($info['lowPrice']) || !is_float($info['lowPrice'])){
			continue;
		}
		#var_dump($info);
		$lowP = $info['lowPrice'];
		$highP = $info['highPrice'];
		$last = $info['last'];
		$trNr = $info['trNr'];
		$avg = $info['avg'];
		$diffAvg = $info['diffAvg'];

		if($last > 0 && $lowP > 0 && $info['lowPrice']){
			$diff = $highP - $lowP;
			$score = $diffAvg * $trNr;
			$name = $v['name'];
			echo "$score|$trNr|$name|$lowP|$highP\n";
			#echo "$diffAvg|$diff|$trNr|$avg|" . $v['name'] . "|$lowP|$highP\n";
		}
	}
}


function getTradingInfo($ch, $issueId){
	global $config;

	$productId = 0;

	$url = "https://charting.vwdservices.com/hchart/v1/deGiro/data.js?requestid=1&resolution=PT1M&culture=en-US&period=P1D&series=issueid:$issueId&series=price:issueid:$issueId&format=json&userToken=" . $config['clientId'] . "&tz=Europe/Lisbon";
	#$url = "https://charting.vwdservices.com/hchart/v1/deGiro/data.js?requestid=1&resolution=PT1M&culture=en-US&period=P1W&series=issueid:$issueId&series=price:issueid:$issueId&format=json&userToken=$userToken&tz=Europe/Lisbon";

	curl_setopt_array($ch, [
		CURLOPT_URL		=> $url,
		CURLOPT_POST	=> false,
		CURLOPT_RETURNTRANSFER	=> true
	]);
	$result = curl_exec($ch);
	$result = json_decode($result,true);

	$prev = 0;
	$last = 0;
	if(isset($result['series'][1]['data'])){
		$trNr = count($result['series'][1]['data']); // trading volume today
	}else{
		return array();
		$trNr = 0;
	}


	foreach($result['series'][1]['data'] as $k => $v){
		$v0 = $v[0];
		$v1 = $v[1];
		$samples[] = array($v0);
		$labels[] = $v1;
	}
	if($trNr > 1){
		$avg = array_sum(array_filter($labels))/$trNr;
		$ll = array_key_last($labels);
		foreach($labels as $k => $v){
			if(($k) != $ll){
				$diffAvg[] = abs($v - $labels[$k + 1]);
			}
		}
		$diffAvg = array_sum(array_filter($diffAvg))/$ll;
	}else{
		$avg = 0;
		$diffAvg = 0;
	}

	if($trNr > 1){
		$prev = array_slice($result['series'][1]['data'], -2, 1)[0][1];
		$last = array_slice($result['series'][1]['data'], -1)[0][1];
	}

	$ret = array(
		'quality'	=> $result['series'][0]['data']['quality'],
		'lastPrice'	=> $result['series'][0]['data']['lastPrice'],
		'lastTime'	=> $result['series'][0]['data']['lastTime'],
		'issueId'	=> $result['series'][0]['data']['issueId'],
		'lowPrice'	=> $result['series'][0]['data']['lowPrice'],
		'highPrice'	=> $result['series'][0]['data']['highPrice'],
	//	'forecast1'	=> $p1,
	//	'forecast2'	=> $p2,
		'prev'		=> $prev,
		'last'		=> $last,
		'trNr'		=> $trNr,
		'avg'		=> $avg,
		'diffAvg'	=> $diffAvg,
	);


	return $ret;
}

function getDegiroConfig(){
	global $config;
	$ch = curl_init();
	$url = 'https://trader.degiro.nl/login/secure/config';
	$header=array();
	$cookieFile = __DIR__ . '/cookie.txt';
	curl_setopt_array($ch, [
		CURLOPT_URL				=> $url,
		CURLOPT_HTTPHEADER		=> $header,
		CURLOPT_RETURNTRANSFER	=> true,
		CURLOPT_COOKIEFILE		=> $cookieFile,
		CURLOPT_COOKIEJAR		=> $cookieFile,
		CURLOPT_POST			=> false,
		CURLOPT_HTTP_VERSION	=> CURL_HTTP_VERSION_2_0,
		CURLOPT_ENCODING		=> '',
	]);

	$result = curl_exec($ch);
	$info = curl_getinfo($ch);
	if($info['http_code'] != 200){
		die('could not get config');
	}

	// verify json
	if($result != json_decode(json_encode($result), true)){
			die("invalid json\n");
	}
	$result = json_decode($result, true);

	$clientId = $result['data']['clientId'];

	foreach($result['data'] as $k => $v){
		$config["$k"] = $v;
	}

	$paUrl = $config['paUrl'] . 'client?sessionId=' . $config['sessionId'];

	curl_setopt($ch, CURLOPT_URL, $paUrl);
	$result = curl_exec($ch);
	//$result = (gzdecode($result));

	if($result != json_decode(json_encode($result), true)){
			die("invalid json\n");
	}
	$result = json_decode($result, true);

	$config['intAccount'] = $result['data']['intAccount'];
	#define('intAccount', $result['data']['intAccount']);
	return $info['http_code'];

}

function checkLogin(){
	/*******
	 * Test access to config, then PaUrl to get clientId,
	 * and then portfolio.
	 * If any fails, we need to generate a new cookie?
	 */
	global $config;
	$ch = curl_init();

	/** Test access to config **/
	$headers[] = 'Content-Type: application/json;charset=UTF-8';
	$headers[] = 'Accept: application/json, text/plain, */*';
	$url = 'https://trader.degiro.nl/login/secure/config';
	curl_setopt_array($ch, [
		CURLOPT_URL				=> $url,
		CURLOPT_HTTPHEADER		=> $headers,
		CURLOPT_RETURNTRANSFER	=> true,
		CURLOPT_COOKIEFILE		=> $config['cookieFile'],
		CURLOPT_COOKIEJAR		=> $config['cookieFile'],
		CURLOPT_ENCODING		=> '',
	]);
	$result = curl_exec($ch);
	$info = curl_getinfo($ch);
	if($info['http_code'] != 200 || $result != json_decode(json_encode($result), true))
		return false;

	$result = json_decode($result, true);
	foreach($result['data'] as $k => $v)
		$config["$k"] = $v;

	/** get intAccount (clientId) from PaUrl **/
	$paUrl = $config['paUrl'] . 'client?sessionId=' . $config['sessionId'];
	curl_setopt($ch, CURLOPT_URL, $paUrl);
	$result = curl_exec($ch);

	if($info['http_code'] != 200 || $result != json_decode(json_encode($result), true))
		return false;

	$result = json_decode($result, true);
	$config['intAccount'] = $result['data']['intAccount'];

	/** Test access to portfolio **/
	$headers = array('accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8');
	$url = $config['tradingUrl'] . "v5/update/" . $config['intAccount'] . ";jsessionid=" . $config['sessionId'] . "?portfolio=0";
	curl_setopt_array($ch, [
		CURLOPT_URL				=> $url,
		CURLOPT_HTTPHEADER		=> $headers,
	]);
	$result = curl_exec($ch);
	$info = curl_getinfo($ch);
	if($info['http_code'] != 200 && $info['http_code'] != 201)
		return false;

	return true;

}

function webLogin($ch){
	global $config;

	curl_close($ch);
	unset($ch);
	$cookieFile = $config['cookieFile'];
	unlink($cookieFile);
	$ch = curl_init();
	if(!file_exists($cookieFile)){
		touch($cookieFile);
	}

	// login parameters
	$username = $config['username'];
	$password = $config['password'];
	$params = '{"username":"' . $username . '","password":"' . $password . '","isPassCodeReset":false,"isRedirectToMobile":false,"queryParams":{}}';
	echo "logging in...\n";

	$url = 'https://trader.degiro.nl/login/secure/login';

	$headers = array();
	$headers[] = 'Origin: https://trader.degiro.nl';
	$headers[] = 'Accept-Encoding: gzip, deflate, br';
	$headers[] = 'Accept-Language: en-GB,en;q=0.9,pt-PT;q=0.8,pt;q=0.7';
	$headers[] = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/72.0.3626.121 Safari/537.36';
	$headers[] = 'Content-Type: application/json;charset=UTF-8';
	$headers[] = 'Accept: application/json, text/plain, */*';
	$headers[] = 'Referer: https://trader.degiro.nl/login/pt';
	$headers[] = 'Authority: trader.degiro.nl';
	$headers[] = 'Dnt: 1';

	curl_setopt_array($ch, [
		CURLOPT_URL			=> $url,
		CURLOPT_RETURNTRANSFER		=> true,
		CURLOPT_HTTPHEADER		=> $headers,
		CURLOPT_COOKIEFILE		=> $cookieFile,
		CURLOPT_COOKIEJAR		=> $cookieFile,
		CURLOPT_POST			=> true,
		CURLOPT_POSTFIELDS		=> $params,
		CURLOPT_ENCODING		=> '',
		//CURLINFO_HEADER_OUT		=> 1, // debug
	]);
	$result = curl_exec($ch);
	$info = curl_getinfo($ch);

	return $info['http_code'];
}


/**
 * Get a list with the ids of the products marked as favorite
 * https://trader.degiro.nl/trader/#/favourites/1153120
 */
function getFavoritesIds() {
	global $config;
	$intAccount = $config['intAccount'];
	$sessionId = $config['sessionId'];

	$url = "https://trader.degiro.nl/pa/secure/favourites/lists?" . $intAccount . "&sessionId=" . $sessionId;
	$ch = curl_init();

	curl_setopt_array($ch, [
		CURLOPT_URL		=> $url,
		CURLOPT_RETURNTRANSFER	=> true
	]);

	$result = curl_exec($ch);
	checkStatus(curl_getinfo($ch), 'could not get favourites');
	$result = json_decode($result,true);
	curl_close($ch);

	return $result['data'][0]['productIds'];

}

function searchProduct($search) {
	global $config;
	$intAccount = $config['intAccount'];
	$sessionId = $config['sessionId'];

	$url = "https://trader.degiro.nl/product_search/secure/v5/products/lookup?offset=0&limit=10&searchText=" . $search . "&intAccount=" . $intAccount . "&sessionId=" . $sessionId;
	$ch = curl_init();

	curl_setopt_array($ch, [
		CURLOPT_URL		=> $url,
		CURLOPT_RETURNTRANSFER	=> true
	]);

	$result = curl_exec($ch);
	checkStatus(curl_getinfo($ch), 'could not get favourites');
	$result = json_decode($result,true);
	curl_close($ch);

	return $result['products'];
}

/**
 * Get a list with the products marked as favorite
 */
function getFavoriteProducts() {
	return getArrayProducts(getFavoritesIds());
}


/**
 * Get the information of an array of products using their id's
 * @param $ids - Array of integers
 * @return Array with the info of products
 */
function getArrayProducts($ids) {
	global $config;
	$intAccount = $config['intAccount'];
	$sessionId = $config['sessionId'];

	$url = "https://trader.degiro.nl/product_search/secure/v5/products/info?intAccount=$intAccount&sessionId=$sessionId";
	$ch = curl_init($url);

	$headers = array(
		'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/72.0.3626.121 Safari/537.36',
		'Content-Type: application/json;charset=UTF-8'
	);

	curl_setopt_array($ch, [
		CURLOPT_HTTPHEADER		=> $headers,
		CURLOPT_POSTFIELDS		=> json_encode($ids),
		CURLOPT_POST			=> true,
		CURLOPT_RETURNTRANSFER		=> true
	]);


	$result = curl_exec($ch);
	checkStatus(curl_getinfo($ch), 'could not get products');
	curl_close($ch);
	return json_decode($result, true)['data'];
}


function checkStatus($info, $msg) {
	if($info['http_code'] != 200){
		die($msg);
	}
}

function checkJson($json){
	if($json != json_decode(json_encode($json), true)){
		if($debug)
			var_dump($json);
		return false;
	}
	return true;
}

/**
 *	A valid price must be a multiple of 0.0100
 */
function normalizeFloat($value){
	return round($value, 2);
}

if (! function_exists("array_key_last")) {
    function array_key_last($array) {
        if (!is_array($array) || empty($array)) {
            return NULL;
        }

        return array_keys($array)[count($array)-1];
    }
}
