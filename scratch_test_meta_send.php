<?php
$con = mysqli_connect("localhost", "root", "", "starfi_crm");
if ($con) {
    // Sede 23
    $res = mysqli_query($con, "SELECT l.meta_telefono_id, l.meta_token FROM lineas_whatsapp l WHERE l.id_sede = 23");
    if ($row = mysqli_fetch_assoc($res)) {
        $telefonoID = $row['meta_telefono_id'];
        $token = $row['meta_token'];
        
        echo "Sede 23 - Phone ID: $telefonoID\n";
        echo "Sede 23 - Token (first 30 chars): " . substr($token, 0, 30) . "...\n";
        
        $url = 'https://graph.facebook.com/v23.0/'.$telefonoID.'/messages';
        $header = array("Authorization: Bearer " . $token, "Content-Type: application/json");
        
        $mensaje ='{"messaging_product": "whatsapp", 
                    "to": "584241660944", 
                    "type": "template", 
                    "template": { 
                       "namespace": "be78987b_011f_4891_8e13_cd03b764fb99", 
                       "name": "send_confirmacion_compra_cn_sn_btn", 
                       "language": { 
                           "code": "es",
                           "policy": "deterministic" 
                           },
                            "components": [{
            "type": "body",
            "parameters": [
                { "type": "text", "text": "TEST RONAL" },
                { "type": "text", "text": "30-05-2026" },
                { "type": "text", "text": "1.44 USD" },
                { "type": "text", "text": "Asesor Test" },
                { "type": "text", "text": "MADEPEG" },
                { "type": "text", "text": "000001" },
                { "type": "text", "text": "0000000000" }
                    ]
        }]
                }
        }';
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $mensaje);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        
        $res_curl = curl_exec($curl);
        $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        echo "Meta HTTP Status Code: $status_code\n";
        echo "Meta Response Body:\n$res_curl\n";
    } else {
        echo "No config found for Sede 23.\n";
    }
    mysqli_close($con);
}
?>
