<?php
// --- Configuration ---
$accountName = "globalsharestorage13"; // REMPLACEZ PAR LE NOM DE VOTRE COMPTE DE STOCKAGE
$containerName = "photos";
// --- Fin de la configuration ---

// Fonction pour obtenir un jeton d'authentification via l'Identité Managée
// Cette fonction communique avec un point de terminaison local sur l'App Service pour obtenir un jeton d'accès pour le stockage.
function getManagedIdentityToken() {
    $url = getenv("IDENTITY_ENDPOINT") . "?resource=https://storage.azure.com/&api-version=2019-08-01";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-IDENTITY-HEADER: " . getenv("IDENTITY_HEADER")]);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response);
    return $data->access_token;
}

// Récupérer le nom du fichier depuis l'URL et le sécuriser
if (!isset($_GET['name']) || empty($_GET['name'])) {
    http_response_code(400);
    echo "Nom de fichier manquant.";
    exit;
}
$blobName = basename($_GET['name']); // Sécurité de base pour éviter les injections de chemin

// Récupérer le contenu du blob depuis Azure Storage
$token = getManagedIdentityToken();
$blobUrl = "https://{$accountName}.blob.core.windows.net/{$containerName}/{$blobName}";
$date = gmdate('D, d M Y H:i:s T');
$headers = [
    'Authorization: Bearer ' . $token, // On utilise le jeton pour s'authentifier
    'x-ms-version: 2020-04-08',
    'x-ms-date: ' . $date
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $blobUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_HEADER, true); // On demande à cURL de nous retourner les en-têtes de la réponse
$response = curl_exec($ch);
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$header_str = substr($response, 0, $header_size);
$body = substr($response, $header_size);
curl_close($ch);

// Extraire le Content-Type (ex: 'image/jpeg') et l'envoyer au navigateur pour qu'il sache comment afficher le contenu
$headers_arr = explode("\r\n", $header_str);
foreach ($headers_arr as $header) {
    if (stripos($header, 'Content-Type:') !== false) {
        header($header);
    }
}

// Afficher le contenu de l'image
echo $body;
?>
