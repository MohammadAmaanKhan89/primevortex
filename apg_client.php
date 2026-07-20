<?php


  
    $url = "https://sandbox.bankalfalah.com/HS/HS/HS";
	 
	 //$url = "https://payments.bankalfalah.com/HS/HS/HS";
        
            
          // $bankorderId   = $this->session->userdata('bankorderId');
         $bankorderId   = rand(0,1786612);
        
        
        $Key1= "7FVMCZWDuvyKSUjK";
        $Key2= "5053296132723986";
        $HS_ChannelId="1001";
        $HS_MerchantId="265397" ;
        $HS_StoreId="560844" ;
        $HS_IsRedirectionRequest  = 0;
        $HS_ReturnURL="https://google.com";
        $HS_MerchantHash="OUU362MB1urEv2JGNWAMrfLdElW+v9hsxG/F/FH5Jaov2pbFGN2JF4mEZ8rZPG5B";
        $HS_MerchantUsername="setyno" ;
        $HS_MerchantPassword="QVSL8wVp325vFzk4yqF7CA==";
        $HS_TransactionReferenceNumber= $bankorderId;
         $transactionTypeId = "3";
	    $TransactionAmount = "3";   
		
		   $cipher="aes-128-cbc";
	    
	    
        $mapString = 
          "HS_ChannelId=$HS_ChannelId" 
        . "&HS_IsRedirectionRequest=$HS_IsRedirectionRequest" 
        . "&HS_MerchantId=$HS_MerchantId" 
        . "&HS_StoreId=$HS_StoreId" 
        . "&HS_ReturnURL=$HS_ReturnURL"
        . "&HS_MerchantHash=$HS_MerchantHash"
        . "&HS_MerchantUsername=$HS_MerchantUsername"
        . "&HS_MerchantPassword=$HS_MerchantPassword"
        . "&HS_TransactionReferenceNumber=$HS_TransactionReferenceNumber";
        
     
        $cipher_text = openssl_encrypt($mapString, $cipher, $Key1,   OPENSSL_RAW_DATA , $Key2);
        $hashRequest = base64_encode($cipher_text);
	
		
        
        //The data you want to send via POST
        $fields = [
            "HS_ChannelId"=>$HS_ChannelId,
            "HS_IsRedirectionRequest"=>$HS_IsRedirectionRequest,
            "HS_MerchantId"=> $HS_MerchantId,
            "HS_StoreId"=> $HS_StoreId,
            "HS_ReturnURL"=> $HS_ReturnURL,
            "HS_MerchantHash"=> $HS_MerchantHash,
            "HS_MerchantUsername"=> $HS_MerchantUsername,
            "HS_MerchantPassword"=> $HS_MerchantPassword,
            "HS_TransactionReferenceNumber"=> $HS_TransactionReferenceNumber,
            "HS_RequestHash"=> $hashRequest
        ];
        
        $fields_string = http_build_query($fields);
        
        //open connection
        $ch = curl_init();
        //set the url, number of POST vars, POST data
        curl_setopt($ch,CURLOPT_URL, $url);
        curl_setopt($ch,CURLOPT_POST, true);
        curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
        //So that curl_exec returns the contents of the cURL; rather than echoing it
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, true); 
        //execute post
        $result = curl_exec($ch);
        
       $handshake =  json_decode($result);
       
        $AuthToken = $handshake->AuthToken;
        
        
	    echo $result ."<br>";
	    echo $AuthToken ."<br>";
	  
	  
	  /* ==============SSO CALL ================*/
	  
	  // you need Auth Token & Amount Here before Hashing
	  
	  
	  
	
$RequestHash1 = NULL;
$Currency = "PKR";
$IsBIN =0;

$mapStringSSo = 
  "AuthToken=$AuthToken" 
. "&RequestHash=$RequestHash1" 
. "&ChannelId=$HS_ChannelId"
. "&Currency=$Currency"
. "&IsBIN=$IsBIN"
. "&ReturnURL=$HS_ReturnURL"
. "&MerchantId=$HS_MerchantId"
. "&StoreId=$HS_StoreId" 
. "&MerchantHash=$HS_MerchantHash"
. "&MerchantUsername=$HS_MerchantUsername"
. "&MerchantPassword=$HS_MerchantPassword"
. "&TransactionTypeId=3"
. "&TransactionReferenceNumber=$HS_TransactionReferenceNumber"
. "&TransactionAmount=$TransactionAmount";



		echo $mapStringSSo."<br>";
		
		 
$cipher_text = openssl_encrypt($mapStringSSo, $cipher, $Key1,   OPENSSL_RAW_DATA , $Key2);
$hashRequest1 = base64_encode($cipher_text);

echo $hashRequest1;



?>

                                                                                                                                                               
  

		
		
        <form action="https://sandbox.bankalfalah.com/SSO/SSO/SSO" id="PageRedirectionForm" method="post" novalidate="novalidate">                                                              
     	<input id="AuthToken" name="AuthToken" type="hidden" value="<?php echo $AuthToken; ?>">                                                                                                                                
     	<input id="RequestHash" name="RequestHash" type="hidden" value="<?php echo $hashRequest1; ?>">                                                                                                                            
     	<input id="ChannelId" name="ChannelId" type="hidden" value="<?php echo $HS_ChannelId; ?>">                                                                                                                            
     	<input id="Currency" name="Currency" type="hidden" value="PKR">                                                                                                                               
         <input id="IsBIN" name="IsBIN" type="hidden" value="0">                                                                                     
     	<input id="ReturnURL" name="ReturnURL" type="hidden" value="https://google.com">                                                                            
         <input id="MerchantId" name="MerchantId" type="hidden" value="<?php echo $HS_MerchantId;?>">                                                                                                                           
         <input id="StoreId" name="StoreId" type="hidden" value="<?php echo $HS_StoreId;?>">                                                                                                                     
     	<input id="MerchantHash" name="MerchantHash" type="hidden" value="<?php echo $HS_MerchantHash;?>">                                  
     	<input id="MerchantUsername" name="MerchantUsername" type="hidden" value="<?php echo $HS_MerchantUsername;?>">                                                                                                            
     	<input id="MerchantPassword" name="MerchantPassword" type="hidden" value="<?php echo $HS_MerchantPassword;?>">  
    <input id="TransactionTypeId" name="TransactionTypeId" type="hidden" value="3"> 
      
                                                                                                                                                                              
     	<input autocomplete="off" id="TransactionReferenceNumber" name="TransactionReferenceNumber" placeholder="Order ID" type="hidden" value="<?php echo $HS_TransactionReferenceNumber;?>">                                  
    	<input autocomplete="off"  id="TransactionAmount" name="TransactionAmount" placeholder="Transaction Amount" type="hidden" value="<?php echo $TransactionAmount; ?>">  
          
     
				 <br>
     <center>	<button type="submit" class="btn btn-custon-four btn-danger" id="run">PAY ONLINE</button>        </center>                                                                                                    
     </form> 
				
		
		
		

		
		