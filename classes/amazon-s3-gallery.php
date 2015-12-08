<?php
use Aws\S3\S3Client;

require_once( __DIR__ . '/../lib/resize-class.php' );

class Amazon_S3_Gallery extends AWS_Plugin_Base {

	const SETTINGS_KEY = 'tantan_wordpress_s3'; // TODO

	const MAX_CONVERT_LIMIT = 10000000; // TODO

	const IMAGE_CACHE_FOLDER = 'cache/'; // TODO

	function __construct( $gallery_connection ) {
		$this->s3_client = $gallery_connection->get_client()->get( 's3' );
		$this->option_thumbnail = self::get_option_media_thumbnail();
		$this->total_converted_size = 0;
	}

	public function show( $attributes ) {

		extract( shortcode_atts( array(
			"folder" => "/",
			"bar" => "the default bar value",
		), $attributes ) );

		$this->bucket = "vredenburchvriendenteam-nl";
		$this->base_folder = $folder;
		$this->query_folder = self::discover_folder();

		$gallery = new Gallery_Display( $this->base_folder, $this->query_folder );

		$iterator = $this->s3_client->getIterator(
			'ListObjects',
			array(
				'Bucket' => $this->bucket,
				'Prefix' => $this->base_folder . $this->query_folder,
				'Delimiter' => '/'
			),
			array(
				'return_prefixes' => true,
				'sort_results' => true
			)
		);
		foreach ( $iterator as $object ) {
			if ( isset( $object['Prefix'] ) ) {
				$gallery->add_folder( $object['Prefix'] );
			}
			if ( isset( $object['Key'] ) ) {
				if ( $object['Size'] > 0 ) {
					if ( $object['Key'] == $this->base_folder . $this->query_folder ) {
						$gallery->add_picture( $this->s3_client->getObjectUrl( $this->bucket, $object['Key'] ) );
					} else {
						if ($this->total_converted_size < self::MAX_CONVERT_LIMIT ) {
							$thumbnail = $this->create_thumb( $object['Key'], $object['Size'] );
							$gallery->add_thumbnail( $object['Key'], $this->s3_client->getObjectUrl( $this->bucket, $thumbnail ) );
						}
					}
				}
			}
		}

		$html = $gallery->show();

		if ($this->total_converted_size > self::MAX_CONVERT_LIMIT) {
			$html .= "<em>Not all thumbnails created yet. Processing max. " . round(self::MAX_CONVERT_LIMIT/1000000) . " MB at a time.</em><br/><br/>";
		}

		// $bucketlist = "<br><br>";
		// $this->buckets = $this->s3_client->listBuckets();
		// foreach ( $this->buckets['Buckets'] as $bucket ) {
		// 	$bucketlist .= $bucket['Name'] . "<br/>";
		// }
		// $bucketlist .= "<br>";
		// $bucketname = print_r( $this->get_setting("bucket"), true );

		//$html .= "Showing the element " . $this->query_folder . "<br/><br/>";

		// $html .= "folder = {$folder} and {$bar} and ";
		// $html .= $bucketlist;
		// $html .= $bucketname;

		return $html;

	}

	private static function discover_folder() {
		$query_folder = get_query_var( 'folder' );

		if ($query_folder == "/" ) {
			$query_folder = "" ;
		}
		return $query_folder;
	}


	private function get_file_listing() {
		return $gallery_connection->get_client()->get( 's3' );
	}

	private function create_thumb( $key, $size ) {

		$thumb_key = self::IMAGE_CACHE_FOLDER . $this->option_thumbnail["width"] . "x" . $this->option_thumbnail["height"] . "-" . $this->option_thumbnail["resize"]  . "/" . $key;

		if ( !$this->s3_client->doesObjectExist( $this->bucket, $thumb_key )) {

			$temp_original_image = self::create_unique_temp_filename( $key );
			$temp_converted_image = self::create_unique_temp_filename( $key );

			// echo "Creating ... ";

			$download = $this->s3_client->getObject(array(
				"Bucket" => $this->bucket,
				"Key"    => $key,
				"SaveAs" => $temp_original_image
			));

			// echo "Size is " . $this->option_thumbnail["width"];
			$this->total_converted_size += $size;

			$resizeObj = new resize( $temp_original_image );

			if ($resizeObj->validImage()) {
				$resizeObj->resizeImage( $this->option_thumbnail["width"], $this->option_thumbnail["height"], $this->option_thumbnail["resize"] );
				$resizeObj->saveImage( $temp_converted_image, 100 );

				$upload = $this->s3_client->putObject(array(
				    "Bucket"       => $this->bucket,
				    "Key"          => $thumb_key,
				    "SourceFile"   => $temp_converted_image,
				    "ContentType"  => $download["ContentType"],
				    "ACL"          => "public-read",
				    "StorageClass" => "REDUCED_REDUNDANCY"
				));
				unlink( $temp_converted_image );
			} else {
				$thumb_key = $key; //TODO
			}
			unlink( $temp_original_image );
		}

		return $thumb_key;

	}

