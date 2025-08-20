<?php
// tous les commentaires sont en français.
// objectif : uploader des images dans le conteneur "photos" et les afficher
//            en STREAMING via une route locale, sans URL publique et sans SAS.

require_once 'vendor/autoload.php';

use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;

// --- configuration de base ---
$accountName   = 'globalsharestorage13'; // remplace par le nom exact du compte
$containerName = 'photos';

// sécurité : limite la taille des fichiers (exemple 8 Mo)
$maxBytes = 8 * 1024 * 1024;
// liste blanche d'extensions simples (tu peux enrichir)
$allowedExt = ['jpg','jpeg','png','gif','webp'];
// --- fin configuration ---

/**
 * récupère un jeton d'accès OAuth2 pour la ressource storage via identité managée.
 * - sur app service : variables d'env IDENTITY_ENDPOINT + IDENTITY_HEADER, api-version=2019-08-01
 * - sur vm/container (imds) : 169.254.169.254/metadata/identity/oauth2/token, api-version=2018-02-01
 * - si tu utilises une identité managée "user-assigned", expose AZURE_CLIENT_ID et passe-la en query.
 */
function getManagedIdentityToken(string $resource = 'https://storage.azure.com/'): string {
    $endpoint = getenv('IDENTITY_ENDPOINT');
    $secret   = getenv('IDENTITY_HEADER');
    $clientId = getenv('AZURE_CLIENT_ID'); // optionnel : user-assigned

    if ($endpoint && $secret) {
        // cas app service
        $query = [
            'resource'    => $resource,
            'api-version' => '2019-08-01'
        ];
        if ($clientId) $query['client_id'] = $clientId;

        $ch = curl_init($endpoint . '?' . http_build_query($query));
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

    // fallback : imds
    $query = [
        'resource'    => $resource,
        'api-version' => '2018-02-01'
    ];
    if ($clientId) $query['client_id'] = $clientId;

    $url = 'http://169.254.169.254/metadata/identity/oauth2/token?' . http_build_query($query);
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

// initialisation du client blob avec jeton bearer
$uploadMessage = '';
$blobClient    = null;

try {
    $token = getManagedIdentityToken('https://storage.azure.com/'); // scope storage
    // la chaîne ci-dessous sert à définir les endpoints publics ; pas de clé de compte
    $connectionString = "DefaultEndpointsProtocol=https;AccountName={$accountName};EndpointSuffix=core.windows.net";
    $blobClient = BlobRestProxy::createBlobServiceWithTokenCredential($token, $connectionString);
} catch (\Throwable $e) {
    $uploadMessage = "<p style='color: red;'>erreur d'initialisation : " . htmlspecialchars($e->getMessage()) . "</p>";
}

// route de streaming : index.php?download=<nom_du_blob>
// tous les commentaires sont en français.
// route de streaming : ?download=nom_du_blob
if ($blobClient && isset($_GET['download'])) {
    $blobName = basename($_GET['download']); // évite la traversée de chemins
    try {
        $blob   = $blobClient->getBlob($containerName, $blobName);
        $props  = $blob->getProperties();
        $stream = $blob->getContentStream(); // ne pas fermer explicitement ce flux

        header('Content-Type: ' . ($props->getContentType() ?: 'application/octet-stream'));
        if ($props->getContentLength() !== null) header('Content-Length: ' . $props->getContentLength());
        if ($props->getLastModified() !== null) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $props->getLastModified()->getTimestamp()) . ' GMT');
        }
        if ($props->getETag()) header('ETag: ' . $props->getETag());
        header('Cache-Control: private, max-age=300');
        header('Content-Disposition: inline; filename="' . addslashes($blobName) . '"');

        // on envoie tout le flux ; pas de fclose ici
        fpassthru($stream);
    } catch (\Throwable $e) {
        http_response_code(404);
        echo "introuvable : " . htmlspecialchars($e->getMessage());
    }
    exit;
}

// tous les commentaires sont en français.
// traitement upload robuste

// tous les commentaires sont en français.
// traitement upload robuste
if ($blobClient
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_FILES['fileToUpload'])
) {
    $err = $_FILES['fileToUpload']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) {
        $map = [
            UPLOAD_ERR_INI_SIZE   => "fichier trop volumineux selon upload_max_filesize.",
            UPLOAD_ERR_FORM_SIZE  => "fichier trop volumineux selon MAX_FILE_SIZE.",
            UPLOAD_ERR_PARTIAL    => "téléversement partiel, réessaie.",
            UPLOAD_ERR_NO_FILE    => "aucun fichier reçu.",
            UPLOAD_ERR_NO_TMP_DIR => "dossier temporaire manquant côté serveur.",
            UPLOAD_ERR_CANT_WRITE => "écriture impossible sur le disque temporaire.",
            UPLOAD_ERR_EXTENSION  => "téléversement interrompu par une extension.",
        ];
        $uploadMessage = "<p style='color: red;'>" . ($map[$err] ?? "erreur inconnue de téléversement.") . "</p>";
    } else {
        $fileTmpPath = $_FILES['fileToUpload']['tmp_name'] ?? '';
        $origName    = $_FILES['fileToUpload']['name'] ?? 'fichier';
        $mimeType    = $_FILES['fileToUpload']['type'] ?: 'application/octet-stream';
        $sizeBytes   = (int)($_FILES['fileToUpload']['size'] ?? 0);

        if (!is_uploaded_file($fileTmpPath) || $sizeBytes <= 0) {
            $uploadMessage = "<p style='color: red;'>le fichier n'est pas un téléversement valide.</p>";
        } else {
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $allowedExt = ['jpg','jpeg','png','gif','webp'];
            if (!in_array($ext, $allowedExt, true)) {
                $uploadMessage = "<p style='color: red;'>extension non autorisée.</p>";
            } else {
                $safeName = bin2hex(random_bytes(16)) . '.' . $ext;

                $options = new MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions();
                $options->setContentType($mimeType ?: 'application/octet-stream');

                $stream = @fopen($fileTmpPath, 'rb');
                if ($stream === false) {
                    $uploadMessage = "<p style='color: red;'>impossible d'ouvrir le fichier temporaire en lecture.</p>";
                } else {
                    try {
                        $blobClient->createBlockBlob($containerName, $safeName, $stream, $options);
                        $uploadMessage = "<p style='color: green;'>image téléversée avec succès.</p>";
                    } catch (\Throwable $e) {
                        $uploadMessage = "<p style='color: red;'>erreur lors du téléversement : " . htmlspecialchars($e->getMessage()) . "</p>";
                    } finally {
                        // ne fermer que si c'est bien une ressource encore ouverte
                        if (is_resource($stream)) {
                            @fclose($stream);
                        }
                    }
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>GlobalShare - galerie</title>
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
      <input type="file" name="fileToUpload" id="fileToUpload" accept=".jpg,.jpeg,.png,.gif,.webp" required>
      <input type="submit" value="téléverser l'image" name="submit">
    </form>

    <?php echo $uploadMessage; ?>

    <h2>photos téléversées</h2>
    <div class="gallery">
      <?php
      if ($blobClient) {
        try {
          $result = $blobClient->listBlobs($containerName, new ListBlobsOptions());
          $blobs  = $result->getBlobs();
          if ($blobs) {
            foreach ($blobs as $blob) {
              $name = $blob->getName();
              $url  = 'index.php?download=' . rawurlencode($name); // route locale protégée
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
