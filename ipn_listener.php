<?php 

	// $url = $_POS['url'];
	 $url = "https://payments.bankalfalah.com/HS/api/IPN/OrderStatus/{Merchantid}/{Storeid}/{Orderid}";
	 
	 
	
	
	 
$curlSession = curl_init();
curl_setopt($curlSession, CURLOPT_URL,  $url);
curl_setopt($curlSession, CURLOPT_BINARYTRANSFER, true);
curl_setopt($curlSession, CURLOPT_RETURNTRANSFER, true);

$jsonData = json_decode(curl_exec($curlSession));
curl_close($curlSession);

     $result =  json_decode($jsonData);
echo $jsonData."<br>";


echo $result->TransactionStatus;


// write your query here to update the data base

// Response parameters {"ResponseCode":"00","Description":"Success","MerchantId":"4019","MerchantName":"DAILY DELI CO","StoreId":"012838","StoreName":"DAILY DELI CO","TransactionTypeId":"3","TransactionReferenceNumber":"1119461","OrderDateTime":"9/16/2021 5:13:32 PM","TransactionId":"20210916171137461","TransactionDateTime":"9/16/2021 5:13:32 PM","AccountNumber":null,"TransactionAmount":"3.00","MobileNumber":"+923323714713","TransactionStatus":"Paid"}

exit;



?>