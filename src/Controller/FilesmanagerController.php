<?php

namespace Drupal\filesmanager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Component\Serialization\Json;

/**
 * Returns responses for filesmanager routes.
 */
class FilesmanagerController extends ControllerBase {
	
	/**
	 * Builds the response.
	 */
	public function build(Request $Request){
		$configs = $Request->getContent();
		$configs = Json::decode( $configs );
		$fid = $this->base64_to_file( $configs["upload"], "public://Filesmanager/" . $configs["filename"] . "." . $configs["ext"] );
		return $this->reponse( $fid );
	}
	public function base64_to_file($base64_string, $destination){
		// $data = explode( ',', $base64_string );
		$fid = file_save_data( base64_decode( $base64_string ), $destination );
		if($fid){
			return [
					'id'=> $fid->id(),
					'url'=> $this->getImageUrlByFid( $fid->id() )
			];
		}
		else
			return [];
	}
	
	/**
	 * retourne le chemin absolue sans le domaine.
	 *
	 * @param array $fid
	 * @param String $image_style
	 * @return string|array
	 */
	public function getImageUrlByFid($fid, $image_style = null){
		if(! empty( $fid[0] )){
			$file = \Drupal\file\Entity\File::load( $fid[0] );
			if($file){
				if(! empty( $image_style ) && \Drupal\image\Entity\ImageStyle::load( $image_style )){
					$img_url = \Drupal\image\Entity\ImageStyle::load( $image_style )->buildUrl( $file->getFileUri() );
				}
				else{
					$img_url = file_create_url( $file->getFileUri() );
				}
				// remove domaine
				$img_url = explode( \Drupal::request()->getSchemeAndHttpHost(), $img_url );
				return ! empty( $img_url[1] ) ? $img_url[1] : $img_url[0];
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
	protected function reponse($configs, $code = null, $message = null){
		if(! is_string( $configs ))
			$configs = Json::encode( $configs );
		$reponse = new JsonResponse();
		if($code)
			$reponse->setStatusCode( $code, $message );
		$reponse->setContent( $configs );
		return $reponse;
	}
}
