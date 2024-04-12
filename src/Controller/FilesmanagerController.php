<?php

namespace Drupal\filesmanager\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\File\FileSystem;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Drupal\file\Entity\File;
use Drupal\image\Entity\ImageStyle;
use Drupal\Core\File\FileSystemInterface;
use \Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\more_fields\Plugin\Field\FieldWidget\HbkFileWidget;
use Drupal\more_fields_video\Entity\MultiformatVideo;

/**
 * Returns responses for filesmanager routes.
 */
class FilesmanagerController extends ControllerBase {
  protected $FileSystem;
  protected $fileUrlGenerator;
  protected $destination = "public://filesmanager/";

  function __construct(FileSystem $FileSystem, FileUrlGeneratorInterface $FileUrlGenerator) {
    $this->FileSystem = $FileSystem;
    $this->fileUrlGenerator = $FileUrlGenerator;
  }

  /**
   *
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // return new static($container->get('prestashop_rest_api.cron'),
    // $container->get('prestashop_rest_api.build_product_to_drupal'));
    return new static($container->get('file_system'), $container->get('file_url_generator'));
  }

  /**
   *
   * @param Request $Request
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function files(Request $Request) {
    $file = $Request->files->get('file__upload');
    $fid = [];
    if (!empty($file)) {
      $fid = $this->fileUpload($file);
    }
    return $this->reponse($fid);
  }

  /**
   * Enregistre un fichier.
   *
   * @param UploadedFile $file
   */
  function fileUpload(UploadedFile $file) {
    $user = \Drupal::currentUser();
    $originalName = explode(".", $file->getClientOriginalName());
    $fileName = Html::getId($originalName[0]) . '.' . $file->getClientOriginalExtension();
    // $new_file = $file->move( $this->destination );
    $source = $file->getPath() . '/' . $file->getFilename();
    $destination = $this->destination . $fileName;
    /** @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager */
    $stream_wrapper_manager = \Drupal::service('stream_wrapper_manager');
    if (!$stream_wrapper_manager->isValidUri($destination)) {
      \Drupal::logger('file')->notice('The data could not be saved because the destination %destination is invalid. This may be caused by improper use of file_save_data() or a missing stream wrapper.', [
        '%destination' => $destination
      ]);
      \Drupal::messenger()->addError(t('The data could not be saved because the destination is invalid. More information is available in the system log.'));
      return FALSE;
    }
    $uri = $this->FileSystem->move($source, $destination);
    $file = File::create([
      'uri' => $uri,
      'uid' => $user->id(),
      'status' => \Drupal\file\FileInterface::STATUS_PERMANENT
    ]);
    $file->setFilename($this->FileSystem->basename($destination));
    $fid = $file->save();
    // $fid = file_save_data( $file, $this->destination . $fileName );
    if ($fid) {
      return [
        'id' => $file->id(),
        'url' => $this->fileUrlGenerator->generateString($file->getFileUri()),
        'filename' => $file->getFilename()
      ];
    }
  }

  /**
   * Elle ne sauvegarde pas deux foix une image portant le meme nom, mais ajoute
   * une entrée dans file.usage;
   */
  public function build(Request $Request) {
    try {
      $configs = $Request->getContent();
      $configs = Json::decode($configs);
      $configs["filename"] = Html::getId($configs["filename"]);
      // $fid = $this->base64_to_file($configs["upload"],
      // "public://filesmanager/" . $configs["filename"] . "." .
      // $configs["ext"], $configs);
      $fid = $this->base64_to_file($configs["upload"], $configs);
      if ($fid) {
        if (\Drupal::moduleHandler()->moduleExists('more_fields_video')) {
          /**
           * @var MultiformatVideo $multiformat
           */
          [
            "multiformat" => $multiformat,
            "furi" => $fileUri
          ] = \Drupal::service("more_fields_video.video_converter")->manageUploadedFile($fid["id"]);
          if ($multiformat) {
            $image_style = $configs["image_style"] ?? "medium";
            $fid["preview"] = $this->getImageUrl($multiformat->getThumbId(), $image_style);
            $fid["file_id"] = $multiformat->id();
            $fid["th_id"] = $multiformat->getThumbId();
            $fid["url"] = $fileUri;
          }
        }
        return $this->reponse($fid);
      }
      throw new \Exception("L'image n'a pas pu etre sauvegarder");
    } catch (\Exception $e) {
      return $this->reponse($fid, 400, $e->getMessage());
    }
  }

