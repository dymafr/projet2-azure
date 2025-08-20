<?php
require_once 'vendor/autoload.php';

use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;
use MicrosoftAzure\Storage\Common\Internal\Resources;
use MicrosoftAzure\Storage\Common\Internal\StorageServiceSettings;
use MicrosoftAzure\Storage\Blob\Internal\SharedAccessSignatureHelper;

// --- configuration ---
$connectionString = getenv('AZURE_STORAGE_CONNECTION_STRING') ?: '';
$containerName = 'photos';
// --- fin configuration ---

$uploadMessage = '';
$blobClient = null;
$storageSettings = null;

try {
    if ($connectionString === '') {
        throw new \RuntimeException("Variable d'environnement non définie : AZURE_STORAGE_CONNECTION_STRING");
    }
    $blobClient = BlobRestProxy::createBlobService($connectionString);
    $storageSettings = StorageServiceSettings::createFromConnectionString($connectionString);
} catch (\Throwable $e) {
    $uploadMessage = "<p style='color: red;'>Erreur d'initialisation : " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>";
}

function isValidImageUpload(array $file): bool {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return false;
    if (!isset($file['type']) || !isset($file['tmp_name'])) return false;
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowed, true)) return false;
    $imageInfo = @getimagesize($file['tmp_name']);
    return $imageInfo !== false;
}

if ($blobClient && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fileToUpload'])) {
    if (isValidImageUpload($_FILES['fileToUpload'])) {
        $originalName = $_FILES['fileToUpload']['name'];
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
        $fileName = date('Ymd_His') . '_' . $safeName;
        $fileContent = file_get_contents($_FILES['fileToUpload']['tmp_name']);
        $options = new CreateBlockBlobOptions();
        $options->setContentType($_FILES['fileToUpload']['type']);

        try {
            $blobClient->createBlockBlob($containerName, $fileName, $fileContent, $options);
            $uploadMessage = "<p style='color: green;'>Fichier « " . htmlspecialchars($originalName, ENT_QUOTES, 'UTF-8') . " » téléversé avec succès</p>";
        } catch (ServiceException $e) {
            $uploadMessage = "<p style='color: red;'>Erreur lors du téléversement : " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>";
        }
    } else {
        $uploadMessage = "<p style='color: red;'>Fichier invalide : seuls les formats jpeg, png, gif et webp sont acceptés</p>";
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
        <h2>Téléverser une nouvelle image</h2>
        <input type="file" name="fileToUpload" id="fileToUpload" accept="image/*" required>
        <input type="submit" value="Téléverser l'image" name="submit">
    </form>

    <?php echo $uploadMessage; ?>

    <h2>Photos téléversées</h2>
    <div class="gallery">
        <?php
        if ($blobClient) {
            try {
                $listBlobsOptions = new ListBlobsOptions();
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
                    echo "<p>Aucune photo n'a encore été téléversée.</p>";
                }
            } catch (ServiceException $e) {
                echo "<p style='color: red;'>Erreur lors de la récupération des images : " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>";
            }
        } else {
            echo "<p style='color: red;'>Client non initialisé : vérifiez la variable d'environnement.</p>";
        }
        ?>
    </div>
</div>
</body>
</html>
