<?php
/**
 * Settings REST API tests.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */

declare( strict_types=1 );

use PRC\Platform\Markdown_For_Agents\Settings;

/**
 * Settings persistence tests.
 */
class Test_Settings extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( Settings::OPTION_KEY );
		parent::tear_down();
	}

	public function test_get_settings_returns_defaults(): void {
		$request  = new WP_REST_Request( 'GET', '/prc-markdown-for-agents/v1/settings' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( array(), $data['settings']['featured_posts'] );
		$this->assertSame( array(), $data['settings']['category_ids'] );
		$this->assertSame( array(), $data['settings']['additional_resources_blocks'] );
		$this->assertIsArray( $data['categories_available'] );
		$this->assertSame( array(), $data['featured_posts_resolved'] );
		$this->assertSame(
			Settings::get_default_site_summary(),
			$data['settings']['site_summary']
		);
		$this->assertSame(
			Settings::get_default_about_description(),
			$data['settings']['about_description']
		);
		$this->assertCount( 3, $data['settings']['about_links'] );
	}

	public function test_post_settings_persists_empty_about_links(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/prc-markdown-for-agents/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'about_links' => array() ) ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( array(), $data['settings']['about_links'] );
	}

	public function test_post_settings_persists_about_text_and_links(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/prc-markdown-for-agents/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'site_summary'      => 'Custom site summary for agents.',
					'about_description' => 'Custom about paragraph.',
					'about_links'       => array(
						array(
							'id'          => 'first-link',
							'title'       => 'First Link',
							'url'         => 'https://example.com/first',
							'description' => 'First description',
						),
						array(
							'id'          => 'second-link',
							'title'       => 'Second Link',
							'url'         => 'https://example.com/second',
							'description' => 'Second description',
						),
						array(
							'id'    => 'invalid-link',
							'title' => '',
							'url'   => 'https://example.com/skipped',
						),
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 'Custom site summary for agents.', $data['settings']['site_summary'] );
		$this->assertSame( 'Custom about paragraph.', $data['settings']['about_description'] );
		$this->assertCount( 2, $data['settings']['about_links'] );
		$this->assertSame( 'First Link', $data['settings']['about_links'][0]['title'] );
		$this->assertSame( 'Second Link', $data['settings']['about_links'][1]['title'] );
	}

	public function test_post_settings_drops_invalid_about_link_urls(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/prc-markdown-for-agents/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'about_links' => array(
						array(
							'id'    => 'bad-url',
							'title' => 'Bad URL',
							'url'   => "javascript:alert(1)\n",
						),
						array(
							'id'    => 'good-url',
							'title' => 'Good URL',
							'url'   => 'https://example.com/good',
						),
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertCount( 1, $data['settings']['about_links'] );
		$this->assertSame( 'Good URL', $data['settings']['about_links'][0]['title'] );
	}

	public function test_post_settings_respects_about_links_cap(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$links = array();
		for ( $i = 1; $i <= Settings::ABOUT_LINKS_CAP + 2; $i++ ) {
			$links[] = array(
				'id'    => 'link-' . $i,
				'title' => 'Link ' . $i,
				'url'   => 'https://example.com/link-' . $i,
			);
		}

		$request = new WP_REST_Request( 'POST', '/prc-markdown-for-agents/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'about_links' => $links ) ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertCount( Settings::ABOUT_LINKS_CAP, $data['settings']['about_links'] );
		$this->assertSame( 'Link 1', $data['settings']['about_links'][0]['title'] );
		$this->assertSame(
			'Link ' . Settings::ABOUT_LINKS_CAP,
			$data['settings']['about_links'][ Settings::ABOUT_LINKS_CAP - 1 ]['title']
		);
	}

	public function test_post_settings_persists_featured_posts(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		$request = new WP_REST_Request( 'POST', '/prc-markdown-for-agents/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'featured_posts' => array( $post_id, $post_id, 999999 ),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( array( $post_id ), $data['settings']['featured_posts'] );
		$this->assertCount( 1, $data['featured_posts_resolved'] );
	}

	public function test_post_settings_persists_additional_resources_blocks(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/prc-markdown-for-agents/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'additional_resources_blocks' => array(
						array(
							'id'    => 'custom-block',
							'title' => 'Custom Section',
							'body'  => "Line one\nLine two",
						),
						array(
							'id'    => 'empty-title',
							'title' => '',
							'body'  => 'Should be dropped',
						),
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertCount( 1, $data['settings']['additional_resources_blocks'] );
		$this->assertSame( 'Custom Section', $data['settings']['additional_resources_blocks'][0]['title'] );
		$this->assertStringContainsString( 'Line two', $data['settings']['additional_resources_blocks'][0]['body'] );
	}

	public function test_get_settings_migrates_legacy_featured_reports_key(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		update_option(
			Settings::OPTION_KEY,
			array(
				'featured_reports' => array( $post_id ),
			)
		);

		$settings = Settings::get_settings();
		$this->assertSame( array( $post_id ), $settings['featured_posts'] );
	}

	public function test_post_settings_persists_category_ids(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$first = self::factory()->term->create(
			array(
				'taxonomy' => Settings::CATEGORIES_TAXONOMY,
				'name'     => 'First Category',
				'parent'   => 0,
			)
		);
		$second = self::factory()->term->create(
			array(
				'taxonomy' => Settings::CATEGORIES_TAXONOMY,
				'name'     => 'Second Category',
				'parent'   => 0,
			)
		);
		$child = self::factory()->term->create(
			array(
				'taxonomy' => Settings::CATEGORIES_TAXONOMY,
				'name'     => 'Child Category',
				'parent'   => $first,
			)
		);

		$request = new WP_REST_Request( 'POST', '/prc-markdown-for-agents/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'category_ids' => array( $second, $first, $child, $second, 0, -1 ),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( array( $second, $first ), $data['settings']['category_ids'] );
	}

	public function test_categories_available_decodes_html_entities_in_names(): void {
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => Settings::CATEGORIES_TAXONOMY,
				'name'     => 'Economy & Work',
				'parent'   => 0,
			)
		);

		$request  = new WP_REST_Request( 'GET', '/prc-markdown-for-agents/v1/settings' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$data       = $response->get_data();
		$categories = array_values(
			array_filter(
				$data['categories_available'],
				static fn( array $category ): bool => (int) $category['id'] === (int) $term_id
			)
		);

		$this->assertCount( 1, $categories );
		$this->assertSame( 'Economy & Work', $categories[0]['name'] );
		$this->assertStringNotContainsString( '&amp;', $categories[0]['name'] );
	}

	public function test_get_category_ids_for_llms_txt_returns_null_when_empty(): void {
		update_option(
			Settings::OPTION_KEY,
			array(
				'category_ids' => array(),
			)
		);

		$this->assertNull( Settings::get_category_ids_for_llms_txt() );
	}

	public function test_get_category_ids_for_llms_txt_returns_curated_ids(): void {
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => Settings::CATEGORIES_TAXONOMY,
				'name'     => 'Curated Category',
				'parent'   => 0,
			)
		);

		update_option(
			Settings::OPTION_KEY,
			array(
				'category_ids' => array( $term_id ),
			)
		);

		$this->assertSame( array( $term_id ), Settings::get_category_ids_for_llms_txt() );
	}

	public function test_get_settings_requires_manage_options(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'GET', '/prc-markdown-for-agents/v1/settings' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}
}
