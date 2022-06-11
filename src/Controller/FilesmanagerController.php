<?php

namespace Drupal\filesmanager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Core\File\FileSystem;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Drupal\file\Entity\File;

/**
 * Returns responses for filesmanager routes.
 */
class FilesmanagerController extends ControllerBase {
  protected $FileSystem;
  protected $destination = "public://Filesmanager/";
  
  function __construct(FileSystem $FileSystem) {
    $this->FileSystem = $FileSystem;
  }
  
  /**
   *
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // return new static($container->get('prestashop_rest_api.cron'),
    // $container->get('prestashop_rest_api.build_product_to_drupal'));
    return new static($container->get('file_system'));
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
      'status' => FILE_STATUS_PERMANENT
    ]);
    $file->setFilename($this->FileSystem->basename($destination));
    $fid = $file->save();
    // $fid = file_save_data( $file, $this->destination . $fileName );
    if ($fid) {
      return [
        'id' => $file->id(),
        'url' => file_url_transform_relative(file_create_url($file->getFileUri())),
        'filename' => $file->getFilename()
      ];
    }
  }
  
  /**
   * Builds the response.
   */
  public function build(Request $Request) {
    $configs = $Request->getContent();
    $configs = Json::decode($configs);
    $configs["filename"] = Html::getId($configs["filename"]);
    $fid = $this->base64_to_file($configs["upload"], "public://filesmanager/" . $configs["filename"] . "." . $configs["ext"], $configs);
    if ($fid)
      return $this->reponse($fid);
    else
      $this->reponse($fid, 400, "L'image n'a pas pu etre sauvegarder");
  }
  
  /**
   *
   * @param String $base64_string
   * @param String $destination
   * @param array $configs
   * @return string[]|array[]|NULL[]|array
   */
  public function base64_to_file($base64_string, $destination, $configs = []) {
    // $data = explode( ',', $base64_string );
    $file = file_save_data(base64_decode($base64_string), $destination);
    if ($file) {
      return [
        'id' => $file->id(),
        'url' => $this->getImageUrlByFid($file->id()),
        'filename' => $file->getFilename()
      ];
    }
    else
      return FALSE;
  }
  
  /**
   * Retourne le chemin absolue sans le domaine.
   *
   * @param array $fid
   * @param String $image_style
   * @return string|array
   */
  public function getImageUrlByFid($fid, $image_style = null) {
    if (!empty($fid)) {
      $file = \Drupal\file\Entity\File::load($fid);
      if ($file) {
        if (!empty($image_style) && \Drupal\image\Entity\ImageStyle::load($image_style)) {
          $img_url = \Drupal\image\Entity\ImageStyle::load($image_style)->buildUrl($file->getFileUri());
        }
        else {
          $img_url = file_create_url($file->getFileUri());
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