	private static function create_unique_temp_filename( $key ) {
		$extension = strtolower( strrchr( $key, '.') );

		// $temp_upload_location = sys_get_temp_dir();
		$wp_upload_location = wp_upload_dir();
		$temp_upload_location = trailingslashit( $wp_upload_location["basedir"] );

		$filename = $temp_upload_location . uniqid() . $extension;
		return $filename;
	}

	private static function get_option_media_thumbnail() {
		return array (
			"width" => get_option( "thumbnail_size_w" ),
			"height" => get_option( "thumbnail_size_h" ),
			"resize" => (get_option( "thumbnail_crop" ) ? "crop" : "contain")
		);
	}

}


class Gallery_Display {

	private $folders = array();
	private $thumbnails = array();
	private $picture;
	private $base_folder;

	function __construct( $base, $query ) {
		$this->base_folder = $base;
		$this->query_folder = $query;
	}

	public function add_folder( $link ) {
		$this->folders[] = array( "link" => $link );
	}

	public function add_thumbnail( $link, $image ) {
		$this->thumbnails[] = array( "link" => $link, "image" => $image );
	}

	public function add_picture( $image ) {
		$this->picture = $image;
	}

	public function show() {
		$html = "";
		$html .= $this->show_path();
		$html .= $this->show_folders();
		$html .= $this->show_thumbnails();
		$html .= $this->show_picture();
		return $html;
	}

	private function show_path() {
		$path_html = "";
		if ( $this->query_folder != "" ) {
			$path_html .= "<a href=\"" . self::gallery_link( self::parent_folder( $this->query_folder ) ) . "\">";
			$path_html .= "Terug"; // Back to " . self::parent_folder( $folder );
			$path_html .= "</a><br/><br/>";
		}
		return $path_html;
	}

	private function show_folders() {
		$folder_list = "";
		foreach ( $this->folders as $folder ) {
			$folder_list .= "<a href=\"" . $this->gallery_link( $folder["link"] ) . "\">";
			$folder_list .= self::pretty_folder_name( $folder["link"] );
			$folder_list .= "</a><br/>". PHP_EOL;
		}
		if ( count( $this->folders ) > 0 ) {
			$folder_list .= "<br/>". PHP_EOL;
		}
		return $folder_list;
	}

	private function show_thumbnails() {
		$thumbnails_list = "<div class=\"gallery\">";
		foreach ( $this->thumbnails as $thumbnail ) {
			$thumbnails_list .= "<figure class=\"gallery-item\">". PHP_EOL;
			$thumbnails_list .= "<a href=\"" . $this->gallery_link( $thumbnail["link"] ) . "\">";
			$thumbnails_list .= "<img src=\"" .  $thumbnail["image"] . "\">";
			$thumbnails_list .= "</a>". PHP_EOL;
			$thumbnails_list .= "</figure>". PHP_EOL;
		}
		$thumbnails_list .= "</div>". PHP_EOL;
		return $thumbnails_list;
	}

	private function show_picture() {
		$picture_html = "";
		if ( $this->picture != null ) {
			$picture_html .= "<img src=\"" .  $this->picture . "\">";
			$picture_html .= "<br/>". PHP_EOL;
		}
		return $picture_html;
	}

	private function gallery_link( $location ) {
		$location = str_replace( $this->base_folder, "", $location );
		return add_query_arg( 'folder', urlencode( $location ), get_permalink() );
	}

	private static function pretty_folder_name( $query_folder ) {
		$path_array = explode("/", $query_folder);
		array_pop( $path_array );
		return array_pop( $path_array );
	}

	private static function parent_folder( $query_folder ) {
		$path_array = explode("/", $query_folder);
		if ( array_pop( $path_array ) == null) {
			array_pop( $path_array );
		}
		$query_folder = implode( $path_array, "/" ) . "/";
		return $query_folder;
	}

}
