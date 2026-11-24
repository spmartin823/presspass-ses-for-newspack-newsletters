<?php
/**
 * Amazon SES service provider for Newspack Newsletters.
 *
 * @package PressPass_SES
 */

use Newspack\Newsletters\Send_List;
use Newspack\Newsletters\Send_Lists;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-presspass-ses-controller.php';

class PressPass_SES_Provider extends Newspack_Newsletters_Service_Provider {

	public $service = 'presspass_ses';

	public static $support_local_lists = false;

	public function __construct() {
		$this->controller = new PressPass_SES_Controller( $this );
		parent::__construct();
	}

	public function api_credentials() {
		return array( 'region' => presspass_ses_get_settings()['region'] );
	}

	public function set_api_credentials( $credentials ) {
		return true;
	}

	public function has_api_credentials() {
		return presspass_ses_has_credentials();
	}

	public static function get_labels( $context = '' ) {
		return array_merge( parent::get_labels( $context ), array( 'name' => __( 'Amazon SES', 'presspass-ses-for-newspack-newsletters' ) ) );
	}

	public static function get_conditional_tag_support() {
		return array();
	}

	public function get_usage_report() {
		return null;
	}

	public function get_lists() {
		return array_map(
			static function ( $list ) {
				return array(
					'id'   => (string) $list->id,
					'name' => $list->name,
				);
			},
			presspass_ses_get_lists()
		);
	}

	public function get_send_lists( $args, $to_array = false ) {
		$send_lists = array();
		foreach ( presspass_ses_get_lists() as $list ) {
			$send_lists[] = new Send_List(
				array(
					'provider'    => $this->service,
					'type'        => 'list',
					'id'          => (string) $list->id,
					'name'        => $list->name,
					'entity_type' => 'list',
					'count'       => (int) $list->subscribed_count,
				)
			);
		}
		if ( ! empty( $args['ids'] ) ) {
			$send_lists = array_filter(
				$send_lists,
				static function ( $send_list ) use ( $args ) {
					return Send_Lists::matches_id( $args['ids'], $send_list->get_id() );
				}
			);
		}
		if ( ! empty( $args['search'] ) ) {
			$send_lists = array_filter(
				$send_lists,
				static function ( $send_list ) use ( $args ) {
					return Send_Lists::matches_search( $args['search'], array( $send_list->get_id(), $send_list->get_name() ) );
				}
			);
		}
		$send_lists = array_values( $send_lists );
		if ( $to_array ) {
			return array_map(
				static function ( $send_list ) {
					return $send_list->to_array();
				},
				$send_lists
			);
		}
		return $send_lists;
	}

	public function retrieve( $post_id ) {
		$newsletter_data = array(
			'campaign'                          => true,
			'campaign_id'                       => 'presspass-ses-' . $post_id,
			'lists'                             => $this->get_send_lists( array(), true ),
			'sublists'                          => array(),
			'supports_multiple_test_recipients' => true,
		);
		// Newspack's editor copies these into the post meta, so they only fill in a sender that hasn't been set yet.
		$settings = presspass_ses_get_settings();
		if ( '' === get_post_meta( $post_id, 'senderName', true ) ) {
			$newsletter_data['senderName'] = $settings['sender_name'];
		}
		if ( '' === get_post_meta( $post_id, 'senderEmail', true ) ) {
			$newsletter_data['senderEmail'] = $settings['sender_email'];
		}
		return $newsletter_data;
	}

	public function test( $post_id, $emails ) {
		if ( ! presspass_ses_is_sending_allowed() ) {
			return self::paused_error();
		}
		$sender_error = self::sender_domain_error( $post_id );
		if ( null !== $sender_error ) {
			return $sender_error;
		}

		$post   = get_post( $post_id );
		$html   = get_post_meta( $post_id, 'newspack_email_html', true );
		$mailer = presspass_ses_build_mailer();
		$mailer->setFrom( get_post_meta( $post_id, 'senderEmail', true ), get_post_meta( $post_id, 'senderName', true ) );
		foreach ( $emails as $email ) {
			presspass_ses_send_email( $mailer, $email, html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ), presspass_ses_render_email( $html, $email, '#' ), array() );
		}
		$mailer->smtpClose();

