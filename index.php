<?php
// tous les commentaires sont en français
// attention : le sdk php azure storage est en mode maintenance ; cette version fonctionne avec une `connection string`

require_once 'vendor/autoload.php';

use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;
use MicrosoftAzure\Storage\Common\Internal\Resources;
use MicrosoftAzure\Storage\Common\Internal\StorageServiceSettings;
use MicrosoftAzure\Storage\Blob\Internal\SharedAccessSignatureHelper;

// --- configuration ---
// bonne pratique : définir la variable d’environnement `AZURE_STORAGE_CONNECTION_STRING` dans l’app service
$connectionString = getenv('AZURE_STORAGE_CONNECTION_STRING') ?: '';
$containerName = 'photos';
// --- fin configuration ---

$uploadMessage = '';
$blobClient = null;
$storageSettings = null;

try {
    if ($connectionString === '') {
        throw new \RuntimeException("variable d'environnement non définie : AZURE_STORAGE_CONNECTION_STRING");
    }
    // initialisation du client blob et des paramètres de stockage
    $blobClient = BlobRestProxy::createBlobService($connectionString);
    $storageSettings = StorageServiceSettings::createFromConnectionString($connectionString);
} catch (\Throwable $e) {
    // on mémorise le message d’erreur pour l’afficher dans la page
    $uploadMessage = "<p style='color: red;'>erreur d'initialisation : " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>";
}

// petite fonction utilitaire : validation basique des images
function isValidImageUpload(array $file): bool {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return false;
    if (!isset($file['type']) || !isset($file['tmp_name'])) return false;

    // liste blanche des types mime courants
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowed, true)) return false;

    // vérification rapide du contenu réel
    $imageInfo = @getimagesize($file['tmp_name']);
    return $imageInfo !== false;
}

// gestion du téléversement
if ($blobClient && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fileToUpload'])) {
    if (isValidImageUpload($_FILES['fileToUpload'])) {
        $originalName = $_FILES['fileToUpload']['name'];
        // on neutralise le nom pour éviter l’injection dans l’url/chemin
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
        // option : préfixer par un horodatage pour éviter les collisions
        $fileName = date('Ymd_His') . '_' . $safeName;

        $fileContent = file_get_contents($_FILES['fileToUpload']['tmp_name']);

        $options = new CreateBlockBlobOptions();
        $options->setContentType($_FILES['fileToUpload']['type']);

        try {
            $blobClient->createBlockBlob($containerName, $fileName, $fileContent, $options);
            $uploadMessage = "<p style='color: green;'>fichier « " . htmlspecialchars($originalName, ENT_QUOTES, 'UTF-8') . " » téléversé avec succès</p>";
        } catch (ServiceException $e) {
            $uploadMessage = "<p style='color: red;'>erreur lors du téléversement : " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>";
        }
    } else {
        $uploadMessage = "<p style='color: red;'>fichier invalide : seuls les formats jpeg, png, gif et webp sont acceptés</p>";
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
        .hint { font-size: 12px; color: #555; }
    </style>
</head>
<body>
<div class="container">
    <h1>GlobalShare - votre galerie photo</h1>

    <form action="index.php" method="post" enctype="multipart/form-data">
        <h2>téléverser une nouvelle image</h2>
        <input type="file" name="fileToUpload" id="fileToUpload" accept="image/*" required>
        <input type="submit" value="téléverser l'image" name="submit">
        <p class="hint">les images sont stockées dans un conteneur privé : elles ne sont pas accessibles publiquement sans url sas</p>
    </form>

    <?php echo $uploadMessage; ?>

    <h2>photos téléversées</h2>
    <div class="gallery">
        <?php
        if ($blobClient && $storageSettings) {
            try {
                $listBlobsOptions = new ListBlobsOptions();
                $listBlobsOptions->setMaxResults(100);
                $result = $blobClient->listBlobs($containerName, $listBlobsOptions);
                $blobs = $result->getBlobs();

                if ($blobs && count($blobs) > 0) {
                    $sasHelper = new SharedAccessSignatureHelper(
                        $storageSettings->getName(),
                        $storageSettings->getKey()
                    );

                    foreach ($blobs as $blob) {
                        $blobName = $blob->getName();
                        // Génération d'une URL SAS valide pour 5 minutes
                        $sasToken = $sasHelper->generateBlobServiceSharedAccessSignatureToken(
                            Resources::RESOURCE_TYPE_BLOB,
                            "$containerName/$blobName",
                            'r', // 'r' pour la permission de lecture (read)
                            (new DateTime())->add(new DateInterval('PT5M'))
                        );
                        $imageUrl = $blob->getUrl() . '?' . $sasToken;
                        echo '<img src="' . htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($blobName, ENT_QUOTES, 'UTF-8') . '">';
                    }
                } else {
                    echo "<p>aucune photo n'a encore été téléversée</p>";
                }
            } catch (ServiceException $e) {
                echo "<p style='color: red;'>erreur lors de la récupération des images : " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>";
            }
        } else {
            echo "<p style='color: red;'>client non initialisé : vérifiez la variable d'environnement</p>";
        }
        ?>
    </div>
</div>
</body>
</html>
