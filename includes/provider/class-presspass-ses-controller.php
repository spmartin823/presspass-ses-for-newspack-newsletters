<?php
/**
 * REST routes Newspack's editor calls for the Amazon SES provider.
 *
 * @package PressPass_SES
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PressPass_SES_Controller extends Newspack_Newsletters_Service_Provider_Controller {

	public function register_routes() {
		parent::register_routes();

		$namespace = $this->service_provider::BASE_NAMESPACE . $this->service_provider->service;
		$id_arg    = array(
			'sanitize_callback' => 'absint',
			'validate_callback' => array( 'Newspack_Newsletters', 'validate_newsletter_id' ),
		);
		register_rest_route(
			$namespace,
			'(?P<id>[\d]+)/retrieve',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'api_retrieve' ),
				'permission_callback' => array( 'Newspack_Newsletters', 'api_authoring_permissions_check' ),
				'args'                => array( 'id' => $id_arg ),
			)
		);
		register_rest_route(
			$namespace,
			'(?P<id>[\d]+)/test',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'api_test' ),
				'permission_callback' => array( 'Newspack_Newsletters', 'api_authoring_permissions_check' ),
				'args'                => array(
					'id'         => $id_arg,
					'test_email' => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
	}

	public function api_retrieve( WP_REST_Request $request ) {
		return self::get_api_response( $this->service_provider->retrieve( $request['id'] ) );
	}

	public function api_test( WP_REST_Request $request ) {
		$emails = array_map(
			static function ( $email ) {
				return sanitize_email( trim( $email ) );
			},
			explode( ',', $request['test_email'] )
		);
		$this->update_user_test_emails( $emails );
		return self::get_api_response( $this->service_provider->test( $request['id'], $emails ) );
	}
}
