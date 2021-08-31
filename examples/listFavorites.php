<?php

	require_once(__DIR__ . '/../config.php'); // configuration
	require_once(__DIR__ . '/../functions.php'); // global functions

	$ch = curl_init(); // web browser
	$login = webLogin($ch);
	if($login != 200)
		die("login failed\n");

	getDegiroConfig($ch);


	$favs = getFavoriteProducts();
	foreach($favs as $fav) {
		echo $fav['id'] . ' ' . $fav['name'] . "\n";
	}

