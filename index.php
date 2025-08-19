<?php
// --- Configuration ---
$accountName = "globalsharestorage13"; // REMPLACEZ PAR LE NOM DE VOTRE COMPTE DE STOCKAGE
$containerName = "photos";
// --- Fin de la configuration ---

// Fonction pour obtenir un jeton d'authentification via l'Identité Managée
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

$uploadMessage = '';

// Gérer le téléversement de fichier
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fileToUpload'])) {
    $fileName = basename($_FILES['fileToUpload']['name']);
    $fileContent = file_get_contents($_FILES['fileToUpload']['tmp_name']);
    $fileSize = strlen($fileContent);

    $token = getManagedIdentityToken();
    $blobUrl = "https://{$accountName}.blob.core.windows.net/{$containerName}/{$fileName}";
    $date = gmdate('D, d M Y H:i:s T');

    // Préparation de la requête PUT pour téléverser le blob, avec les en-têtes requis par l'API Azure Storage
    $headers = [
        'Authorization: Bearer ' . $token,
        'x-ms-version: 2020-04-08',
        'x-ms-date: ' . $date,
        'x-ms-blob-type: BlockBlob',
        'Content-Length: ' . $fileSize,
        'Content-Type: ' . $_FILES['fileToUpload']['type']
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $blobUrl);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContent);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code >= 200 && $http_code < 300) {
        $uploadMessage = "<p style='color: green;'>Fichier '$fileName' téléversé avec succès !</p>";
    } else {
        $uploadMessage = "<p style='color: red;'>Erreur lors du téléversement (Code: $http_code)</p>";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>GlobalShare - Galerie</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f4f4; color: #333; margin: 20px; }
        h1, h2 { color: #0078D4; }
        .container { max-width: 900px; margin: auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; margin-top: 20px; }
        .gallery img { width: 100%; height: auto; border-radius: 4px; border: 1px solid #ddd; }
        form { margin-bottom: 30px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>GlobalShare - Votre galerie photo</h1>

        <form action="index.php" method="post" enctype="multipart/form-data">
            <h2>Téléverser une nouvelle image</h2>
            <input type="file" name="fileToUpload" id="fileToUpload" required>
            <input type="submit" value="Téléverser l'image" name="submit">
        </form>

        <?php echo $uploadMessage; ?>

        <h2>Photos téléversées</h2>
        <div class="gallery">
            <?php
            // Lister les blobs en appelant l'API REST de manière authentifiée
            $token = getManagedIdentityToken();
            $listUrl = "https://{$accountName}.blob.core.windows.net/{$containerName}?restype=container&comp=list";
            $date = gmdate('D, d M Y H:i:s T');
            $headers = [
                'Authorization: Bearer ' . $token,
                'x-ms-version: 2020-04-08',
                'x-ms-date: ' . $date
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $listUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $xmlResponse = curl_exec($ch);
            curl_close($ch);

            if ($xmlResponse) {
                $xml = simplexml_load_string($xmlResponse);
                if ($xml->Blobs->Blob) {
                    foreach ($xml->Blobs->Blob as $blob) {
                        $blobName = (string)$blob->Name;
                        // Pour chaque blob, on génère une balise <img> qui pointe vers notre script image.php
                        // Cela permet au serveur de récupérer l'image de manière sécurisée et de la servir au client.
                        echo '<img src="image.php?name=' . urlencode($blobName) . '" alt="' . htmlspecialchars($blobName) . '">';
                    }
                } else {
                    echo "<p>Aucune photo n'a encore été téléversée.</p>";
                }
            } else {
                echo "<p style='color: red;'>Erreur lors de la récupération des images.</p>";
            }
            ?>
        </div>
    </div>
</body>
</html>