		return array(
			'message' => sprintf(
				/* translators: %s: comma-separated list of email addresses. */
				__( 'Test sent to %s.', 'presspass-ses-for-newspack-newsletters' ),
				implode( ', ', $emails )
			),
		);
	}

	public function send( $post ) {
		if ( ! presspass_ses_is_sending_allowed() ) {
			return self::paused_error();
		}
		$sender_error = self::sender_domain_error( $post->ID );
		if ( null !== $sender_error ) {
			return $sender_error;
		}
		// A list id left over from another provider can start with digits, so a cast would pick the wrong list.
		$list_id = get_post_meta( $post->ID, 'send_list_id', true );
		if ( ! ctype_digit( $list_id ) ) {
			return new WP_Error( 'presspass_ses_no_list', __( 'Choose a list to send to.', 'presspass-ses-for-newspack-newsletters' ) );
		}
		if ( 0 === presspass_ses_queue_newsletter( $post, (int) $list_id ) ) {
			return new WP_Error( 'presspass_ses_empty_list', __( 'This list has no subscribed members.', 'presspass-ses-for-newspack-newsletters' ) );
		}
		return true;
	}

	public function sync( $post ) {
		return array( 'campaign_id' => 'presspass-ses-' . $post->ID );
	}

	public function save( $meta_id, $post_id, $meta_key ) {}

	public function trash( $post_id ) {}

	public function list( $post_id, $list_id ) {}

	// The base class calls add_contact() once per list, which would send one confirmation email per list.
	public function upsert_contact( $contact, $lists ) {
		$list_ids = array();
		foreach ( $lists as $list ) {
			if ( ! $list->is_local() ) {
				$list_ids[] = (int) $list->get_public_id();
			}
		}
		// Newspack's own callers leave out the name.
		$subscriber = presspass_ses_subscribe( $contact['email'], $contact['name'] ?? '', $list_ids );
		return array(
			'email'  => $subscriber->email,
			'status' => $subscriber->status,
		);
	}

	public function add_contact( $contact, $list_id = false ) {
		$subscriber = presspass_ses_subscribe( $contact['email'], $contact['name'] ?? '', $list_id ? array( (int) $list_id ) : array() );
		return array(
			'email'  => $subscriber->email,
			'status' => $subscriber->status,
		);
	}

	public function get_contact_data( $email, $return_details = false ) {
		$subscriber = presspass_ses_get_subscriber_by_email( $email );
		if ( null === $subscriber ) {
			return new WP_Error( 'presspass_ses_contact_not_found', __( 'Contact not found.', 'presspass-ses-for-newspack-newsletters' ) );
		}
		return array(
			'id'     => (int) $subscriber->id,
			'email'  => $subscriber->email,
			'status' => $subscriber->status,
			'lists'  => array_map( 'strval', presspass_ses_get_subscriber_list_ids( (int) $subscriber->id ) ),
		);
	}

	public function get_contact_lists( $email ) {
		$subscriber = presspass_ses_get_subscriber_by_email( $email );
		if ( null === $subscriber ) {
			return array();
		}
		return array_map( 'strval', presspass_ses_get_subscriber_list_ids( (int) $subscriber->id ) );
	}

	public function update_contact_lists( $email, $lists_to_add = array(), $lists_to_remove = array() ) {
		$subscriber = presspass_ses_get_subscriber_by_email( $email );
		if ( null === $subscriber ) {
			presspass_ses_subscribe( $email, '', array_map( 'intval', $lists_to_add ) );
			return true;
		}
		foreach ( $lists_to_add as $list_id ) {
			presspass_ses_add_to_list( (int) $subscriber->id, (int) $list_id );
		}
		foreach ( $lists_to_remove as $list_id ) {
			presspass_ses_remove_from_list( (int) $subscriber->id, (int) $list_id );
		}
		return true;
	}

	public function get_tag_id( $tag_name, $create_if_not_found = true, $list_id = null ) {
		return self::not_implemented_error();
	}

	public function get_tag_by_id( $tag_id, $list_id = null ) {
		return self::not_implemented_error();
	}

	public function create_tag( $tag, $list_id = null ) {
		return self::not_implemented_error();
	}

	public function update_tag( $tag_id, $tag, $list_id = null ) {
		return self::not_implemented_error();
	}

	public function add_tag_to_contact( $email, $tag, $list_id = null ) {
		return self::not_implemented_error();
	}

	public function remove_tag_from_contact( $email, $tag, $list_id = null ) {
		return self::not_implemented_error();
	}

	public function get_contact_tags_ids( $email ) {
		return self::not_implemented_error();
	}

	private static function paused_error(): WP_Error {
		return new WP_Error( 'presspass_ses_paused', __( 'Sending is paused on this copy of the site.', 'presspass-ses-for-newspack-newsletters' ) );
	}

	private static function not_implemented_error(): WP_Error {
		return new WP_Error( 'presspass_ses_not_implemented', __( 'Amazon SES does not support this yet.', 'presspass-ses-for-newspack-newsletters' ) );
	}

	// SES only sends from addresses on the verified domain; anything else would fail for every recipient.
	private static function sender_domain_error( int $post_id ): ?WP_Error {
		$domain = substr( strrchr( presspass_ses_get_settings()['sender_email'], '@' ), 1 );
		$sender = substr( strrchr( get_post_meta( $post_id, 'senderEmail', true ), '@' ), 1 );
		if ( strtolower( $sender ) === strtolower( $domain ) ) {
			return null;
		}
		return new WP_Error(
			'presspass_ses_sender_domain',
			sprintf(
				/* translators: %s: the email domain set up for Amazon SES. */
				__( 'The sender address must be on %s, the domain set up for Amazon SES.', 'presspass-ses-for-newspack-newsletters' ),
				$domain
			)
		);
	}
}
