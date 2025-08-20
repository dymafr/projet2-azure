<?php
// tous les commentaires sont en français.
// but : téléverser une image dans le conteneur "photos" et afficher la galerie
//      en STREAMING (sans générer de SAS côté client).

require_once 'vendor/autoload.php';

use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;

// --- configuration ---
$accountName   = getenv('AZURE_STORAGE_ACCOUNT') ?: 'votre_nom_de_compte'; // remplace si besoin
$containerName = 'photos';
// --- fin configuration ---

/**
 * récupère un jeton d'accès via l'identité managée.
 * - sur app service : IDENTITY_ENDPOINT + X-IDENTITY-HEADER (api-version 2019-08-01)
 * - fallback imds (vm/aca) : 169.254.169.254 + header Metadata:true (api-version 2018-02-01)
 */
function getManagedIdentityToken(string $resource = 'https://storage.azure.com/'): string {
    $endpoint = getenv('IDENTITY_ENDPOINT');
    $secret   = getenv('IDENTITY_HEADER');

    if ($endpoint && $secret) {
        // cas app service
        $qs = http_build_query([
            'resource'    => $resource,
            'api-version' => '2019-08-01'
        ]);
        $ch = curl_init($endpoint . '?' . $qs);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-IDENTITY-HEADER: ' . $secret]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resp = curl_exec($ch);
        if ($resp === false) {
            throw new \RuntimeException('échec token app service : ' . curl_error($ch));
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) {
            throw new \RuntimeException('échec token app service (' . $code . ') : ' . $resp);
        }
        $json = json_decode($resp, true);
        if (!isset($json['access_token'])) {
            throw new \RuntimeException('réponse token invalide côté app service');
        }
        return $json['access_token'];
    }

    // fallback : imds (vm, container, etc.)
    $qs = http_build_query([
        'resource'    => $resource,
        'api-version' => '2018-02-01'
    ]);
    $url = 'http://169.254.169.254/metadata/identity/oauth2/token?' . $qs;
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Metadata: true']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    if ($resp === false) {
        throw new \RuntimeException('échec token imds : ' . curl_error($ch));
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) {
        throw new \RuntimeException('échec token imds (' . $code . ') : ' . $resp);
    }
    $json = json_decode($resp, true);
    if (!isset($json['access_token'])) {
        throw new \RuntimeException('réponse token invalide côté imds');
    }
    return $json['access_token'];
}

// initialisation du client blob avec jeton bearer (entra id)
$uploadMessage = '';
$blobClient    = null;

try {
    $token = getManagedIdentityToken('https://storage.azure.com/');
    // connection string sans clé : sert à former les endpoints (pas d'AccountKey ici)
    $connectionString = "DefaultEndpointsProtocol=https;AccountName={$accountName};EndpointSuffix=core.windows.net";
    $blobClient = BlobRestProxy::createBlobServiceWithTokenCredential($token, $connectionString);
} catch (\Throwable $e) {
    $uploadMessage = "<p style='color: red;'>erreur d'initialisation : " . htmlspecialchars($e->getMessage()) . "</p>";
}

// route de streaming : ?download=nom_du_blob
if ($blobClient && isset($_GET['download'])) {
    $blobName = $_GET['download'];
    try {
        $result = $blobClient->getBlob($containerName, $blobName);
        // propage le content-type du blob
        $contentType = $result->getProperties()->getContentType() ?: 'application/octet-stream';
        header('Content-Type: ' . $contentType);
        header('Cache-Control: private, max-age=60');
        fpassthru($result->getContentStream());
    } catch (ServiceException $e) {
        http_response_code(404);
        echo "introuvable : " . htmlspecialchars($e->getMessage());
    }
    exit;
}

// traitement upload
if ($blobClient
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_FILES['fileToUpload'])
    && $_FILES['fileToUpload']['error'] === UPLOAD_ERR_OK
) {
    $fileName    = basename($_FILES['fileToUpload']['name']);
    $fileTmpPath = $_FILES['fileToUpload']['tmp_name'];
    $options     = new CreateBlockBlobOptions();
    $options->setContentType($_FILES['fileToUpload']['type'] ?: 'application/octet-stream');

    try {
        // envoie le flux directement
        $stream = fopen($fileTmpPath, 'rb');
        $blobClient->createBlockBlob($containerName, $fileName, $stream, $options);
        fclose($stream);
        $uploadMessage = "<p style='color: green;'>fichier « $fileName » téléversé avec succès.</p>";
    } catch (ServiceException $e) {
        $uploadMessage = "<p style='color: red;'>erreur lors du téléversement : " . htmlspecialchars($e->getMessage()) . "</p>";
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
    <h1>GlobalShare - votre galerie photo</h1>

    <form action="index.php" method="post" enctype="multipart/form-data">
      <h2>téléverser une nouvelle image</h2>
      <input type="file" name="fileToUpload" id="fileToUpload" required>
      <input type="submit" value="téléverser l'image" name="submit">
    </form>

    <?php echo $uploadMessage; ?>

    <h2>photos téléversées</h2>
    <div class="gallery">
      <?php
      if ($blobClient) {
        try {
          $listBlobsOptions = new ListBlobsOptions();
          $result = $blobClient->listBlobs($containerName, $listBlobsOptions);
          $blobs = $result->getBlobs();

          if ($blobs) {
            foreach ($blobs as $blob) {
              $name = $blob->getName();
              // on affiche via la route ?download=... (pas d'url publique)
              $url  = 'index.php?download=' . rawurlencode($name);
              echo '<img src="' . htmlspecialchars($url) . '" alt="' . htmlspecialchars($name) . '">';
            }
          } else {
            echo "<p>aucune photo téléversée pour le moment.</p>";
          }
        } catch (ServiceException $e) {
          echo "<p style='color: red;'>erreur lors de la récupération des images : " . htmlspecialchars($e->getMessage()) . "</p>";
        }
      }
      ?>
    </div>
  </div>
</body>
</html>