  /**
   *
   * @param String $base64_string
   * @param array $configs
   * @return string[]|array[]|NULL[]|array
   */
  protected function base64_to_file($base64_string, $configs = []) {
    // $data = explode( ',', $base64_string );
    /**
     *
     * @var \Drupal\Core\File\FileSystem $filesystem
     */
    $filesystem = \Drupal::service('file_system');
    // Check the directory exists before writing data to it.
    $filesystem->prepareDirectory($this->destination, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $file_destination = $this->destination . $configs["filename"] . "." . $configs["ext"];
    // Save the default icon file.
    /** @var \Drupal\file\FileRepositoryInterface $fileRepository */
    $fileRepository = \Drupal::service('file.repository');
    $file = $fileRepository->writeData(base64_decode($base64_string), $file_destination);
    if ($file) {
      return [
        'id' => $file->id(),
        'url' => $this->getImageUrlByFid($file->id()),
        'filename' => $file->getFilename()
      ];
    } else
      return FALSE;
  }

  /**
   * Permet de supprimer l'utilisation et de l'image si cette derniere n'est
   * plus utiliser ailleurs.
   */
  public function removeImage($fid, $module) {
    $file = \Drupal\file\Entity\File::load($fid);
    if ($file) {
      /**
       *
       * @var \Drupal\file\FileUsage\DatabaseFileUsageBackend $file_usage
       */
      $file_usage = \Drupal::service('file.usage');
      // On supprime pour l'utilisation
      $file_usage->delete($file, $module);
      $usage = $file_usage->listUsage($file);
      // on supprime le fichier s'il n'est plus utiliser.
      if (count($usage) == 0) {
        $file->delete();
      }
      return $this->reponse($fid . " is deleted");
    }
    return $this->reponse($fid . " not existed ", 400);
  }

  /**
   * Builds the response.
   */
  public function getImage($fid, $style) {
    $img_url = $this->getImageUrl($fid, $style);
    return $this->reponse($img_url);
  }


  /**
   * Build the Styled image
   */
  public function getImageUrl($fid, $style) {
    $file = File::load($fid);
    $img_url = null;
    if ($file) {
      $renderStyle = ImageStyle::load($style);
      if ($renderStyle) {
        $img_url = $renderStyle->buildUrl($file->getFileUri());
      } else
        // $img_url =
        // \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
        // ou
        $img_url = \Drupal::service('file_system')->realpath($file->getFileUri());
    }
    return $img_url;
  }


  /**
   * Builds the response.
   */
  public function getHbkFileThumb($fid, $style) {
    $file = MultiformatVideo::load($fid);
    $id = $file ? $file->getThumbId() : $fid;
    return $this->getImage($id, $style);
  }


  /**
   * Retourne le chemin absolue sans le domaine.
   *
   * @param array $fid
   * @param String $image_style
   * @return string|array
   */
  protected function getImageUrlByFid($fid, $image_style = null) {
    if (!empty($fid)) {
      $file = \Drupal\file\Entity\File::load($fid);
      if ($file) {
        if (!empty($image_style) && \Drupal\image\Entity\ImageStyle::load($image_style)) {
          $img_url = \Drupal\image\Entity\ImageStyle::load($image_style)->buildUrl($file->getFileUri());
        } else {
          $img_url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
        }
        // remove domaine
        $img_url = explode(\Drupal::request()->getSchemeAndHttpHost(), $img_url);
        return !empty($img_url[1]) ? $img_url[1] : $img_url[0];
      }
    }
    return [];
  }

  /**
   *
   * @param array|string $configs
   * @param number $code
   * @param string $message
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  protected function reponse($configs, $code = null, $message = null) {
    if (!is_string($configs))
      $configs = Json::encode($configs);
    $reponse = new JsonResponse();
    if ($code)
      $reponse->setStatusCode($code, $message);
    $reponse->setContent($configs);
    return $reponse;
  }
}
