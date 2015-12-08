<?php

function show_s3_gallery( $atts ) {
	global $aws_gallery_connection;
	require_once( __DIR__ . '/../classes/amazon-s3-gallery.php' );
	$s3_gallery = new Amazon_S3_Gallery( $aws_gallery_connection );
	return $s3_gallery->show( $atts );
}

function s3_gallery_filter( $vars ) {
	$vars[] = "folder";
	return $vars;
}

add_filter( 'query_vars', 's3_gallery_filter' );

function s3_gallery_title ( $title ) {
	$query_folder = get_query_var( 'folder' );
	if ( $query_folder != "") {
		$path_array = explode("/", $query_folder);
		array_pop( $path_array );
		$current_folder = array_pop( $path_array );
		return htmlspecialchars($current_folder);
	} else {
		return $title;
	}
}

// add_filter("the_title", "s3_gallery_title");

function aws_init_s3_gallery( $aws_connection ) {
	global $aws_gallery_connection;
	$aws_gallery_connection = $aws_connection;
	add_shortcode( 's3gallery', 'show_s3_gallery' );
}

add_action( 'aws_init', 'aws_init_s3_gallery' );

?>
